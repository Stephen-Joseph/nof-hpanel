package actions

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

var safeSessionIDRegex = regexp.MustCompile(`^[a-zA-Z0-9_\-]+$`)

type UploadSession struct {
	SessionID      string
	Username       string
	DestinationDir string
	Filename       string
	FinalPath      string
	TempPath       string
	TotalSize      int64
	BytesReceived  int64
	FileHandle     *os.File
	CreatedAt      time.Time
}

var (
	fsUploadSessionsMu sync.Mutex
	fsUploadSessions   = make(map[string]*UploadSession)
)

func chownToUser(targetPath, username string) error {
	u, err := user.Lookup(username)
	if err == nil {
		uid, errU := strconv.Atoi(u.Uid)
		gid, errG := strconv.Atoi(u.Gid)
		if errU == nil && errG == nil {
			_ = os.Chown(targetPath, uid, gid)
		}
	}
	cmd := exec.Command("chown", fmt.Sprintf("%s:%s", username, username), targetPath)
	_ = cmd.Run()
	return nil
}

func getFileOwner(info os.FileInfo, defaultUser string) string {
	if stat, ok := info.Sys().(*syscall.Stat_t); ok {
		u, err := user.LookupId(strconv.Itoa(int(stat.Uid)))
		if err == nil && u.Username != "" {
			return u.Username
		}
		return strconv.Itoa(int(stat.Uid))
	}
	return defaultUser
}

func validateFilenameStrict(filename string) error {
	if filename == "" {
		return errors.New("filename cannot be empty")
	}
	for _, c := range filename {
		if c < 32 || c == 127 || c == '/' || c == '\\' || c == 0 {
			return fmt.Errorf("filename contains illegal or control character: %q", c)
		}
	}
	clean := filepath.Clean(filename)
	if clean == "." || clean == ".." || strings.Contains(clean, "..") || filepath.Base(clean) != filename {
		return errors.New("path traversal sequence in filename")
	}
	return nil
}

// -------------------------------------------------------------------------
// fs.list
// -------------------------------------------------------------------------

type FsListPayload struct {
	Username string `json:"username"`
	Path     string `json:"path,omitempty"`
}

type FsEntry struct {
	Name       string    `json:"name"`
	Path       string    `json:"path"`
	Type       string    `json:"type"` // "file", "directory", "symlink"
	Size       int64     `json:"size"`
	Mode       string    `json:"mode"`
	ModTime    time.Time `json:"mod_time"`
	Owner      string    `json:"owner"`
	IsEditable bool      `json:"is_editable"`
	Extension  string    `json:"extension,omitempty"`
	Target     string    `json:"target,omitempty"`
}

type FsListAction struct {
	homeBaseDir string
}

func NewFsListAction() *FsListAction {
	return NewFsListActionWithBase("/home")
}

func NewFsListActionWithBase(baseHome string) *FsListAction {
	if baseHome == "" {
		baseHome = "/home"
	}
	return &FsListAction{homeBaseDir: filepath.Clean(baseHome)}
}

func (a *FsListAction) Name() string {
	return "fs.list"
}

func (a *FsListAction) Validate(payload []byte) error {
	var p FsListPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if p.Path != "" {
		_, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.Path)
		return err
	}
	return nil
}

