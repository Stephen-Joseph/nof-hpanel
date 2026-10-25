package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
)

type FileWritePayload struct {
	Username string `json:"username"`
	Path     string `json:"path"`
	Content  string `json:"content"`
}

type FileWriteAction struct{}

func NewFileWriteAction() *FileWriteAction {
	return &FileWriteAction{}
}

func (a *FileWriteAction) Name() string {
	return "file.write"
}

func (a *FileWriteAction) Validate(payload []byte) error {
	var p FileWritePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	_, err := ResolveSafePath(p.Username, p.Path)
	return err
}

func (a *FileWriteAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FileWritePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	safePath, err := ResolveSafePath(p.Username, p.Path)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("writing to file: %s (bytes: %d)", safePath, len(p.Content))}

	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"path":         safePath,
				"bytes_writen": len(p.Content),
				"dry_run":      true,
			},
			Logs: logs,
		}, nil
	}

	// Default permission: 0644
	mode := os.FileMode(0644)
	if info, err := os.Stat(safePath); err == nil {
		mode = info.Mode().Perm()
	}

	if err := os.WriteFile(safePath, []byte(p.Content), mode); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed writing file '%s': %s", safePath, err),
			Logs:    logs,
		}, err
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"path":         safePath,
			"bytes_writen": len(p.Content),
		},
		Logs: logs,
	}, nil
}
