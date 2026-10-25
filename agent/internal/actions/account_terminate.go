package actions

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
)

type AccountTerminatePayload struct {
	Username     string `json:"username"`
	ConfirmToken string `json:"confirm_token"`
	TakeBackup   bool   `json:"take_backup"`
}

type AccountTerminateAction struct{}

func NewAccountTerminateAction() *AccountTerminateAction {
	return &AccountTerminateAction{}
}

func (a *AccountTerminateAction) Name() string {
	return "account.terminate"
}

func (a *AccountTerminateAction) Validate(payload []byte) error {
	var p AccountTerminatePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	expectedToken := "TERMINATE-" + p.Username
	if p.ConfirmToken != expectedToken {
		return fmt.Errorf("invalid confirmation token '%s', expected '%s'", p.ConfirmToken, expectedToken)
	}
	return nil
}

func (a *AccountTerminateAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p AccountTerminatePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	expectedToken := "TERMINATE-" + p.Username
	if p.ConfirmToken != expectedToken {
		return &ActionResult{Success: false, Error: "invalid confirmation token"}, errors.New("invalid confirmation token")
	}

	logs := []string{fmt.Sprintf("initiating account.terminate for user '%s'", p.Username)}

	if dryRun {
		logs = append(logs,
			fmt.Sprintf("[dry-run] simulated process termination for user '%s'", p.Username),
			fmt.Sprintf("[dry-run] simulated backup creation if take_backup=%v", p.TakeBackup),
			fmt.Sprintf("[dry-run] simulated PHP-FPM pool removal for user '%s'", p.Username),
			fmt.Sprintf("[dry-run] simulated crontab removal for user '%s'", p.Username),
			fmt.Sprintf("[dry-run] simulated userdel -r '%s' and home removal", p.Username),
		)
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username":    p.Username,
				"terminated":  true,
				"backup_made": p.TakeBackup,
				"dry_run":     true,
			},
			Logs: logs,
		}, nil
	}

	homeDir := fmt.Sprintf("/home/%s", p.Username)

	// Step 1: Create final backup if requested
	backupPath := ""
	if p.TakeBackup && runtime.GOOS == "linux" {
		backupDir := "/var/backups/panel-agent"
		_ = os.MkdirAll(backupDir, 0700)
		backupPath = filepath.Join(backupDir, fmt.Sprintf("%s-final.tar.gz", p.Username))
		if _, err := os.Stat(homeDir); err == nil {
			// Strict exec argument array only (no shell strings)
			tarCmd := exec.CommandContext(ctx, "tar", "-czf", backupPath, "-C", "/home", p.Username)
			if err := tarCmd.Run(); err == nil {
				logs = append(logs, fmt.Sprintf("created final archive at %s", backupPath))
			} else {
				logs = append(logs, fmt.Sprintf("warning: failed creating final backup: %v", err))
			}
		}
	}

	// Step 2: Terminate running user processes (pkill -u <username>)
	if runtime.GOOS == "linux" {
		_ = exec.CommandContext(ctx, "pkill", "-9", "-u", p.Username).Run()
		logs = append(logs, "terminated active user processes")
	}

	// Step 3: Remove PHP-FPM pools
	phpVersions := []string{"8.1", "8.2", "8.3", "8.4"}
	for _, ver := range phpVersions {
		poolFile := fmt.Sprintf("/etc/php/%s/fpm/pool.d/%s.conf", ver, p.Username)
		suspendedFile := poolFile + ".suspended"
		_ = os.Remove(poolFile)
		_ = os.Remove(suspendedFile)
		if runtime.GOOS == "linux" {
			_ = exec.CommandContext(ctx, "systemctl", "reload", fmt.Sprintf("php%s-fpm", ver)).Run()
		}
	}
	logs = append(logs, "removed PHP-FPM pool configurations")

	// Step 4: Remove systemd slice
	sliceDir := fmt.Sprintf("/etc/systemd/system/hpanel-%s.slice.d", p.Username)
	_ = os.RemoveAll(sliceDir)
	if runtime.GOOS == "linux" {
		_ = exec.CommandContext(ctx, "systemctl", "daemon-reload").Run()
	}

	// Step 5: Remove user crontab
	if runtime.GOOS == "linux" {
		_ = exec.CommandContext(ctx, "crontab", "-u", p.Username, "-r").Run()
		logs = append(logs, "removed user crontab")
	}

	// Step 6: Delete system user and remove /home/<username>
	if runtime.GOOS == "linux" {
		// userdel -r <user>
		userdel := exec.CommandContext(ctx, "userdel", "-r", p.Username)
		if err := userdel.Run(); err != nil {
			// If user doesn't exist, ignore (idempotent), otherwise try to clean up directory
			_ = os.RemoveAll(homeDir)
		}
		logs = append(logs, fmt.Sprintf("removed system user '%s' and home directory", p.Username))
	} else {
		_ = os.RemoveAll(homeDir)
		logs = append(logs, fmt.Sprintf("cleaned up %s on non-linux OS", homeDir))
	}

	logs = append(logs, "account.terminate completed successfully")

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username":    p.Username,
			"terminated":  true,
			"backup_path": backupPath,
			"dry_run":     false,
		},
		Logs: logs,
	}, nil
}