func (a *FsListAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FsListPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	homeDir := filepath.Join(a.homeBaseDir, p.Username)
	targetPath := homeDir
	if p.Path != "" {
		safe, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.Path)
		if err != nil {
			return &ActionResult{Success: false, Error: err.Error()}, err
		}
		targetPath = safe
	}

	relPath, _ := filepath.Rel(homeDir, targetPath)
	if relPath == "." {
		relPath = ""
	}

	logs := []string{fmt.Sprintf("listing fs directory: %s", targetPath)}

	if dryRun {
		now := time.Now()
		mockEntries := []FsEntry{
			{Name: "public_html", Path: filepath.Join(targetPath, "public_html"), Type: "directory", Mode: "0755", ModTime: now, Owner: p.Username},
			{Name: "logs", Path: filepath.Join(targetPath, "logs"), Type: "directory", Mode: "0750", ModTime: now, Owner: p.Username},
			{Name: "index.html", Path: filepath.Join(targetPath, "index.html"), Type: "file", Size: 512, Mode: "0644", ModTime: now, Owner: p.Username, IsEditable: true, Extension: "html"},
		}
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"current_path": targetPath,
				"rel_path":     relPath,
				"parent_path":  filepath.Dir(targetPath),
				"entries":      mockEntries,
				"total":        len(mockEntries),
				"dry_run":      true,
			},
			Logs: logs,
		}, nil
	}

	// Verify targetPath is not an escaping symlink
	lfi, err := os.Lstat(targetPath)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("path not found: %s", err), Logs: logs}, err
	}
	if lfi.Mode()&os.ModeSymlink != 0 {
		realTgt, err := filepath.EvalSymlinks(targetPath)
		if err != nil || (!strings.HasPrefix(realTgt, homeDir+string(filepath.Separator)) && realTgt != homeDir) {
			return &ActionResult{Success: false, Error: "symlink escapes account home", Logs: logs}, fmt.Errorf("symlink target escapes home directory: %s", realTgt)
		}
	}

	dirEntries, err := os.ReadDir(targetPath)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("failed reading directory '%s': %s", targetPath, err), Logs: logs}, err
	}

	var entries []FsEntry
	for _, de := range dirEntries {
		entryPath := filepath.Join(targetPath, de.Name())
		info, err := os.Lstat(entryPath)
		if err != nil {
			continue
		}

		itemType := "file"
		targetLink := ""
		if info.Mode()&os.ModeSymlink != 0 {
			itemType = "symlink"
			if readTarget, err := os.Readlink(entryPath); err == nil {
				targetLink = readTarget
			}
		} else if info.IsDir() {
			itemType = "directory"
		}

		ext := strings.TrimPrefix(filepath.Ext(de.Name()), ".")
		isEditable := itemType == "file" && info.Size() <= 1024*1024 && isTextExtension(ext)
		owner := getFileOwner(info, p.Username)

		entries = append(entries, FsEntry{
			Name:       de.Name(),
			Path:       entryPath,
			Type:       itemType,
			Size:       info.Size(),
			Mode:       fmt.Sprintf("%04o", info.Mode().Perm()),
			ModTime:    info.ModTime(),
			Owner:      owner,
			IsEditable: isEditable,
			Extension:  ext,
			Target:     targetLink,
		})
	}

	// Sort directories first, then alphabetical
	sort.Slice(entries, func(i, j int) bool {
		if (entries[i].Type == "directory") != (entries[j].Type == "directory") {
			return entries[i].Type == "directory"
		}
		return entries[i].Name < entries[j].Name
	})

	var parentPath string
	if targetPath != homeDir {
		parentPath = filepath.Dir(targetPath)
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"current_path": targetPath,
			"rel_path":     relPath,
			"parent_path":  parentPath,
			"entries":      entries,
			"total":        len(entries),
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------------------
// fs.stat
// -------------------------------------------------------------------------

type FsStatPayload struct {
	Username string `json:"username"`
	Path     string `json:"path"`
}

type FsStatAction struct {
	homeBaseDir string
}

func NewFsStatAction() *FsStatAction {
	return NewFsStatActionWithBase("/home")
}

func NewFsStatActionWithBase(baseHome string) *FsStatAction {
	if baseHome == "" {
		baseHome = "/home"
	}
	return &FsStatAction{homeBaseDir: filepath.Clean(baseHome)}
}

func (a *FsStatAction) Name() string { return "fs.stat" }

func (a *FsStatAction) Validate(payload []byte) error {
	var p FsStatPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	_, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.Path)
	return err
}

func (a *FsStatAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FsStatPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	safePath, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.Path)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("stat path: %s", safePath)}
	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"name":    filepath.Base(safePath),
				"path":    safePath,
				"type":    "file",
				"size":    1024,
				"mode":    "0644",
				"dry_run": true,
			},
			Logs: logs,
		}, nil
	}

	info, err := os.Lstat(safePath)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("stat error: %s", err), Logs: logs}, err
	}

	itemType := "file"
	targetLink := ""
	if info.Mode()&os.ModeSymlink != 0 {
		itemType = "symlink"
		if readTarget, err := os.Readlink(safePath); err == nil {
			targetLink = readTarget
		}
	} else if info.IsDir() {
		itemType = "directory"
	}

	ext := strings.TrimPrefix(filepath.Ext(safePath), ".")
	owner := getFileOwner(info, p.Username)

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"name":        filepath.Base(safePath),
			"path":        safePath,
			"type":        itemType,
			"size":        info.Size(),
			"mode":        fmt.Sprintf("%04o", info.Mode().Perm()),
			"mod_time":    info.ModTime(),
			"owner":       owner,
			"extension":   ext,
			"target":      targetLink,
			"is_editable": itemType == "file" && info.Size() <= 1024*1024 && isTextExtension(ext),
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------------------
// fs.mkdir
// -------------------------------------------------------------------------

type FsMkdirPayload struct {
	Username string `json:"username"`
	Path     string `json:"path"`
}

