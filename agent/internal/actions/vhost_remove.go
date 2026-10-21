package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"runtime"
)

type VhostRemovePayload struct {
	Username string `json:"username"`
	Domain   string `json:"domain"`
}

type VhostRemoveAction struct{}

func NewVhostRemoveAction() *VhostRemoveAction {
	return &VhostRemoveAction{}
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

	availPath := fmt.Sprintf("/etc/nginx/sites-available/%s.conf", p.Domain)
	enabledPath := fmt.Sprintf("/etc/nginx/sites-enabled/%s.conf", p.Domain)

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

	if runtime.GOOS == "linux" {
		_ = os.Remove(enabledPath)
		_ = os.Remove(availPath)

		if _, err := exec.LookPath("nginx"); err == nil {
			_ = exec.CommandContext(ctx, "nginx", "-t").Run()
			_ = exec.CommandContext(ctx, "systemctl", "reload", "nginx").Run()
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

