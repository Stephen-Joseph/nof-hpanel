package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os/exec"
	"runtime"
)

type PhpInstallVersionPayload struct {
	Version string `json:"version"`
}

type PhpInstallVersionAction struct{}

func NewPhpInstallVersionAction() *PhpInstallVersionAction {
	return &PhpInstallVersionAction{}
}

func (a *PhpInstallVersionAction) Name() string {
	return "php.install_version"
}

func (a *PhpInstallVersionAction) Validate(payload []byte) error {
	var p PhpInstallVersionPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	return ValidateSupportedPHPVersion(p.Version)
}

func (a *PhpInstallVersionAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p PhpInstallVersionPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{
		fmt.Sprintf("installing PHP %s packages from Sury PPA repository", p.Version),
	}

	if dryRun || runtime.GOOS != "linux" {
		logs = append(logs, fmt.Sprintf("[dry-run] simulated apt installation for PHP %s", p.Version))
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"version":   p.Version,
				"installed": true,
				"dry_run":   dryRun,
			},
			Logs: logs,
		}, nil
	}

	packages := []string{
		fmt.Sprintf("php%s-fpm", p.Version),
		fmt.Sprintf("php%s-cli", p.Version),
		fmt.Sprintf("php%s-common", p.Version),
		fmt.Sprintf("php%s-mysql", p.Version),
		fmt.Sprintf("php%s-xml", p.Version),
		fmt.Sprintf("php%s-mbstring", p.Version),
		fmt.Sprintf("php%s-curl", p.Version),
		fmt.Sprintf("php%s-zip", p.Version),
		fmt.Sprintf("php%s-bcmath", p.Version),
		fmt.Sprintf("php%s-intl", p.Version),
	}

	args := append([]string{"install", "-y", "--no-install-recommends"}, packages...)
	cmd := exec.CommandContext(ctx, "apt-get", args...)
	output, err := cmd.CombinedOutput()
	logs = append(logs, string(output))

	if err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("apt install failed: %s", err),
			Logs:    logs,
		}, err
	}

	// Ensure systemd service is enabled and started
	serviceName := fmt.Sprintf("php%s-fpm", p.Version)
	_ = exec.Command("systemctl", "enable", "--now", serviceName).Run()

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"version":   p.Version,
			"installed": true,
		},
		Logs: logs,
	}, nil
}