type FsMkdirAction struct {
	homeBaseDir string
}

func NewFsMkdirAction() *FsMkdirAction {
	return NewFsMkdirActionWithBase("/home")
}

func NewFsMkdirActionWithBase(baseHome string) *FsMkdirAction {
	if baseHome == "" {
		baseHome = "/home"
	}
	return &FsMkdirAction{homeBaseDir: filepath.Clean(baseHome)}
}

func (a *FsMkdirAction) Name() string { return "fs.mkdir" }

func (a *FsMkdirAction) Validate(payload []byte) error {
	var p FsMkdirPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	_, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.Path)
	return err
}

func (a *FsMkdirAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FsMkdirPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	safePath, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.Path)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("creating directory: %s", safePath)}
	if dryRun {
		return &ActionResult{
			Success: true,
			Data:    map[string]any{"path": safePath, "created": true, "dry_run": true},
			Logs:    logs,
		}, nil
	}

	if err := os.MkdirAll(safePath, 0755); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}
	_ = os.Chmod(safePath, 0755)
	_ = chownToUser(safePath, p.Username)

	return &ActionResult{
		Success: true,
		Data:    map[string]any{"path": safePath, "created": true, "mode": "0755"},
		Logs:    logs,
	}, nil
}

// -------------------------------------------------------------------------
// fs.create_file
// -------------------------------------------------------------------------

type FsCreateFilePayload struct {
	Username string `json:"username"`
	Path     string `json:"path"`
}

type FsCreateFileAction struct {
	homeBaseDir string
}

func NewFsCreateFileAction() *FsCreateFileAction {
	return NewFsCreateFileActionWithBase("/home")
}

func NewFsCreateFileActionWithBase(baseHome string) *FsCreateFileAction {
	if baseHome == "" {
		baseHome = "/home"
	}
	return &FsCreateFileAction{homeBaseDir: filepath.Clean(baseHome)}
}

func (a *FsCreateFileAction) Name() string { return "fs.create_file" }

func (a *FsCreateFileAction) Validate(payload []byte) error {
	var p FsCreateFilePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	_, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.Path)
	return err
}

func (a *FsCreateFileAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FsCreateFilePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	safePath, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.Path)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("creating file: %s", safePath)}
	if dryRun {
		return &ActionResult{
			Success: true,
			Data:    map[string]any{"path": safePath, "created": true, "dry_run": true},
			Logs:    logs,
		}, nil
	}

	if err := os.MkdirAll(filepath.Dir(safePath), 0755); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	f, err := os.OpenFile(safePath, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0644)
	if err != nil {
		if os.IsExist(err) {
			return &ActionResult{Success: false, Error: "file already exists", Logs: logs}, err
		}
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}
	_ = f.Close()
	_ = os.Chmod(safePath, 0644)
	_ = chownToUser(safePath, p.Username)

	return &ActionResult{
		Success: true,
		Data:    map[string]any{"path": safePath, "created": true, "mode": "0644"},
		Logs:    logs,
	}, nil
}

// -------------------------------------------------------------------------
// fs.rename
// -------------------------------------------------------------------------

type FsRenamePayload struct {
	Username   string `json:"username"`
	SourcePath string `json:"source_path"`
	TargetPath string `json:"target_path"`
}

type FsRenameAction struct {
	homeBaseDir string
}

func NewFsRenameAction() *FsRenameAction {
	return NewFsRenameActionWithBase("/home")
}

func NewFsRenameActionWithBase(baseHome string) *FsRenameAction {
	if baseHome == "" {
		baseHome = "/home"
	}
	return &FsRenameAction{homeBaseDir: filepath.Clean(baseHome)}
}

func (a *FsRenameAction) Name() string { return "fs.rename" }

func (a *FsRenameAction) Validate(payload []byte) error {
	var p FsRenamePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if _, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.SourcePath); err != nil {
		return fmt.Errorf("invalid source_path: %w", err)
	}
	if _, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.TargetPath); err != nil {
		return fmt.Errorf("invalid target_path: %w", err)
	}
	return nil
}

func (a *FsRenameAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FsRenamePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	srcSafe, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.SourcePath)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	tgtSafe, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.TargetPath)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	homeDir := filepath.Join(a.homeBaseDir, p.Username)
	if srcSafe == homeDir {
		return &ActionResult{Success: false, Error: "cannot rename account home root directory"}, errors.New("cannot rename account home root directory")
	}

	logs := []string{fmt.Sprintf("renaming '%s' to '%s'", srcSafe, tgtSafe)}
	if dryRun {
		return &ActionResult{
			Success: true,
			Data:    map[string]any{"source": srcSafe, "target": tgtSafe, "renamed": true, "dry_run": true},
			Logs:    logs,
		}, nil
	}

	if err := os.Rename(srcSafe, tgtSafe); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	return &ActionResult{
		Success: true,
		Data:    map[string]any{"source": srcSafe, "target": tgtSafe, "renamed": true},
		Logs:    logs,
	}, nil
}

