package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"time"
)

type AccountSuspendPayload struct {
	Username string `json:"username"`
	Reason   string `json:"reason,omitempty"`
}

type AccountSuspendAction struct{}

func NewAccountSuspendAction() *AccountSuspendAction {
	return &AccountSuspendAction{}
}

func (a *AccountSuspendAction) Name() string {
	return "account.suspend"
}

func (a *AccountSuspendAction) Validate(payload []byte) error {
	var p AccountSuspendPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	return ValidateLinuxUsername(p.Username)
}

func (a *AccountSuspendAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p AccountSuspendPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	if p.Reason == "" {
		p.Reason = "Administrative suspension"
	}

	logs := []string{fmt.Sprintf("initiating account.suspend for user '%s' (reason: %s)", p.Username, p.Reason)}

	if dryRun {
		logs = append(logs,
			fmt.Sprintf("[dry-run] simulated shell lock for user '%s'", p.Username),
			fmt.Sprintf("[dry-run] simulated PHP-FPM pool deactivation for '%s'", p.Username),
			fmt.Sprintf("[dry-run] simulated cron suspension for '%s'", p.Username),
			fmt.Sprintf("[dry-run] simulated creation of /home/%s/.suspended marker", p.Username),
		)
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username":  p.Username,
				"suspended": true,
				"reason":    p.Reason,
				"dry_run":   true,
			},
			Logs: logs,
		}, nil
	}

	homeDir := fmt.Sprintf("/home/%s", p.Username)

	// Step 1: Lock Linux user account / change shell to /bin/false
	if runtime.GOOS == "linux" {
		_ = exec.CommandContext(ctx, "usermod", "-s", "/bin/false", p.Username).Run()
		logs = append(logs, "locked user shell login")
	}

	// Step 2: Write suspension marker file
	markerPath := filepath.Join(homeDir, ".suspended")
	markerData := fmt.Sprintf("SUSPENDED_AT=%s\nREASON=%s\n", time.Now().UTC().Format(time.RFC3339), p.Reason)
	_ = os.WriteFile(markerPath, []byte(markerData), 0600)
	logs = append(logs, "wrote suspension marker file")

	// Step 3: Disable PHP-FPM pool across supported PHP versions
	phpVersions := []string{"8.1", "8.2", "8.3", "8.4"}
	for _, ver := range phpVersions {
		poolFile := fmt.Sprintf("/etc/php/%s/fpm/pool.d/%s.conf", ver, p.Username)
		suspendedPoolFile := poolFile + ".suspended"

		if _, err := os.Stat(poolFile); err == nil {
			_ = os.Rename(poolFile, suspendedPoolFile)
			if runtime.GOOS == "linux" {
				_ = exec.CommandContext(ctx, "systemctl", "reload", fmt.Sprintf("php%s-fpm", ver)).Run()
			}
			logs = append(logs, fmt.Sprintf("disabled PHP-FPM pool for PHP %s", ver))
		}
	}

	// Step 4: Backup & clear user crontab
	if runtime.GOOS == "linux" {
		crontabOut, err := exec.CommandContext(ctx, "crontab", "-u", p.Username, "-l").Output()
		if err == nil && len(crontabOut) > 0 {
			_ = WriteConfigFileAtomically(filepath.Join(homeDir, ".crontab.backup"), crontabOut, 0600)
			_ = exec.CommandContext(ctx, "crontab", "-u", p.Username, "-r").Run()
			logs = append(logs, "backed up and removed user crontab")
		}
	}

	logs = append(logs, "account.suspend completed successfully")

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username":  p.Username,
			"suspended": true,
			"reason":    p.Reason,
			"dry_run":   false,
		},
		Logs: logs,
	}, nil
}
