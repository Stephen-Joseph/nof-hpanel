package actions

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
)

var (
	reHtpasswdFilename = regexp.MustCompile(`^[a-zA-Z0-9_\-]+\.htpasswd$`)
	reHtpasswdUser     = regexp.MustCompile(`^[a-zA-Z0-9_\-\.]{1,32}$`)
)

// HtpasswdEntry represents a single user and hashed password for an .htpasswd file.
type HtpasswdEntry struct {
	User string `json:"user"`
	Hash string `json:"hash"`
}

// -------------------------------------------------------------
// Action: htpasswd.write
// -------------------------------------------------------------

type HtpasswdWritePayload struct {
	Username string          `json:"username"`
	Filename string          `json:"filename"`
	Entries  []HtpasswdEntry `json:"entries"`
}

type HtpasswdWriteAction struct{}

func NewHtpasswdWriteAction() *HtpasswdWriteAction {
	return &HtpasswdWriteAction{}
}

func (a *HtpasswdWriteAction) Name() string {
	return "htpasswd.write"
}

func (a *HtpasswdWriteAction) Validate(payload []byte) error {
	var p HtpasswdWritePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}

	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}

	if !reHtpasswdFilename.MatchString(p.Filename) {
		return fmt.Errorf("invalid htpasswd filename '%s': must match ^[a-zA-Z0-9_-]+\\.htpasswd$", p.Filename)
	}

	if len(p.Entries) == 0 {
		return errors.New("at least one htpasswd user entry is required")
	}

	for _, entry := range p.Entries {
		if !reHtpasswdUser.MatchString(entry.User) {
			return fmt.Errorf("invalid htpasswd username '%s'", entry.User)
		}
		if strings.TrimSpace(entry.Hash) == "" || strings.ContainsAny(entry.Hash, "\r\n") {
			return fmt.Errorf("invalid htpasswd hash for user '%s'", entry.User)
		}
	}

	return nil
}

func (a *HtpasswdWriteAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p HtpasswdWritePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	htpasswdsDir := fmt.Sprintf("/home/%s/.htpasswds", p.Username)
	targetFilePath := filepath.Join(htpasswdsDir, p.Filename)

	logs := []string{fmt.Sprintf("writing htpasswd file '%s' for user '%s'", p.Filename, p.Username)}

	if dryRun || runtime.GOOS != "linux" {
		logs = append(logs,
			fmt.Sprintf("[dry-run] created directory %s (mode 0750)", htpasswdsDir),
			fmt.Sprintf("[dry-run] wrote %d user credentials to %s (mode 0640)", len(p.Entries), targetFilePath),
		)

		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"htpasswd_path": targetFilePath,
				"user_count":    len(p.Entries),
				"dry_run":       true,
			},
			Logs: logs,
		}, nil
	}

	// Step 1: Ensure directory exists with 0750 permissions
	if err := os.MkdirAll(htpasswdsDir, 0750); err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("failed to create directory: %v", err)}, err
	}

	// Step 2: Build file content: user:hash\n
	var buf bytes.Buffer
	for _, entry := range p.Entries {
		buf.WriteString(fmt.Sprintf("%s:%s\n", strings.TrimSpace(entry.User), strings.TrimSpace(entry.Hash)))
	}

	// Step 3: Write atomically via temporary file
	tmpFile := fmt.Sprintf("%s.tmp.%d", targetFilePath, os.Getpid())
	if err := os.WriteFile(tmpFile, buf.Bytes(), 0640); err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("failed to write temporary file: %v", err)}, err
	}

	if err := os.Rename(tmpFile, targetFilePath); err != nil {
		_ = os.Remove(tmpFile)
		return &ActionResult{Success: false, Error: fmt.Sprintf("failed to replace htpasswd file: %v", err)}, err
	}

	// Step 4: Ensure permissions and group ownership for www-data so Nginx can read it
	_ = exec.CommandContext(ctx, "chown", fmt.Sprintf("%s:www-data", p.Username), htpasswdsDir).Run()
	_ = exec.CommandContext(ctx, "chmod", "0750", htpasswdsDir).Run()
	_ = exec.CommandContext(ctx, "chown", fmt.Sprintf("%s:www-data", p.Username), targetFilePath).Run()
	_ = exec.CommandContext(ctx, "chmod", "0640", targetFilePath).Run()

	logs = append(logs, fmt.Sprintf("htpasswd written successfully to %s", targetFilePath))

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"htpasswd_path": targetFilePath,
			"user_count":    len(p.Entries),
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// Action: htpasswd.delete
// -------------------------------------------------------------

type HtpasswdDeletePayload struct {
	Username string `json:"username"`
	Filename string `json:"filename"`
}

type HtpasswdDeleteAction struct{}

func NewHtpasswdDeleteAction() *HtpasswdDeleteAction {
	return &HtpasswdDeleteAction{}
}

func (a *HtpasswdDeleteAction) Name() string {
	return "htpasswd.delete"
}

func (a *HtpasswdDeleteAction) Validate(payload []byte) error {
	var p HtpasswdDeletePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}

	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}

	if !reHtpasswdFilename.MatchString(p.Filename) {
		return fmt.Errorf("invalid htpasswd filename '%s'", p.Filename)
	}

	return nil
}

func (a *HtpasswdDeleteAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p HtpasswdDeletePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	targetFilePath := filepath.Join(fmt.Sprintf("/home/%s/.htpasswds", p.Username), p.Filename)

	logs := []string{fmt.Sprintf("deleting htpasswd file '%s'", targetFilePath)}

	if dryRun || runtime.GOOS != "linux" {
		logs = append(logs, fmt.Sprintf("[dry-run] removed %s", targetFilePath))
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"deleted": targetFilePath,
				"dry_run": true,
			},
			Logs: logs,
		}, nil
	}

	if err := os.Remove(targetFilePath); err != nil && !os.IsNotExist(err) {
		return &ActionResult{Success: false, Error: fmt.Sprintf("failed to delete file: %v", err)}, err
	}

	logs = append(logs, fmt.Sprintf("file %s deleted", targetFilePath))

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"deleted": targetFilePath,
		},
		Logs: logs,
	}, nil
}

