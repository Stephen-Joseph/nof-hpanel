package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
)

type VhostRemovePayload struct {
	Username string `json:"username"`
	Domain   string `json:"domain"`
}

type VhostRemoveAction struct {
	executor          commandExecutor
	vhostAvailableDir string
	vhostEnabledDir   string
	isLinux           bool
}

func NewVhostRemoveAction() *VhostRemoveAction {
	return &VhostRemoveAction{
		executor:          osCommandExecutor{},
		vhostAvailableDir: "/etc/nginx/sites-available",
		vhostEnabledDir:   "/etc/nginx/sites-enabled",
		isLinux:           runtime.GOOS == "linux",
	}
}

func NewVhostRemoveActionForTest(executor commandExecutor, vhostAvailableDir, vhostEnabledDir string) *VhostRemoveAction {
	return &VhostRemoveAction{
		executor:          executor,
		vhostAvailableDir: vhostAvailableDir,
		vhostEnabledDir:   vhostEnabledDir,
		isLinux:           true,
	}
}

func (a *VhostRemoveAction) Name() string {
	return "vhost.remove"
}

func (a *VhostRemoveAction) Validate(payload []byte) error {
	var p VhostRemovePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	return ValidateDomain(p.Domain)
}

func (a *VhostRemoveAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p VhostRemovePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	availPath := filepath.Join(a.vhostAvailableDir, fmt.Sprintf("%s.conf", p.Domain))
	enabledPath := filepath.Join(a.vhostEnabledDir, fmt.Sprintf("%s.conf", p.Domain))

	logs := []string{fmt.Sprintf("removing vhost for domain '%s'", p.Domain)}

	if dryRun {
		logs = append(logs, fmt.Sprintf("[dry-run] simulated removal of %s and %s", enabledPath, availPath))
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"domain":  p.Domain,
				"removed": true,
				"dry_run": true,
			},
			Logs: logs,
		}, nil
	}

	if a.isLinux {
		_ = os.Remove(enabledPath)
		_ = os.Remove(availPath)

		if _, err := a.executor.LookPath("nginx"); err == nil {
			_ = a.executor.Run(ctx, "nginx", "-t")
		}
		if _, err := a.executor.LookPath("systemctl"); err == nil {
			_ = a.executor.Run(ctx, "systemctl", "reload", "nginx")
		}
		logs = append(logs, "removed Nginx vhost files and reloaded Nginx")
	} else {
		logs = append(logs, "simulated vhost removal on non-linux OS")
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"domain":  p.Domain,
			"removed": true,
			"dry_run": false,
		},
		Logs: logs,
	}, nil
}