// -------------------------------------------------------------------------
// fs.move
// -------------------------------------------------------------------------

type FsMovePayload struct {
	Username   string `json:"username"`
	SourcePath string `json:"source_path"`
	TargetPath string `json:"target_path"`
}

type FsMoveAction struct {
	homeBaseDir string
}

func NewFsMoveAction() *FsMoveAction {
	return NewFsMoveActionWithBase("/home")
}

func NewFsMoveActionWithBase(baseHome string) *FsMoveAction {
	if baseHome == "" {
		baseHome = "/home"
	}
	return &FsMoveAction{homeBaseDir: filepath.Clean(baseHome)}
}

func (a *FsMoveAction) Name() string { return "fs.move" }

func (a *FsMoveAction) Validate(payload []byte) error {
	var p FsMovePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if _, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.SourcePath); err != nil {
		return fmt.Errorf("invalid source_path: %w", err)
	}
	if _, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.TargetPath); err != nil {
		return fmt.Errorf("invalid target_path: %w", err)
	}
	return nil
}

func (a *FsMoveAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FsMovePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	srcSafe, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.SourcePath)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	tgtSafe, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.TargetPath)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	homeDir := filepath.Join(a.homeBaseDir, p.Username)
	if srcSafe == homeDir {
		return &ActionResult{Success: false, Error: "cannot move account home root directory"}, errors.New("cannot move account home root directory")
	}

	finalDest := tgtSafe
	if fi, err := os.Stat(tgtSafe); err == nil && fi.IsDir() {
		finalDest = filepath.Join(tgtSafe, filepath.Base(srcSafe))
	}

	logs := []string{fmt.Sprintf("moving '%s' to '%s'", srcSafe, finalDest)}
	if dryRun {
		return &ActionResult{
			Success: true,
			Data:    map[string]any{"source": srcSafe, "destination": finalDest, "moved": true, "dry_run": true},
			Logs:    logs,
		}, nil
	}

	if err := os.Rename(srcSafe, finalDest); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	return &ActionResult{
		Success: true,
		Data:    map[string]any{"source": srcSafe, "destination": finalDest, "moved": true},
		Logs:    logs,
	}, nil
}

// -------------------------------------------------------------------------
// fs.copy
// -------------------------------------------------------------------------

type FsCopyPayload struct {
	Username   string `json:"username"`
	SourcePath string `json:"source_path"`
	TargetPath string `json:"target_path"`
}

type FsCopyAction struct {
	homeBaseDir string
}

func NewFsCopyAction() *FsCopyAction {
	return NewFsCopyActionWithBase("/home")
}

func NewFsCopyActionWithBase(baseHome string) *FsCopyAction {
	if baseHome == "" {
		baseHome = "/home"
	}
	return &FsCopyAction{homeBaseDir: filepath.Clean(baseHome)}
}

func (a *FsCopyAction) Name() string { return "fs.copy" }

func (a *FsCopyAction) Validate(payload []byte) error {
	var p FsCopyPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if _, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.SourcePath); err != nil {
		return fmt.Errorf("invalid source_path: %w", err)
	}
	if _, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.TargetPath); err != nil {
		return fmt.Errorf("invalid target_path: %w", err)
	}
	return nil
}

func (a *FsCopyAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FsCopyPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	srcSafe, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.SourcePath)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	tgtSafe, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.TargetPath)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	homeDir := filepath.Join(a.homeBaseDir, p.Username)
	finalDest := tgtSafe
	if fi, err := os.Stat(tgtSafe); err == nil && fi.IsDir() {
		finalDest = filepath.Join(tgtSafe, filepath.Base(srcSafe))
	}

	logs := []string{fmt.Sprintf("copying '%s' to '%s'", srcSafe, finalDest)}
	if dryRun {
		return &ActionResult{
			Success: true,
			Data:    map[string]any{"source": srcSafe, "destination": finalDest, "copied": true, "dry_run": true},
			Logs:    logs,
		}, nil
	}

	maxBytes := int64(100 * 1024 * 1024) // 100 MB limit
	if err := copyRecursive(srcSafe, finalDest, maxBytes, p.Username, homeDir); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	return &ActionResult{
		Success: true,
		Data:    map[string]any{"source": srcSafe, "destination": finalDest, "copied": true},
		Logs:    logs,
	}, nil
}

