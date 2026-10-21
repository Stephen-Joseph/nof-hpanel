package actions

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"runtime"
)

type PhpListVersionsAction struct{}

func NewPhpListVersionsAction() *PhpListVersionsAction {
	return &PhpListVersionsAction{}
}

func (a *PhpListVersionsAction) Name() string {
	return "php.list_versions"
}

func (a *PhpListVersionsAction) Validate(payload []byte) error {
	return nil
}

func (a *PhpListVersionsAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	logs := []string{"detecting installed PHP versions and FPM service states"}

	if dryRun || runtime.GOOS != "linux" {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"supported_versions": SupportedPHPVersions,
				"installed_versions": []string{"8.1", "8.2", "8.3", "8.4"},
				"default_version":    "8.3",
				"statuses": map[string]string{
					"8.1": "active",
					"8.2": "active",
					"8.3": "active",
					"8.4": "active",
				},
				"dry_run": dryRun,
			},
			Logs: logs,
		}, nil
	}

	installed := []string{}
	statuses := make(map[string]string)

	for _, v := range SupportedPHPVersions {
		binPath := fmt.Sprintf("/usr/bin/php%s", v)
		fpmPath := fmt.Sprintf("/usr/sbin/php-fpm%s", v)

		if _, err := os.Stat(binPath); err == nil || os.Stat(fpmPath) == nil {
			installed = append(installed, v)

			// Check systemd status
			cmd := exec.CommandContext(ctx, "systemctl", "is-active", fmt.Sprintf("php%s-fpm", v))
			out, _ := cmd.Output()
			status := string(out)
			if status == "" {
				status = "inactive"
			}
			statuses[v] = status
		}
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"supported_versions": SupportedPHPVersions,
			"installed_versions": installed,
			"default_version":    "8.3",
			"statuses":           statuses,
		},
		Logs: logs,
	}, nil
}

