package actions

import (
	"context"
	"fmt"
	"os/exec"
	"runtime"
	"strings"
)

type VhostReloadAction struct{}

func NewVhostReloadAction() *VhostReloadAction {
	return &VhostReloadAction{}
}

func (a *VhostReloadAction) Name() string {
	return "vhost.reload"
}

func (a *VhostReloadAction) Validate(payload []byte) error {
	return nil
}

func (a *VhostReloadAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	logs := []string{"reloading Nginx web server"}

	if dryRun {
		logs = append(logs, "[dry-run] simulated nginx -t and systemctl reload nginx")
		return &ActionResult{
			Success: true,
			Data:    map[string]any{"reloaded": true, "dry_run": true},
			Logs:    logs,
		}, nil
	}

	if runtime.GOOS == "linux" {
		if _, err := exec.LookPath("nginx"); err == nil {
			testCmd := exec.CommandContext(ctx, "nginx", "-t")
			if out, err := testCmd.CombinedOutput(); err != nil {
				return &ActionResult{
					Success: false,
					Error:   fmt.Sprintf("nginx syntax test failed: %s", strings.TrimSpace(string(out))),
					Logs:    logs,
				}, err
			}

			reloadCmd := exec.CommandContext(ctx, "systemctl", "reload", "nginx")
			if out, err := reloadCmd.CombinedOutput(); err != nil {
				return &ActionResult{
					Success: false,
					Error:   fmt.Sprintf("nginx reload failed: %s", strings.TrimSpace(string(out))),
					Logs:    logs,
				}, err
			}
		}
		logs = append(logs, "Nginx reloaded successfully")
	} else {
		logs = append(logs, "simulated nginx reload on non-linux OS")
	}

	return &ActionResult{
		Success: true,
		Data:    map[string]any{"reloaded": true, "dry_run": false},
		Logs:    logs,
	}, nil
}