func copyRecursive(src, dst string, maxBytes int64, username, homeDir string) error {
	srcInfo, err := os.Lstat(src)
	if err != nil {
		return err
	}

	if srcInfo.Mode()&os.ModeSymlink != 0 {
		target, err := os.Readlink(src)
		if err != nil {
			return err
		}
		resolved := target
		if !filepath.IsAbs(target) {
			resolved = filepath.Join(filepath.Dir(src), target)
		}
		realTarget, err := filepath.EvalSymlinks(resolved)
		if err != nil || (!strings.HasPrefix(realTarget, homeDir+string(filepath.Separator)) && realTarget != homeDir) {
			return fmt.Errorf("symlink target '%s' escapes home directory '%s'", target, homeDir)
		}
		_ = os.Remove(dst)
		return os.Symlink(target, dst)
	}

	if !srcInfo.IsDir() {
		if srcInfo.Size() > maxBytes {
			return fmt.Errorf("file size (%d bytes) exceeds copy limit (%d bytes)", srcInfo.Size(), maxBytes)
		}
		return copySingleFile(src, dst, 0644, username)
	}

	if err := os.MkdirAll(dst, 0755); err != nil {
		return err
	}
	_ = chownToUser(dst, username)

	var totalCopied int64 = 0

	err = filepath.Walk(src, func(path string, info os.FileInfo, err error) error {
		if err != nil {
			return err
		}
		rel, err := filepath.Rel(src, path)
		if err != nil || rel == "." {
			return nil
		}
		targetPath := filepath.Join(dst, rel)

		if info.Mode()&os.ModeSymlink != 0 {
			target, err := os.Readlink(path)
			if err != nil {
				return err
			}
			resolved := target
			if !filepath.IsAbs(target) {
				resolved = filepath.Join(filepath.Dir(path), target)
			}
			realTarget, err := filepath.EvalSymlinks(resolved)
			if err != nil || (!strings.HasPrefix(realTarget, homeDir+string(filepath.Separator)) && realTarget != homeDir) {
				return fmt.Errorf("symlink target '%s' escapes home directory '%s'", target, homeDir)
			}
			_ = os.Remove(targetPath)
			return os.Symlink(target, targetPath)
		}

		if info.IsDir() {
			if err := os.MkdirAll(targetPath, 0755); err != nil {
				return err
			}
			_ = chownToUser(targetPath, username)
			return nil
		}

		totalCopied += info.Size()
		if totalCopied > maxBytes {
			return fmt.Errorf("copy exceeds maximum limit of %d bytes", maxBytes)
		}

		return copySingleFile(path, targetPath, 0644, username)
	})

	if err != nil {
		_ = os.RemoveAll(dst)
		return err
	}
	return nil
}

func copySingleFile(src, dst string, perm os.FileMode, username string) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()

	if err := os.MkdirAll(filepath.Dir(dst), 0755); err != nil {
		return err
	}

	out, err := os.OpenFile(dst, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, perm)
	if err != nil {
		return err
	}
	defer out.Close()

	if _, err := io.Copy(out, in); err != nil {
		return err
	}
	_ = out.Sync()
	_ = os.Chmod(dst, perm)
	_ = chownToUser(dst, username)
	return nil
}

// -------------------------------------------------------------------------
// fs.delete
// -------------------------------------------------------------------------

type FsDeletePayload struct {
	Username string `json:"username"`
	Path     string `json:"path"`
}

type FsDeleteAction struct {
	homeBaseDir string
}

func NewFsDeleteAction() *FsDeleteAction {
	return NewFsDeleteActionWithBase("/home")
}

func NewFsDeleteActionWithBase(baseHome string) *FsDeleteAction {
	if baseHome == "" {
		baseHome = "/home"
	}
	return &FsDeleteAction{homeBaseDir: filepath.Clean(baseHome)}
}

func (a *FsDeleteAction) Name() string { return "fs.delete" }

func (a *FsDeleteAction) Validate(payload []byte) error {
	var p FsDeletePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	_, err := ResolveSafePathNoFollowWithBase(a.homeBaseDir, p.Username, p.Path)
	return err
}

