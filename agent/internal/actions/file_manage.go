package actions

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"strconv"
)

// --- File Create Action ---

type FileCreatePayload struct {
	Username string `json:"username"`
	Path     string `json:"path"`
	IsDir    bool   `json:"is_dir,omitempty"`
}

type FileCreateAction struct{}

func NewFileCreateAction() *FileCreateAction { return &FileCreateAction{} }

func (a *FileCreateAction) Name() string { return "file.create" }

func (a *FileCreateAction) Validate(payload []byte) error {
	var p FileCreatePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	_, err := ResolveSafePath(p.Username, p.Path)
	return err
}

func (a *FileCreateAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FileCreatePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	safePath, err := ResolveSafePath(p.Username, p.Path)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("creating item: %s (is_dir: %v)", safePath, p.IsDir)}

	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"path":    safePath,
				"is_dir":  p.IsDir,
				"created": true,
				"dry_run": true,
			},
			Logs: logs,
		}, nil
	}

	if p.IsDir {
		if err := os.MkdirAll(safePath, 0755); err != nil {
			return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
		}
	} else {
		f, err := os.OpenFile(safePath, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0644)
		if err != nil {
			return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
		}
		f.Close()
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"path":    safePath,
			"is_dir":  p.IsDir,
			"created": true,
		},
		Logs: logs,
	}, nil
}

// --- File Delete Action ---

type FileDeletePayload struct {
	Username string `json:"username"`
	Path     string `json:"path"`
}

type FileDeleteAction struct{}

func NewFileDeleteAction() *FileDeleteAction { return &FileDeleteAction{} }

func (a *FileDeleteAction) Name() string { return "file.delete" }

func (a *FileDeleteAction) Validate(payload []byte) error {
	var p FileDeletePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	_, err := ResolveSafePath(p.Username, p.Path)
	return err
}

func (a *FileDeleteAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FileDeletePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	safePath, err := ResolveSafePath(p.Username, p.Path)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	// Strictly protect account root from deletion
	homeDir := fmt.Sprintf("/home/%s", p.Username)
	if safePath == homeDir {
		return &ActionResult{
			Success: false,
			Error:   "cannot delete account home directory",
		}, errors.New("cannot delete account home directory")
	}

	logs := []string{fmt.Sprintf("deleting file or directory: %s", safePath)}

	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"path":    safePath,
				"deleted": true,
				"dry_run": true,
			},
			Logs: logs,
		}, nil
	}

	if err := os.RemoveAll(safePath); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"path":    safePath,
			"deleted": true,
		},
		Logs: logs,
	}, nil
}

// --- File Rename Action ---

type FileRenamePayload struct {
	Username   string `json:"username"`
	SourcePath string `json:"source_path"`
	TargetPath string `json:"target_path"`
}

type FileRenameAction struct{}

func NewFileRenameAction() *FileRenameAction { return &FileRenameAction{} }

func (a *FileRenameAction) Name() string { return "file.rename" }

func (a *FileRenameAction) Validate(payload []byte) error {
	var p FileRenamePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if _, err := ResolveSafePath(p.Username, p.SourcePath); err != nil {
		return fmt.Errorf("invalid source_path: %w", err)
	}
	if _, err := ResolveSafePath(p.Username, p.TargetPath); err != nil {
		return fmt.Errorf("invalid target_path: %w", err)
	}
	return nil
}

func (a *FileRenameAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FileRenamePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	sourceSafe, err := ResolveSafePath(p.Username, p.SourcePath)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	targetSafe, err := ResolveSafePath(p.Username, p.TargetPath)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("renaming '%s' to '%s'", sourceSafe, targetSafe)}

	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"source":  sourceSafe,
				"target":  targetSafe,
				"renamed": true,
				"dry_run": true,
			},
			Logs: logs,
		}, nil
	}

	if err := os.Rename(sourceSafe, targetSafe); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"source":  sourceSafe,
			"target":  targetSafe,
			"renamed": true,
		},
		Logs: logs,
	}, nil
}

// --- File Chmod Action ---

type FileChmodPayload struct {
	Username string `json:"username"`
	Path     string `json:"path"`
	Mode     string `json:"mode"`
}

type FileChmodAction struct{}

func NewFileChmodAction() *FileChmodAction { return &FileChmodAction{} }

func (a *FileChmodAction) Name() string { return "file.chmod" }

func (a *FileChmodAction) Validate(payload []byte) error {
	var p FileChmodPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if _, err := ResolveSafePath(p.Username, p.Path); err != nil {
		return err
	}
	modeInt, err := strconv.ParseUint(p.Mode, 8, 32)
	if err != nil || modeInt > 0777 {
		return fmt.Errorf("invalid octal permission mode '%s'", p.Mode)
	}
	return nil
}

func (a *FileChmodAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FileChmodPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	safePath, err := ResolveSafePath(p.Username, p.Path)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	modeInt, _ := strconv.ParseUint(p.Mode, 8, 32)
	fileMode := os.FileMode(modeInt)

	logs := []string{fmt.Sprintf("changing permissions of '%s' to %04o", safePath, fileMode)}

	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"path":    safePath,
				"mode":    fmt.Sprintf("%04o", fileMode),
				"dry_run": true,
			},
			Logs: logs,
		}, nil
	}

	if err := os.Chmod(safePath, fileMode); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"path": safePath,
			"mode": fmt.Sprintf("%04o", fileMode),
		},
		Logs: logs,
	}, nil
}
