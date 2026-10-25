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

type PhpPoolWritePayload struct {
	Username    string         `json:"username"`
	Version     string         `json:"version"`
	PmMode      string         `json:"pm_mode,omitempty"`
	MaxChildren int            `json:"max_children,omitempty"`
	MaxMemoryMB int            `json:"max_memory_mb,omitempty"`
	Settings    PhpIniSettings `json:"settings,omitempty"`
}

type PhpPoolWriteAction struct{}

func NewPhpPoolWriteAction() *PhpPoolWriteAction {
	return &PhpPoolWriteAction{}
}

func (a *PhpPoolWriteAction) Name() string {
	return "php.pool_write"
}

func (a *PhpPoolWriteAction) Validate(payload []byte) error {
	var p PhpPoolWritePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}

	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if err := ValidateSupportedPHPVersion(p.Version); err != nil {
		return err
	}
	if p.PmMode != "" {
		if err := ValidatePmMode(p.PmMode); err != nil {
			return err
		}
	}
	if err := ValidatePhpIniSettings(p.Settings); err != nil {
		return err
	}

	// Package limit memory check
	if p.MaxMemoryMB > 0 && p.Settings.MemoryLimit != "" {
		requestedMB := ParseMemoryToMB(p.Settings.MemoryLimit)
		if requestedMB > p.MaxMemoryMB {
			return fmt.Errorf("memory_limit %s exceeds package limit of %dM", p.Settings.MemoryLimit, p.MaxMemoryMB)
		}
	}

	return nil
}

func (a *PhpPoolWriteAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p PhpPoolWritePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if p.PmMode == "" {
		p.PmMode = "ondemand"
	}
	if p.MaxChildren <= 0 {
		p.MaxChildren = 5
	}

	poolData := PhpPoolTemplateData{
		Username:    p.Username,
		Version:     p.Version,
		PmMode:      p.PmMode,
		MaxChildren: p.MaxChildren,
		Settings:    p.Settings,
	}

	configContent, err := RenderPhpPoolConfig(poolData)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{
		fmt.Sprintf("rendering PHP-FPM pool for '%s' (PHP %s, pm: %s, max_children: %d)", p.Username, p.Version, p.PmMode, p.MaxChildren),
	}

	targetDir := fmt.Sprintf("/etc/php/%s/fpm/pool.d", p.Version)
	targetFile := filepath.Join(targetDir, fmt.Sprintf("%s.conf", p.Username))

	if dryRun {
		logs = append(logs,
			fmt.Sprintf("[dry-run] simulated syntax validation: php-fpm%s -t", p.Version),
			fmt.Sprintf("[dry-run] simulated atomic write to %s", targetFile),
			fmt.Sprintf("[dry-run] simulated service reload: systemctl reload php%s-fpm", p.Version),
		)
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username":     p.Username,
				"version":      p.Version,
				"pool_file":    targetFile,
				"socket":       fmt.Sprintf("/run/php/php%s-fpm-%s.sock", p.Version, p.Username),
				"config_bytes": len(configContent),
				"dry_run":      true,
			},
			Logs: logs,
		}, nil
	}

	if runtime.GOOS != "linux" {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username":     p.Username,
				"version":      p.Version,
				"pool_file":    targetFile,
				"config_bytes": len(configContent),
			},
			Logs: logs,
		}, nil
	}

	_ = os.MkdirAll(targetDir, 0755)
	tempFile, err := CreateConfigTemp(targetFile, []byte(configContent), 0640)
	if err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed writing temp pool config: %s", err),
			Logs:    logs,
		}, err
	}
	defer os.Remove(tempFile)

	// Step 1: Pre-flight syntax validation
	fpmBinary := fmt.Sprintf("php-fpm%s", p.Version)
	testCmd := exec.CommandContext(ctx, fpmBinary, "-t")
	testOutput, err := testCmd.CombinedOutput()
	if err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("PHP-FPM syntax test failed: %s (%s)", err, string(testOutput)),
			Logs:    append(logs, string(testOutput)),
		}, err
	}

	// Backup existing pool config if present
	var backupData []byte
	if data, readErr := os.ReadFile(targetFile); readErr == nil {
		backupData = data
	}

	// Step 2: Atomic move
	if err := CommitConfigFile(tempFile, targetFile, 0644); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed to move pool config to %s: %s", targetFile, err),
			Logs:    logs,
		}, err
	}

	// Step 3: Reload FPM service
	serviceName := fmt.Sprintf("php%s-fpm", p.Version)
	reloadCmd := exec.CommandContext(ctx, "systemctl", "reload", serviceName)
	if reloadOutput, reloadErr := reloadCmd.CombinedOutput(); reloadErr != nil {
		// Rollback on reload failure
		if backupData != nil {
			_ = WriteConfigFileAtomically(targetFile, backupData, 0644)
			_ = exec.Command("systemctl", "reload", serviceName).Run()
		} else {
			_ = os.Remove(targetFile)
			_ = exec.Command("systemctl", "reload", serviceName).Run()
		}

		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed to reload %s: %s (%s)", serviceName, reloadErr, string(reloadOutput)),
			Logs:    append(logs, string(reloadOutput)),
		}, reloadErr
	}

	logs = append(logs, fmt.Sprintf("PHP-FPM pool for '%s' deployed and %s reloaded", p.Username, serviceName))

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username":  p.Username,
			"version":   p.Version,
			"pool_file": targetFile,
			"socket":    fmt.Sprintf("/run/php/php%s-fpm-%s.sock", p.Version, p.Username),
		},
		Logs: logs,
	}, nil
}
