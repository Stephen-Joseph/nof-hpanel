package actions

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
)

var safeUploadIDRegex = regexp.MustCompile(`^[a-zA-Z0-9_\-]+$`)

type FileUploadPayload struct {
	Username      string `json:"username"`
	TargetPath    string `json:"target_path"`
	Filename      string `json:"filename"`
	ContentBase64 string `json:"content_base64"`
	ChunkIndex    int    `json:"chunk_index,omitempty"`
	TotalChunks   int    `json:"total_chunks,omitempty"`
	UploadID      string `json:"upload_id,omitempty"`
	MaxSize       int64  `json:"max_size,omitempty"`
}

type FileUploadAction struct{}

func NewFileUploadAction() *FileUploadAction {
	return &FileUploadAction{}
}

func (a *FileUploadAction) Name() string {
	return "file.upload"
}

func (a *FileUploadAction) Validate(payload []byte) error {
	var p FileUploadPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}

	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}

	if p.Filename == "" {
		return fmt.Errorf("filename cannot be empty")
	}

	if strings.ContainsAny(p.Filename, "/\\") || strings.Contains(p.Filename, "\x00") || strings.Contains(p.Filename, "..") {
		return fmt.Errorf("invalid characters in filename: %s", p.Filename)
	}

	cleanFilename := filepath.Base(filepath.Clean(p.Filename))
	if cleanFilename == "." || cleanFilename == ".." || cleanFilename != p.Filename {
		return fmt.Errorf("path traversal attempt in filename: %s", p.Filename)
	}

	if _, err := ResolveSafePath(p.Username, p.TargetPath); err != nil {
		return fmt.Errorf("invalid target_path '%s': %w", p.TargetPath, err)
	}

	destCandidate := filepath.Join(p.TargetPath, p.Filename)
	if _, err := ResolveSafePath(p.Username, destCandidate); err != nil {
		return fmt.Errorf("destination '%s' outside user home: %w", destCandidate, err)
	}

	if p.UploadID != "" && !safeUploadIDRegex.MatchString(p.UploadID) {
		return fmt.Errorf("invalid upload_id format")
	}

	if p.ChunkIndex < 0 {
		return fmt.Errorf("chunk_index cannot be negative")
	}
	if p.TotalChunks < 0 {
		return fmt.Errorf("total_chunks cannot be negative")
	}
	if p.TotalChunks > 0 && p.ChunkIndex >= p.TotalChunks {
		return fmt.Errorf("chunk_index %d exceeds total_chunks %d", p.ChunkIndex, p.TotalChunks)
	}

	// Validate base64 payload
	data, err := base64.StdEncoding.DecodeString(p.ContentBase64)
	if err != nil {
		return fmt.Errorf("invalid base64 content: %w", err)
	}

	maxSize := int64(100 * 1024 * 1024) // 100 MB default
	if p.MaxSize > 0 {
		maxSize = p.MaxSize
	}

	if int64(len(data)) > maxSize {
		return fmt.Errorf("file size (%d bytes) exceeds maximum allowed size (%d bytes)", len(data), maxSize)
	}

	return nil
}

