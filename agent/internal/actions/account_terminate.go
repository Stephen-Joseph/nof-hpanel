package actions

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"strings"
)

type AccountTerminatePayload struct {
	Username     string   `json:"username"`
	ConfirmToken string   `json:"confirm_token"`
	TakeBackup   bool     `json:"take_backup"`
	Domains      []string `json:"domains,omitempty"`
}

type AccountTerminateAction struct {
	executor          commandExecutor
	homeBaseDir       string
	vhostAvailableDir string
	vhostEnabledDir   string
	phpConfigBaseDir  string
	sshdConfigDir     string
	isLinux           bool
}

func NewAccountTerminateAction() *AccountTerminateAction {
	return &AccountTerminateAction{
		executor:          osCommandExecutor{},
		homeBaseDir:       "/home",
		vhostAvailableDir: "/etc/nginx/sites-available",
		vhostEnabledDir:   "/etc/nginx/sites-enabled",
		phpConfigBaseDir:  "/etc/php",
		sshdConfigDir:     "/etc/ssh/sshd_config.d",
		isLinux:           runtime.GOOS == "linux",
	}
}

func NewAccountTerminateActionForTest(executor commandExecutor, homeBaseDir, vhostAvailableDir, vhostEnabledDir, phpConfigBaseDir, sshdConfigDir string) *AccountTerminateAction {
	return &AccountTerminateAction{
		executor:          executor,
		homeBaseDir:       homeBaseDir,
		vhostAvailableDir: vhostAvailableDir,
		vhostEnabledDir:   vhostEnabledDir,
		phpConfigBaseDir:  phpConfigBaseDir,
		sshdConfigDir:     sshdConfigDir,
		isLinux:           true,
	}
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
	for _, domain := range p.Domains {
		if err := ValidateDomain(domain); err != nil {
			return fmt.Errorf("invalid domain '%s': %w", domain, err)
		}
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
			fmt.Sprintf("[dry-run] simulated Nginx vhost removal for user '%s'", p.Username),
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

	homeDir := filepath.Join(a.homeBaseDir, p.Username)

	// Step 1: Create final backup if requested
	backupPath := ""
	if p.TakeBackup && a.isLinux {
		backupDir := "/var/backups/panel-agent"
		_ = os.MkdirAll(backupDir, 0700)
		backupPath = filepath.Join(backupDir, fmt.Sprintf("%s-final.tar.gz", p.Username))
		if _, err := os.Stat(homeDir); err == nil {
			// Strict exec argument array only (no shell strings)
			tarCmd := a.executor.Run(ctx, "tar", "-czf", backupPath, "-C", a.homeBaseDir, p.Username)
			if tarCmd == nil {
				logs = append(logs, fmt.Sprintf("created final archive at %s", backupPath))
			} else {
				logs = append(logs, fmt.Sprintf("warning: failed creating final backup: %v", tarCmd))
			}
		}
	}

	// Step 2: Terminate running user processes (pkill -u <username>)
	if a.isLinux {
		_ = a.executor.Run(ctx, "pkill", "-9", "-u", p.Username)
		logs = append(logs, "terminated active user processes")
	}

	// Step 3: Remove PHP-FPM pools
	phpVersions := []string{"8.1", "8.2", "8.3", "8.4"}
	for _, ver := range phpVersions {
		poolFile := filepath.Join(a.phpConfigBaseDir, ver, "fpm", "pool.d", fmt.Sprintf("%s.conf", p.Username))
		suspendedFile := poolFile + ".suspended"
		_ = os.Remove(poolFile)
		_ = os.Remove(suspendedFile)
		if a.isLinux {
			_ = a.executor.Run(ctx, "systemctl", "reload", fmt.Sprintf("php%s-fpm", ver))
		}
	}
	logs = append(logs, "removed PHP-FPM pool configurations")

	// Step 4: Remove Nginx vhosts for this account
	if a.isLinux {
		vhostsRemoved := 0

		// Explicit domains passed in payload
		for _, domain := range p.Domains {
			availPath := filepath.Join(a.vhostAvailableDir, fmt.Sprintf("%s.conf", domain))
			enabledPath := filepath.Join(a.vhostEnabledDir, fmt.Sprintf("%s.conf", domain))
			if _, err := os.Lstat(enabledPath); err == nil {
				_ = os.Remove(enabledPath)
				vhostsRemoved++
			}
			if _, err := os.Stat(availPath); err == nil {
				_ = os.Remove(availPath)
				vhostsRemoved++
			}
		}

		// Also discover any vhosts containing /home/<username> or /home/<username>/
		userHomeStr := filepath.Join(a.homeBaseDir, p.Username)
		userHomeSlash := userHomeStr + "/"
		userSockStr := fmt.Sprintf("-%s.sock", p.Username)

		if entries, err := os.ReadDir(a.vhostAvailableDir); err == nil {
			for _, entry := range entries {
				if entry.IsDir() || !strings.HasSuffix(entry.Name(), ".conf") {
					continue
				}
				confPath := filepath.Join(a.vhostAvailableDir, entry.Name())
				data, readErr := os.ReadFile(confPath)
				if readErr != nil {
					continue
				}
				content := string(data)
				if strings.Contains(content, userHomeSlash) || strings.Contains(content, userHomeStr) || strings.Contains(content, userSockStr) {
					enabledPath := filepath.Join(a.vhostEnabledDir, entry.Name())
					_ = os.Remove(enabledPath)
					_ = os.Remove(confPath)
					vhostsRemoved++
				}
			}
		}

		// Clean up any broken/dangling symlinks in sites-enabled
		if entries, err := os.ReadDir(a.vhostEnabledDir); err == nil {
			for _, entry := range entries {
				linkPath := filepath.Join(a.vhostEnabledDir, entry.Name())
				if target, err := os.Readlink(linkPath); err == nil {
					// Resolve target relative to directory if needed
					if !filepath.IsAbs(target) {
						target = filepath.Join(a.vhostEnabledDir, target)
					}
					if _, statErr := os.Stat(target); os.IsNotExist(statErr) {
						_ = os.Remove(linkPath)
						vhostsRemoved++
					}
				}
			}
		}

		if vhostsRemoved > 0 {
			if _, err := a.executor.LookPath("nginx"); err == nil {
				_ = a.executor.Run(ctx, "nginx", "-t")
			}
			if _, err := a.executor.LookPath("systemctl"); err == nil {
				_ = a.executor.Run(ctx, "systemctl", "reload", "nginx")
			}
			logs = append(logs, fmt.Sprintf("removed %d Nginx vhost configurations", vhostsRemoved))
		}
	}

	// Step 5: Remove systemd slice
	sliceDir := fmt.Sprintf("/etc/systemd/system/hpanel-%s.slice.d", p.Username)
	_ = os.RemoveAll(sliceDir)
	if a.isLinux {
		_ = a.executor.Run(ctx, "systemctl", "daemon-reload")
	}

	// Step 6: Remove sshd match block
	sshdConf := filepath.Join(a.sshdConfigDir, fmt.Sprintf("hpanel-%s.conf", p.Username))
	if _, err := os.Stat(sshdConf); err == nil {
		_ = os.Remove(sshdConf)
		if a.isLinux {
			if out, err := a.executor.CombinedOutput(ctx, "sshd", "-t"); err == nil {
				_ = a.executor.Run(ctx, "systemctl", "reload", "ssh")
			} else {
				logs = append(logs, fmt.Sprintf("sshd -t check warning after removing match block: %s", string(out)))
			}
		}
	}

	// Step 7: Remove user crontab
	if a.isLinux {
		_ = a.executor.Run(ctx, "crontab", "-u", p.Username, "-r")
		logs = append(logs, "removed user crontab")
	}

	// Step 8: Delete system user and remove home directory recursively (including root-owned files)
	if a.isLinux {
		_ = a.executor.Run(ctx, "userdel", "-r", p.Username)
	}

	if _, err := os.Stat(homeDir); err == nil {
		// Ensure write/exec permissions so root-owned or read-only files can be removed
		_ = filepath.Walk(homeDir, func(path string, info os.FileInfo, err error) error {
			if err == nil && info.IsDir() {
				_ = os.Chmod(path, 0777)
			}
			return nil
		})
		_ = a.executor.Run(ctx, "rm", "-rf", homeDir)
		_ = os.RemoveAll(homeDir)
	}

	// Step 9: Verify and explicitly report any leftover paths
	var leftovers []string

	// Check home directory
	if _, err := os.Stat(homeDir); err == nil {
		leftovers = append(leftovers, homeDir)
	}

	// Check PHP-FPM pool files
	for _, ver := range phpVersions {
		poolFile := filepath.Join(a.phpConfigBaseDir, ver, "fpm", "pool.d", fmt.Sprintf("%s.conf", p.Username))
		if _, err := os.Stat(poolFile); err == nil {
			leftovers = append(leftovers, poolFile)
		}
		suspendedFile := poolFile + ".suspended"
		if _, err := os.Stat(suspendedFile); err == nil {
			leftovers = append(leftovers, suspendedFile)
		}
	}

	// Check vhost files & symlinks
	for _, domain := range p.Domains {
		enabledPath := filepath.Join(a.vhostEnabledDir, fmt.Sprintf("%s.conf", domain))
		if _, err := os.Lstat(enabledPath); err == nil {
			leftovers = append(leftovers, enabledPath)
		}
		availPath := filepath.Join(a.vhostAvailableDir, fmt.Sprintf("%s.conf", domain))
		if _, err := os.Stat(availPath); err == nil {
			leftovers = append(leftovers, availPath)
		}
	}

	// Scan vhost available dir for any remaining user vhost
	if entries, err := os.ReadDir(a.vhostAvailableDir); err == nil {
		userHomeStr := filepath.Join(a.homeBaseDir, p.Username)
		userSockStr := fmt.Sprintf("-%s.sock", p.Username)
		for _, entry := range entries {
			if entry.IsDir() || !strings.HasSuffix(entry.Name(), ".conf") {
				continue
			}
			confPath := filepath.Join(a.vhostAvailableDir, entry.Name())
			if data, err := os.ReadFile(confPath); err == nil {
				content := string(data)
				if strings.Contains(content, userHomeStr) || strings.Contains(content, userSockStr) {
					leftovers = append(leftovers, confPath)
				}
			}
		}
	}

	// Check SSHD match block
	if _, err := os.Stat(sshdConf); err == nil {
		leftovers = append(leftovers, sshdConf)
	}

	if len(leftovers) > 0 {
		errMsg := fmt.Sprintf("leftover paths remain after termination: %s", strings.Join(leftovers, ", "))
		logs = append(logs, "error: "+errMsg)
		return &ActionResult{
			Success: false,
			Error:   errMsg,
			Data: map[string]any{
				"username":   p.Username,
				"terminated": false,
				"leftovers":  leftovers,
			},
			Logs: logs,
		}, fmt.Errorf("%s", errMsg)
	}

	logs = append(logs, "account.terminate completed successfully")

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username":    p.Username,
			"terminated":  true,
			"backup_path": backupPath,
			"leftovers":   []string{},
			"dry_run":     false,
		},
		Logs: logs,
	}, nil
}
