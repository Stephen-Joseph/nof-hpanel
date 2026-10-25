package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"runtime"
	"strings"
)

type PhpFpmStatusPayload struct {
	Username string `json:"username"`
	Version  string `json:"version"`
}

type PhpFpmStatusAction struct{}

func NewPhpFpmStatusAction() *PhpFpmStatusAction {
	return &PhpFpmStatusAction{}
}

func (a *PhpFpmStatusAction) Name() string {
	return "php.fpm_status"
}

func (a *PhpFpmStatusAction) Validate(payload []byte) error {
	var p PhpFpmStatusPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	return ValidateSupportedPHPVersion(p.Version)
}

func (a *PhpFpmStatusAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p PhpFpmStatusPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	socketPath := fmt.Sprintf("/run/php/php%s-fpm-%s.sock", p.Version, p.Username)
	logs := []string{
		fmt.Sprintf("checking PHP-FPM status for '%s' (PHP %s, socket: %s)", p.Username, p.Version, socketPath),
	}

	if dryRun || runtime.GOOS != "linux" {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username":             p.Username,
				"version":              p.Version,
				"socket":               socketPath,
				"socket_exists":        true,
				"active_workers":       1,
				"idle_workers":         2,
				"total_workers":        3,
				"max_children_reached": 0,
				"dry_run":              dryRun,
			},
			Logs: logs,
		}, nil
	}

	_, statErr := os.Stat(socketPath)
	socketExists := (statErr == nil)

	// Count processes executing as the account user for php-fpm
	cmd := exec.CommandContext(ctx, "pgrep", "-u", p.Username, "-f", fmt.Sprintf("php-fpm: pool %s", p.Username))
	out, _ := cmd.Output()
	lines := strings.Split(strings.TrimSpace(string(out)), "\n")
	workerCount := 0
	if len(lines) > 0 && lines[0] != "" {
		workerCount = len(lines)
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username":             p.Username,
			"version":              p.Version,
			"socket":               socketPath,
			"socket_exists":        socketExists,
			"active_workers":       workerCount,
			"idle_workers":         0,
			"total_workers":        workerCount,
			"max_children_reached": 0,
		},
		Logs: logs,
	}, nil
}