func (a *FileUploadAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FileUploadPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	targetSafe, err := ResolveSafePath(p.Username, p.TargetPath)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	destSafe, err := ResolveSafePath(p.Username, filepath.Join(targetSafe, p.Filename))
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	// Reject if target path exists and is a symlink
	if fi, err := os.Lstat(destSafe); err == nil && (fi.Mode()&os.ModeSymlink != 0) {
		err := fmt.Errorf("target file '%s' is a symlink, refusing to overwrite", destSafe)
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	data, err := base64.StdEncoding.DecodeString(p.ContentBase64)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("decoding base64: %v", err)}, err
	}

	maxSize := int64(100 * 1024 * 1024)
	if p.MaxSize > 0 {
		maxSize = p.MaxSize
	}

	logs := []string{fmt.Sprintf("processing upload '%s' into %s", p.Filename, targetSafe)}

	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"filename":    p.Filename,
				"destination": destSafe,
				"size_bytes":  len(data),
				"dry_run":     true,
			},
			Logs: logs,
		}, nil
	}

	if err := os.MkdirAll(targetSafe, 0755); err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("creating target directory: %v", err), Logs: logs}, err
	}

	isMultiChunk := p.TotalChunks > 1
	uploadID := p.UploadID
	if uploadID == "" {
		uploadID = "default"
	}

	if !isMultiChunk {
		// Single chunk upload: Write atomically to temp file in target directory, fsync, close, chmod, rename
		tmpPath, err := CreateConfigTemp(destSafe, data, 0644)
		if err != nil {
			return &ActionResult{Success: false, Error: fmt.Sprintf("creating temp upload file: %v", err), Logs: logs}, err
		}

		if err := CommitConfigFile(tmpPath, destSafe, 0644); err != nil {
			return &ActionResult{Success: false, Error: fmt.Sprintf("committing uploaded file: %v", err), Logs: logs}, err
		}

		// Set owner to account user
		_ = exec.CommandContext(ctx, "chown", fmt.Sprintf("%s:%s", p.Username, p.Username), destSafe).Run()

		logs = append(logs, fmt.Sprintf("successfully uploaded %d bytes to %s", len(data), destSafe))
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"filename":    p.Filename,
				"destination": destSafe,
				"bytes":       len(data),
				"completed":   true,
			},
			Logs: logs,
		}, nil
	}

	// Chunked upload
	chunkTmpFile := filepath.Join(targetSafe, fmt.Sprintf(".%s.upload-%s.tmp", p.Filename, uploadID))
	flags := os.O_CREATE | os.O_WRONLY
	if p.ChunkIndex == 0 {
		flags |= os.O_TRUNC
	} else {
		flags |= os.O_APPEND
	}

	f, err := os.OpenFile(chunkTmpFile, flags, 0600)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("opening chunk file: %v", err), Logs: logs}, err
	}

	if _, err := f.Write(data); err != nil {
		_ = f.Close()
		_ = os.Remove(chunkTmpFile)
		return &ActionResult{Success: false, Error: fmt.Sprintf("writing chunk data: %v", err), Logs: logs}, err
	}

	if err := f.Sync(); err != nil {
		_ = f.Close()
		_ = os.Remove(chunkTmpFile)
		return &ActionResult{Success: false, Error: fmt.Sprintf("syncing chunk file: %v", err), Logs: logs}, err
	}
	_ = f.Close()

	// Check cumulative size
	if fi, err := os.Stat(chunkTmpFile); err == nil && fi.Size() > maxSize {
		_ = os.Remove(chunkTmpFile)
		err := fmt.Errorf("total uploaded size (%d bytes) exceeds maximum allowed size (%d bytes)", fi.Size(), maxSize)
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	// If this was the last chunk, finalize into destSafe
	if p.ChunkIndex == p.TotalChunks-1 {
		if err := CommitConfigFile(chunkTmpFile, destSafe, 0644); err != nil {
			_ = os.Remove(chunkTmpFile)
			return &ActionResult{Success: false, Error: fmt.Sprintf("finalizing chunked file: %v", err), Logs: logs}, err
		}

		_ = exec.CommandContext(ctx, "chown", fmt.Sprintf("%s:%s", p.Username, p.Username), destSafe).Run()

		var finalSize int64
		if fi, err := os.Stat(destSafe); err == nil {
			finalSize = fi.Size()
		}

		logs = append(logs, fmt.Sprintf("completed chunked upload (%d chunks, %d total bytes) to %s", p.TotalChunks, finalSize, destSafe))
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"filename":     p.Filename,
				"destination":  destSafe,
				"bytes":        finalSize,
				"total_chunks": p.TotalChunks,
				"completed":    true,
			},
			Logs: logs,
		}, nil
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"filename":     p.Filename,
			"chunk_index":  p.ChunkIndex,
			"total_chunks": p.TotalChunks,
			"completed":    false,
		},
		Logs: logs,
	}, nil
}
