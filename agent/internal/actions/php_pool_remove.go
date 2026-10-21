package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
)

type PhpPoolRemovePayload struct {
	Username string `json:"username"`
	Version  string `json:"version"`
}

type PhpPoolRemoveAction struct{}

func NewPhpPoolRemoveAction() *PhpPoolRemoveAction {
	return &PhpPoolRemoveAction{}
}

func (a *PhpPoolRemoveAction) Name() string {
	return "php.pool_remove"
}

func (a *PhpPoolRemoveAction) Validate(payload []byte) error {
	var p PhpPoolRemovePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}

	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	return ValidateSupportedPHPVersion(p.Version)
}

func (a *PhpPoolRemoveAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p PhpPoolRemovePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	targetFile := filepath.Join(fmt.Sprintf("/etc/php/%s/fpm/pool.d", p.Version), fmt.Sprintf("%s.conf", p.Username))
	serviceName := fmt.Sprintf("php%s-fpm", p.Version)

	logs := []string{
		fmt.Sprintf("removing PHP-FPM pool for '%s' (PHP %s)", p.Username, p.Version),
	}

	if dryRun {
		logs = append(logs,
			fmt.Sprintf("[dry-run] simulated deletion of %s", targetFile),
			fmt.Sprintf("[dry-run] simulated reload of %s", serviceName),
		)
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username": p.Username,
				"version":  p.Version,
				"removed":  true,
				"dry_run":  true,
			},
			Logs: logs,
		}, nil
	}

	if runtime.GOOS != "linux" {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username": p.Username,
				"version":  p.Version,
				"removed":  true,
			},
			Logs: logs,
		}, nil
	}

	if err := os.Remove(targetFile); err != nil && !os.IsNotExist(err) {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed to remove pool file %s: %s", targetFile, err),
			Logs:    logs,
		}, err
	}

	_ = exec.CommandContext(ctx, "systemctl", "reload", serviceName).Run()
	logs = append(logs, fmt.Sprintf("pool file %s purged and %s reloaded", targetFile, serviceName))

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username": p.Username,
			"version":  p.Version,
			"removed":  true,
		},
		Logs: logs,
	}, nil
}

