package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

type FileReadPayload struct {
	Username string `json:"username"`
	Path     string `json:"path"`
}

type FileReadAction struct{}

func NewFileReadAction() *FileReadAction {
	return &FileReadAction{}
}

func (a *FileReadAction) Name() string {
	return "file.read"
}

func (a *FileReadAction) Validate(payload []byte) error {
	var p FileReadPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	_, err := ResolveSafePath(p.Username, p.Path)
	return err
}

func (a *FileReadAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FileReadPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	safePath, err := ResolveSafePath(p.Username, p.Path)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("reading file: %s", safePath)}

	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"path":      safePath,
				"content":   "<?php\n\necho 'Hello from HPanel!';\n",
				"size":      33,
				"is_binary": false,
				"dry_run":   true,
			},
			Logs: logs,
		}, nil
	}

	info, err := os.Stat(safePath)
	if err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("file not found: %s", err),
			Logs:    logs,
		}, err
	}

	if info.IsDir() {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("target '%s' is a directory, not a file", safePath),
			Logs:    logs,
		}, fmt.Errorf("cannot read directory")
	}

	// 5MB max read size cap for web editor
	if info.Size() > 5*1024*1024 {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("file size (%d bytes) exceeds 5MB web viewer limit", info.Size()),
			Logs:    logs,
		}, fmt.Errorf("file size exceeds 5MB")
	}

	bytes, err := os.ReadFile(safePath)
	if err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed to read file: %s", err),
			Logs:    logs,
		}, err
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"path":      safePath,
			"content":   string(bytes),
			"size":      info.Size(),
			"mode":      fmt.Sprintf("%04o", info.Mode().Perm()),
			"extension": strings.TrimPrefix(filepath.Ext(safePath), "."),
		},
		Logs: logs,
	}, nil
}
