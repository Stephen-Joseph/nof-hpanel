package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"time"
)

type FileListPayload struct {
	Username string `json:"username"`
	Path     string `json:"path,omitempty"`
}

type FileItem struct {
	Name       string    `json:"name"`
	Path       string    `json:"path"`
	IsDir      bool      `json:"is_dir"`
	Size       int64     `json:"size"`
	Mode       string    `json:"mode"`
	ModTime    time.Time `json:"mod_time"`
	Extension  string    `json:"extension,omitempty"`
	IsEditable bool      `json:"is_editable"`
}

type FileListAction struct{}

func NewFileListAction() *FileListAction {
	return &FileListAction{}
}

func (a *FileListAction) Name() string {
	return "file.list"
}

func (a *FileListAction) Validate(payload []byte) error {
	var p FileListPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if p.Path != "" {
		_, err := ResolveSafePath(p.Username, p.Path)
		return err
	}
	return nil
}

func (a *FileListAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FileListPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	targetPath := fmt.Sprintf("/home/%s", p.Username)
	if p.Path != "" {
		safe, err := ResolveSafePath(p.Username, p.Path)
		if err != nil {
			return &ActionResult{Success: false, Error: err.Error()}, err
		}
		targetPath = safe
	}

	homeDir := fmt.Sprintf("/home/%s", p.Username)
	relPath, _ := filepath.Rel(homeDir, targetPath)
	if relPath == "." {
		relPath = ""
	}

	logs := []string{fmt.Sprintf("listing directory contents: %s", targetPath)}

	if dryRun {
		now := time.Now()
		mockFiles := []FileItem{
			{Name: "public_html", Path: filepath.Join(targetPath, "public_html"), IsDir: true, Mode: "0755", ModTime: now},
			{Name: "logs", Path: filepath.Join(targetPath, "logs"), IsDir: true, Mode: "0750", ModTime: now},
			{Name: "tmp", Path: filepath.Join(targetPath, "tmp"), IsDir: true, Mode: "0700", ModTime: now},
			{Name: "index.php", Path: filepath.Join(targetPath, "index.php"), IsDir: false, Size: 1024, Mode: "0644", ModTime: now, Extension: "php", IsEditable: true},
			{Name: ".htaccess", Path: filepath.Join(targetPath, ".htaccess"), IsDir: false, Size: 256, Mode: "0644", ModTime: now, IsEditable: true},
		}

		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"current_path": targetPath,
				"rel_path":     relPath,
				"parent_path":  filepath.Dir(targetPath),
				"entries":      mockFiles,
				"total":        len(mockFiles),
				"dry_run":      true,
			},
			Logs: logs,
		}, nil
	}

	entries, err := os.ReadDir(targetPath)
	if err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed reading directory '%s': %s", targetPath, err),
			Logs:    logs,
		}, err
	}

	var items []FileItem
	for _, entry := range entries {
		info, err := entry.Info()
		if err != nil {
			continue
		}

		ext := strings.TrimPrefix(filepath.Ext(entry.Name()), ".")
		isEditable := !entry.IsDir() && isTextExtension(ext) && info.Size() <= 5*1024*1024

		items = append(items, FileItem{
			Name:       entry.Name(),
			Path:       filepath.Join(targetPath, entry.Name()),
			IsDir:      entry.IsDir(),
			Size:       info.Size(),
			Mode:       fmt.Sprintf("%04o", info.Mode().Perm()),
			ModTime:    info.ModTime(),
			Extension:  ext,
			IsEditable: isEditable,
		})
	}

	// Sort directories first, then alphabetical
	sort.Slice(items, func(i, j int) bool {
		if items[i].IsDir != items[j].IsDir {
			return items[i].IsDir
		}
		return items[i].Name < items[j].Name
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
			"entries":      items,
			"total":        len(items),
		},
		Logs: logs,
	}, nil
}

func isTextExtension(ext string) bool {
	switch ext {
	case "php", "html", "htm", "css", "js", "json", "txt", "md", "sql", "xml", "yaml", "yml", "ini", "env", "conf", "sh", "htaccess":
		return true
	default:
		return false
	}
}