func (a *FsDeleteAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FsDeletePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	safePath, err := ResolveSafePathNoFollowWithBase(a.homeBaseDir, p.Username, p.Path)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	homeDir := filepath.Join(a.homeBaseDir, p.Username)
	if safePath == homeDir {
		return &ActionResult{Success: false, Error: "cannot delete account home root directory"}, errors.New("cannot delete account home root directory")
	}

	logs := []string{fmt.Sprintf("deleting fs path: %s", safePath)}
	if dryRun {
		return &ActionResult{
			Success: true,
			Data:    map[string]any{"path": safePath, "deleted": true, "dry_run": true},
			Logs:    logs,
		}, nil
	}

	fi, err := os.Lstat(safePath)
	if err != nil {
		if os.IsNotExist(err) {
			return &ActionResult{Success: true, Data: map[string]any{"path": safePath, "deleted": true}, Logs: logs}, nil
		}
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	if fi.Mode()&os.ModeSymlink != 0 {
		if err := os.Remove(safePath); err != nil {
			return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
		}
	} else {
		if err := os.RemoveAll(safePath); err != nil {
			return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
		}
	}

	return &ActionResult{
		Success: true,
		Data:    map[string]any{"path": safePath, "deleted": true},
		Logs:    logs,
	}, nil
}

// -------------------------------------------------------------------------
// fs.read_text
// -------------------------------------------------------------------------

type FsReadTextPayload struct {
	Username string `json:"username"`
	Path     string `json:"path"`
}

type FsReadTextAction struct {
	homeBaseDir string
}

func NewFsReadTextAction() *FsReadTextAction {
	return NewFsReadTextActionWithBase("/home")
}

func NewFsReadTextActionWithBase(baseHome string) *FsReadTextAction {
	if baseHome == "" {
		baseHome = "/home"
	}
	return &FsReadTextAction{homeBaseDir: filepath.Clean(baseHome)}
}

func (a *FsReadTextAction) Name() string { return "fs.read_text" }

func (a *FsReadTextAction) Validate(payload []byte) error {
	var p FsReadTextPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	_, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.Path)
	return err
}

func (a *FsReadTextAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FsReadTextPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	safePath, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.Path)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("reading text file: %s", safePath)}
	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"path":      safePath,
				"content":   "Sample text content\n",
				"size":      20,
				"is_binary": false,
				"dry_run":   true,
			},
			Logs: logs,
		}, nil
	}

	info, err := os.Stat(safePath)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("file not found: %s", err), Logs: logs}, err
	}
	if info.IsDir() {
		return &ActionResult{Success: false, Error: "target is a directory, not a file", Logs: logs}, errors.New("target is a directory, not a file")
	}

	// 1 MB limit
	if info.Size() > 1024*1024 {
		return &ActionResult{Success: false, Error: fmt.Sprintf("file size (%d bytes) exceeds 1MB limit", info.Size()), Logs: logs}, fmt.Errorf("file size (%d bytes) exceeds 1MB limit", info.Size())
	}

	content, err := os.ReadFile(safePath)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	// Check NUL byte for binary detection
	if bytes.IndexByte(content, 0) != -1 {
		return &ActionResult{Success: false, Error: "binary file detected: NUL byte found", Logs: logs}, errors.New("binary file detected: NUL byte found")
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"path":      safePath,
			"content":   string(content),
			"size":      info.Size(),
			"mode":      fmt.Sprintf("%04o", info.Mode().Perm()),
			"extension": strings.TrimPrefix(filepath.Ext(safePath), "."),
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------------------
// fs.write_text
// -------------------------------------------------------------------------

type FsWriteTextPayload struct {
	Username string `json:"username"`
	Path     string `json:"path"`
	Content  string `json:"content"`
}

type FsWriteTextAction struct {
	homeBaseDir string
}

func NewFsWriteTextAction() *FsWriteTextAction {
	return NewFsWriteTextActionWithBase("/home")
}

func NewFsWriteTextActionWithBase(baseHome string) *FsWriteTextAction {
	if baseHome == "" {
		baseHome = "/home"
	}
	return &FsWriteTextAction{homeBaseDir: filepath.Clean(baseHome)}
}

func (a *FsWriteTextAction) Name() string { return "fs.write_text" }

func (a *FsWriteTextAction) Validate(payload []byte) error {
	var p FsWriteTextPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	_, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.Path)
	return err
}

func (a *FsWriteTextAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FsWriteTextPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	safePath, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.Path)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if len(p.Content) > 1024*1024 {
		return &ActionResult{Success: false, Error: "content exceeds 1MB limit"}, errors.New("content exceeds 1MB limit")
	}

	logs := []string{fmt.Sprintf("writing text file atomically: %s (%d bytes)", safePath, len(p.Content))}
	if dryRun {
		return &ActionResult{
			Success: true,
			Data:    map[string]any{"path": safePath, "bytes_written": len(p.Content), "dry_run": true},
			Logs:    logs,
		}, nil
	}

	if err := os.MkdirAll(filepath.Dir(safePath), 0755); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	if err := atomicWriteSafe(safePath, []byte(p.Content), 0644, p.Username); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	return &ActionResult{
		Success: true,
		Data:    map[string]any{"path": safePath, "bytes_written": len(p.Content), "mode": "0644"},
		Logs:    logs,
	}, nil
}

