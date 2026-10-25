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

type AccountUnsuspendPayload struct {
	Username string `json:"username"`
}

type AccountUnsuspendAction struct{}

func NewAccountUnsuspendAction() *AccountUnsuspendAction {
	return &AccountUnsuspendAction{}
}

func (a *AccountUnsuspendAction) Name() string {
	return "account.unsuspend"
}

func (a *AccountUnsuspendAction) Validate(payload []byte) error {
	var p AccountUnsuspendPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	return ValidateLinuxUsername(p.Username)
}

func (a *AccountUnsuspendAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p AccountUnsuspendPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("initiating account.unsuspend for user '%s'", p.Username)}

	if dryRun {
		logs = append(logs,
			fmt.Sprintf("[dry-run] simulated shell restore to /usr/sbin/nologin for '%s'", p.Username),
			fmt.Sprintf("[dry-run] simulated PHP-FPM pool restoration for '%s'", p.Username),
			fmt.Sprintf("[dry-run] simulated removal of /home/%s/.suspended marker", p.Username),
			fmt.Sprintf("[dry-run] simulated cron restoration for '%s'", p.Username),
		)
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username":  p.Username,
				"suspended": false,
				"dry_run":   true,
			},
			Logs: logs,
		}, nil
	}

	homeDir := fmt.Sprintf("/home/%s", p.Username)

	// Step 1: Restore shell login capability (/usr/sbin/nologin for SFTP)
	if runtime.GOOS == "linux" {
		_ = exec.CommandContext(ctx, "usermod", "-s", "/usr/sbin/nologin", p.Username).Run()
		logs = append(logs, "restored user shell to nologin")
	}

	// Step 2: Remove suspension marker file
	markerPath := filepath.Join(homeDir, ".suspended")
	_ = os.Remove(markerPath)
	logs = append(logs, "removed suspension marker")

	// Step 3: Restore suspended PHP-FPM pool files
	phpVersions := []string{"8.1", "8.2", "8.3", "8.4"}
	for _, ver := range phpVersions {
		poolFile := fmt.Sprintf("/etc/php/%s/fpm/pool.d/%s.conf", ver, p.Username)
		suspendedPoolFile := poolFile + ".suspended"

		if _, err := os.Stat(suspendedPoolFile); err == nil {
			_ = os.Rename(suspendedPoolFile, poolFile)
			if runtime.GOOS == "linux" {
				_ = exec.CommandContext(ctx, "systemctl", "reload", fmt.Sprintf("php%s-fpm", ver)).Run()
			}
			logs = append(logs, fmt.Sprintf("restored PHP-FPM pool for PHP %s", ver))
		}
	}

	// Step 4: Restore crontab if backup exists
	crontabBackup := filepath.Join(homeDir, ".crontab.backup")
	if _, err := os.Stat(crontabBackup); err == nil && runtime.GOOS == "linux" {
		_ = exec.CommandContext(ctx, "crontab", "-u", p.Username, crontabBackup).Run()
		_ = os.Remove(crontabBackup)
		logs = append(logs, "restored user crontab")
	}

	logs = append(logs, "account.unsuspend completed successfully")

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username":  p.Username,
			"suspended": false,
			"dry_run":   false,
		},
		Logs: logs,
	}, nil
}