func atomicWriteSafe(dest string, content []byte, mode os.FileMode, username string) error {
	dir := filepath.Dir(dest)
	tmpFile, err := os.CreateTemp(dir, ".tmp-*")
	if err != nil {
		return fmt.Errorf("creating temp file in %s: %w", dir, err)
	}
	tmpName := tmpFile.Name()
	defer func() {
		_ = os.Remove(tmpName)
	}()

	if _, err := tmpFile.Write(content); err != nil {
		_ = tmpFile.Close()
		return fmt.Errorf("writing content to %s: %w", tmpName, err)
	}
	if err := tmpFile.Sync(); err != nil {
		_ = tmpFile.Close()
		return fmt.Errorf("syncing %s: %w", tmpName, err)
	}
	if err := tmpFile.Close(); err != nil {
		return fmt.Errorf("closing %s: %w", tmpName, err)
	}

	if err := os.Chmod(tmpName, mode); err != nil {
		return fmt.Errorf("chmod %s: %w", tmpName, err)
	}
	_ = chownToUser(tmpName, username)

	if err := os.Rename(tmpName, dest); err != nil {
		return fmt.Errorf("renaming %s to %s: %w", tmpName, dest, err)
	}
	_ = chownToUser(dest, username)
	return nil
}

// -------------------------------------------------------------------------
// fs.upload_begin
// -------------------------------------------------------------------------

type FsUploadBeginPayload struct {
	Username       string `json:"username"`
	DestinationDir string `json:"destination_dir"`
	Filename       string `json:"filename"`
	TotalSize      int64  `json:"total_size"`
	QuotaRemaining int64  `json:"quota_remaining,omitempty"`
}

type FsUploadBeginAction struct {
	homeBaseDir string
}

func NewFsUploadBeginAction() *FsUploadBeginAction {
	return NewFsUploadBeginActionWithBase("/home")
}

func NewFsUploadBeginActionWithBase(baseHome string) *FsUploadBeginAction {
	if baseHome == "" {
		baseHome = "/home"
	}
	return &FsUploadBeginAction{homeBaseDir: filepath.Clean(baseHome)}
}

func (a *FsUploadBeginAction) Name() string { return "fs.upload_begin" }

func (a *FsUploadBeginAction) Validate(payload []byte) error {
	var p FsUploadBeginPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if err := validateFilenameStrict(p.Filename); err != nil {
		return err
	}
	if _, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.DestinationDir); err != nil {
		return fmt.Errorf("invalid destination_dir: %w", err)
	}
	if p.TotalSize < 0 {
		return errors.New("total_size cannot be negative")
	}
	maxUpload := int64(100 * 1024 * 1024)
	if p.TotalSize > maxUpload {
		return fmt.Errorf("file size (%d bytes) exceeds maximum 100MB upload limit", p.TotalSize)
	}
	if p.QuotaRemaining > 0 && p.TotalSize > p.QuotaRemaining {
		return fmt.Errorf("upload exceeds remaining disk quota (%d bytes requested, %d bytes remaining)", p.TotalSize, p.QuotaRemaining)
	}
	return nil
}

func (a *FsUploadBeginAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FsUploadBeginPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	destSafe, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, p.DestinationDir)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	finalPath := filepath.Join(destSafe, p.Filename)
	finalSafe, err := ResolveSafePathWithBase(a.homeBaseDir, p.Username, finalPath)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("starting upload session for '%s' in %s", p.Filename, destSafe)}
	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"session_id":  "dry-run-session-id",
				"filename":    p.Filename,
				"destination": finalSafe,
				"dry_run":     true,
			},
			Logs: logs,
		}, nil
	}

	if err := os.MkdirAll(destSafe, 0755); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	sessionBytes := make([]byte, 16)
	if _, err := rand.Read(sessionBytes); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}
	sessionID := hex.EncodeToString(sessionBytes)

	tempPath := filepath.Join(destSafe, fmt.Sprintf(".sftp_upload_%s.tmp", sessionID))
	fh, err := os.OpenFile(tempPath, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0644)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}
	_ = chownToUser(tempPath, p.Username)

	fsUploadSessionsMu.Lock()
	fsUploadSessions[sessionID] = &UploadSession{
		SessionID:      sessionID,
		Username:       p.Username,
		DestinationDir: destSafe,
		Filename:       p.Filename,
		FinalPath:      finalSafe,
		TempPath:       tempPath,
		TotalSize:      p.TotalSize,
		BytesReceived:  0,
		FileHandle:     fh,
		CreatedAt:      time.Now(),
	}
	fsUploadSessionsMu.Unlock()

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"session_id":  sessionID,
			"filename":    p.Filename,
			"destination": finalSafe,
			"temp_path":   tempPath,
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------------------
// fs.upload_chunk
// -------------------------------------------------------------------------

type FsUploadChunkPayload struct {
	Username      string `json:"username"`
	SessionID     string `json:"session_id"`
	ChunkIndex    int    `json:"chunk_index"`
	ContentBase64 string `json:"content_base64"`
}

type FsUploadChunkAction struct{}

func NewFsUploadChunkAction() *FsUploadChunkAction { return &FsUploadChunkAction{} }

func (a *FsUploadChunkAction) Name() string { return "fs.upload_chunk" }

func (a *FsUploadChunkAction) Validate(payload []byte) error {
	var p FsUploadChunkPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if !safeSessionIDRegex.MatchString(p.SessionID) {
		return errors.New("invalid session_id format")
	}
	if p.ChunkIndex < 0 {
		return errors.New("chunk_index cannot be negative")
	}
	return nil
}

func (a *FsUploadChunkAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FsUploadChunkPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	data, err := base64.StdEncoding.DecodeString(p.ContentBase64)
	if err != nil {
		return &ActionResult{Success: false, Error: "invalid base64 content"}, err
	}

	if len(data) > 4*1024*1024 {
		return &ActionResult{Success: false, Error: "chunk size exceeds 4MB limit"}, errors.New("chunk size exceeds 4MB limit")
	}

	logs := []string{fmt.Sprintf("processing chunk %d for session %s (%d bytes)", p.ChunkIndex, p.SessionID, len(data))}
	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"session_id":    p.SessionID,
				"chunk_index":   p.ChunkIndex,
				"bytes_written": len(data),
				"dry_run":       true,
			},
			Logs: logs,
		}, nil
	}

	fsUploadSessionsMu.Lock()
	session, exists := fsUploadSessions[p.SessionID]
	fsUploadSessionsMu.Unlock()

	if !exists || session.Username != p.Username {
		return &ActionResult{Success: false, Error: "upload session not found or user mismatch", Logs: logs}, errors.New("upload session not found or user mismatch")
	}

	n, err := session.FileHandle.Write(data)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}
	session.BytesReceived += int64(n)

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"session_id":     p.SessionID,
			"chunk_index":    p.ChunkIndex,
			"bytes_written":  n,
			"total_received": session.BytesReceived,
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------------------
// fs.upload_commit
// -------------------------------------------------------------------------

type FsUploadCommitPayload struct {
	Username  string `json:"username"`
	SessionID string `json:"session_id"`
}

type FsUploadCommitAction struct{}

func NewFsUploadCommitAction() *FsUploadCommitAction { return &FsUploadCommitAction{} }

func (a *FsUploadCommitAction) Name() string { return "fs.upload_commit" }

func (a *FsUploadCommitAction) Validate(payload []byte) error {
	var p FsUploadCommitPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if !safeSessionIDRegex.MatchString(p.SessionID) {
		return errors.New("invalid session_id format")
	}
	return nil
}

func (a *FsUploadCommitAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FsUploadCommitPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("committing upload session: %s", p.SessionID)}
	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"session_id": p.SessionID,
				"committed":  true,
				"dry_run":    true,
			},
			Logs: logs,
		}, nil
	}

	fsUploadSessionsMu.Lock()
	session, exists := fsUploadSessions[p.SessionID]
	if exists {
		delete(fsUploadSessions, p.SessionID)
	}
	fsUploadSessionsMu.Unlock()

	if !exists || session.Username != p.Username {
		return &ActionResult{Success: false, Error: "upload session not found or user mismatch", Logs: logs}, errors.New("upload session not found or user mismatch")
	}

	_ = session.FileHandle.Sync()
	_ = session.FileHandle.Close()

	fi, err := os.Stat(session.TempPath)
	if err != nil {
		return &ActionResult{Success: false, Error: "temp upload file missing", Logs: logs}, err
	}

	if err := os.Rename(session.TempPath, session.FinalPath); err != nil {
		_ = os.Remove(session.TempPath)
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	_ = os.Chmod(session.FinalPath, 0644)
	_ = chownToUser(session.FinalPath, session.Username)

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"session_id": session.SessionID,
			"final_path": session.FinalPath,
			"filename":   session.Filename,
			"size":       fi.Size(),
			"mode":       "0644",
		},
		Logs: logs,
	}, nil
}
