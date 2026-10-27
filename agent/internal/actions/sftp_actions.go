package actions

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

// DetectSftpServerPath searches common distro locations for the OpenSSH sftp-server binary.
func DetectSftpServerPath() string {
	candidates := []string{
		"/usr/lib/openssh/sftp-server",
		"/usr/libexec/openssh/sftp-server",
		"/usr/lib/ssh/sftp-server",
		"/usr/libexec/sftp-server",
	}
	for _, p := range candidates {
		if _, err := os.Stat(p); err == nil {
			return p
		}
	}
	return "/usr/lib/openssh/sftp-server"
}

// EnsureGlobalSshdConfig idempotently comments out conflicting password auth lines in sshd_config
// and deploys /etc/ssh/sshd_config.d/00-hpanel.conf so panel settings sort first and apply cleanly.
func EnsureGlobalSshdConfig(ctx context.Context, sshdConfigFile, sshdConfigDir string, executor commandExecutor) error {
	if sshdConfigFile == "" {
		sshdConfigFile = "/etc/ssh/sshd_config"
	}
	if sshdConfigDir == "" {
		sshdConfigDir = "/etc/ssh/sshd_config.d"
	}
	if executor == nil {
		executor = osCommandExecutor{}
	}

	// 1. Comment out PasswordAuthentication no and KbdInteractiveAuthentication no in main sshd_config
	if mainData, err := os.ReadFile(sshdConfigFile); err == nil {
		content := string(mainData)
		rePass := regexp.MustCompile(`(?m)^[ \t]*PasswordAuthentication[ \t]+no\b`)
		reKbd := regexp.MustCompile(`(?m)^[ \t]*KbdInteractiveAuthentication[ \t]+no\b`)

		needsUpdate := rePass.MatchString(content) || reKbd.MatchString(content)
		if needsUpdate {
			// Backup once
			bakPath := sshdConfigFile + ".hpanel.bak"
			if _, statErr := os.Stat(bakPath); os.IsNotExist(statErr) {
				_ = os.WriteFile(bakPath, mainData, 0644)
			}

			modified := rePass.ReplaceAllString(content, "# PasswordAuthentication no (disabled by HPanel)")
			modified = reKbd.ReplaceAllString(modified, "# KbdInteractiveAuthentication no (disabled by HPanel)")

			if err := atomicWriteFile(sshdConfigFile, []byte(modified), 0644, 0, 0); err != nil {
				return fmt.Errorf("failed writing updated %s: %w", sshdConfigFile, err)
			}

			// Validate with sshd -t
			if out, err := executor.CombinedOutput(ctx, "sshd", "-t"); err != nil {
				// Restore original
				_ = atomicWriteFile(sshdConfigFile, mainData, 0644, 0, 0)
				return fmt.Errorf("sshd -t failed after modifying main config: %s: %w", string(out), err)
			}
		}
	}

	// 2. Ensure /etc/ssh/sshd_config.d exists
	if err := os.MkdirAll(sshdConfigDir, 0755); err != nil {
		return fmt.Errorf("failed creating sshd_config.d directory %s: %w", sshdConfigDir, err)
	}

	// 3. Render 00-hpanel.conf
	sftpServerPath := DetectSftpServerPath()
	globalConfPath := filepath.Join(sshdConfigDir, "00-hpanel.conf")
	globalContent := fmt.Sprintf(`# Managed by HPanel - Global SFTP and SSH baseline
PasswordAuthentication yes
KbdInteractiveAuthentication yes
PubkeyAuthentication yes
Subsystem sftp %s
`, sftpServerPath)

	prevGlobal, _ := os.ReadFile(globalConfPath)

	if err := atomicWriteFile(globalConfPath, []byte(globalContent), 0644, 0, 0); err != nil {
		return fmt.Errorf("failed writing %s: %w", globalConfPath, err)
	}

	// Validate with sshd -t
	if out, err := executor.CombinedOutput(ctx, "sshd", "-t"); err != nil {
		if prevGlobal != nil {
			_ = atomicWriteFile(globalConfPath, prevGlobal, 0644, 0, 0)
		} else {
			_ = os.Remove(globalConfPath)
		}
		return fmt.Errorf("sshd -t validation failed for %s: %s: %w", globalConfPath, string(out), err)
	}

	// Reload ssh service
	if err := executor.Run(ctx, "systemctl", "reload", "ssh"); err != nil {
		_ = executor.Run(ctx, "systemctl", "reload", "sshd")
	}

	return nil
}

// MigrateLegacySubuserDropins scans sshd_config.d for drop-ins containing ChrootDirectory,
// backs them up, and removes the ChrootDirectory line to fix broken logins.
func MigrateLegacySubuserDropins(ctx context.Context, sshdConfigDir string, executor commandExecutor) error {
	if sshdConfigDir == "" {
		sshdConfigDir = "/etc/ssh/sshd_config.d"
	}
	if executor == nil {
		executor = osCommandExecutor{}
	}

	entries, err := os.ReadDir(sshdConfigDir)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return err
	}

	reChroot := regexp.MustCompile(`(?m)^[ \t]*ChrootDirectory[ \t]+.*(\r?\n)?`)
	var modifiedFiles []string
	var originalContents = make(map[string][]byte)

	for _, entry := range entries {
		name := entry.Name()
		if entry.IsDir() || !strings.HasSuffix(name, ".conf") || name == "00-hpanel.conf" {
			continue
		}
		// Match hpanel-subuser-*.conf or legacy hpanel-*.conf
		if !strings.HasPrefix(name, "hpanel-") && !strings.HasPrefix(name, "50-hpanel-") {
			continue
		}

		filePath := filepath.Join(sshdConfigDir, name)
		data, err := os.ReadFile(filePath)
		if err != nil {
			continue
		}

		if reChroot.Match(data) {
			originalContents[filePath] = data
			bakFile := filePath + ".bak"
			if _, statErr := os.Stat(bakFile); os.IsNotExist(statErr) {
				_ = os.WriteFile(bakFile, data, 0644)
			}

			cleaned := reChroot.ReplaceAll(data, nil)
			if err := atomicWriteFile(filePath, cleaned, 0644, 0, 0); err != nil {
				return err
			}
			modifiedFiles = append(modifiedFiles, filePath)
		}
	}

	if len(modifiedFiles) > 0 {
		if out, err := executor.CombinedOutput(ctx, "sshd", "-t"); err != nil {
			// Restore original contents
			for fPath, origData := range originalContents {
				_ = atomicWriteFile(fPath, origData, 0644, 0, 0)
			}
			return fmt.Errorf("sshd -t failed after migrating legacy dropins: %s: %w", string(out), err)
		}
		if err := executor.Run(ctx, "systemctl", "reload", "ssh"); err != nil {
			_ = executor.Run(ctx, "systemctl", "reload", "sshd")
		}
	}

	return nil
}

// -----------------------------------------------------------------------------
// sftp.enable Action
// -----------------------------------------------------------------------------

type SftpEnablePayload struct {
	Username string `json:"username"`
}

type SftpEnableAction struct {
	executor      commandExecutor
	sshdConfigDir string
}

func NewSftpEnableAction() *SftpEnableAction {
	return &SftpEnableAction{
		executor:      osCommandExecutor{},
		sshdConfigDir: "/etc/ssh/sshd_config.d",
	}
}

func NewSftpEnableActionForTest(executor commandExecutor, sshdConfigDir string) *SftpEnableAction {
	return &SftpEnableAction{
		executor:      executor,
		sshdConfigDir: sshdConfigDir,
	}
}

func (a *SftpEnableAction) Name() string {
	return "sftp.enable"
}

func (a *SftpEnableAction) Validate(payload []byte) error {
	var p SftpEnablePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	return ValidateLinuxUsername(p.Username)
}

func (a *SftpEnableAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p SftpEnablePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if err := ValidateLinuxUsername(p.Username); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("enabling SFTP access for Linux account '%s'", p.Username)}

	if dryRun {
		logs = append(logs, fmt.Sprintf("[dry-run] simulated sshd match block: /etc/ssh/sshd_config.d/50-hpanel-%s.conf", p.Username))
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username": p.Username,
				"enabled":  true,
				"dry_run":  true,
			},
			Logs: logs,
		}, nil
	}

	sshdDir := a.sshdConfigDir
	if sshdDir == "" {
		sshdDir = "/etc/ssh/sshd_config.d"
	}
	if err := os.MkdirAll(sshdDir, 0755); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	confPath := filepath.Join(sshdDir, fmt.Sprintf("50-hpanel-%s.conf", p.Username))
	content := fmt.Sprintf(`# HPanel SFTP access for %s
# Note: ChrootDirectory is omitted in this phase due to home ownership (0711).
# A dedicated chroot layout (/var/chroot/%s) will be introduced in a future release.
Match User %s
    ForceCommand internal-sftp -u 022
    AllowTcpForwarding no
    X11Forwarding no
    PasswordAuthentication yes
`, p.Username, p.Username, p.Username)

	// Read existing file if any for rollback on sshd -t failure
	prevData, _ := os.ReadFile(confPath)

	// Clean up legacy non-prefixed file if present
	legacyPath := filepath.Join(sshdDir, fmt.Sprintf("hpanel-%s.conf", p.Username))
	_ = os.Remove(legacyPath)

	if err := atomicWriteFile(confPath, []byte(content), 0644, 0, 0); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	// Validate with sshd -t
	if out, err := a.executor.CombinedOutput(ctx, "sshd", "-t"); err != nil {
		if prevData != nil {
			_ = atomicWriteFile(confPath, prevData, 0644, 0, 0)
		} else {
			_ = os.Remove(confPath)
		}
		errMsg := fmt.Sprintf("sshd -t validation failed: %s", string(out))
		return &ActionResult{Success: false, Error: errMsg}, fmt.Errorf("%s: %w", errMsg, err)
	}

	// Reload ssh service
	if err := a.executor.Run(ctx, "systemctl", "reload", "ssh"); err != nil {
		_ = a.executor.Run(ctx, "systemctl", "reload", "sshd")
	}

	logs = append(logs, fmt.Sprintf("sshd match block written to %s and ssh reloaded", confPath))

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username": p.Username,
			"enabled":  true,
		},
		Logs: logs,
	}, nil
}

// -----------------------------------------------------------------------------
// sftp.disable Action
// -----------------------------------------------------------------------------

type SftpDisablePayload struct {
	Username string `json:"username"`
}

type SftpDisableAction struct {
	executor      commandExecutor
	sshdConfigDir string
}

func NewSftpDisableAction() *SftpDisableAction {
	return &SftpDisableAction{
		executor:      osCommandExecutor{},
		sshdConfigDir: "/etc/ssh/sshd_config.d",
	}
}

func NewSftpDisableActionForTest(executor commandExecutor, sshdConfigDir string) *SftpDisableAction {
	return &SftpDisableAction{
		executor:      executor,
		sshdConfigDir: sshdConfigDir,
	}
}

func (a *SftpDisableAction) Name() string {
	return "sftp.disable"
}

func (a *SftpDisableAction) Validate(payload []byte) error {
	var p SftpDisablePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	return ValidateLinuxUsername(p.Username)
}

func (a *SftpDisableAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p SftpDisablePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if err := ValidateLinuxUsername(p.Username); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("disabling SFTP access for user '%s'", p.Username)}

	if dryRun {
		logs = append(logs, fmt.Sprintf("[dry-run] simulated removal of sshd match block for %s", p.Username))
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username": p.Username,
				"enabled":  false,
				"dry_run":  true,
			},
			Logs: logs,
		}, nil
	}

	sshdDir := a.sshdConfigDir
	if sshdDir == "" {
		sshdDir = "/etc/ssh/sshd_config.d"
	}

	confPath := filepath.Join(sshdDir, fmt.Sprintf("50-hpanel-%s.conf", p.Username))
	legacyPath := filepath.Join(sshdDir, fmt.Sprintf("hpanel-%s.conf", p.Username))

	prevData, _ := os.ReadFile(confPath)
	_ = os.Remove(confPath)
	_ = os.Remove(legacyPath)

	// Validate with sshd -t
	if out, err := a.executor.CombinedOutput(ctx, "sshd", "-t"); err != nil {
		if prevData != nil {
			_ = atomicWriteFile(confPath, prevData, 0644, 0, 0)
		}
		errMsg := fmt.Sprintf("sshd -t validation failed after removing %s: %s", confPath, string(out))
		return &ActionResult{Success: false, Error: errMsg}, fmt.Errorf("%s: %w", errMsg, err)
	}

	if err := a.executor.Run(ctx, "systemctl", "reload", "ssh"); err != nil {
		_ = a.executor.Run(ctx, "systemctl", "reload", "sshd")
	}

	logs = append(logs, fmt.Sprintf("removed match block for %s and reloaded ssh", p.Username))

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username": p.Username,
			"enabled":  false,
		},
		Logs: logs,
	}, nil
}

// -----------------------------------------------------------------------------
// sftp.set_password Action
// -----------------------------------------------------------------------------

type SftpSetPasswordPayload struct {
	Username string `json:"username"`
	Password string `json:"password"`
}

type SftpSetPasswordAction struct {
	executor commandExecutor
}

func NewSftpSetPasswordAction() *SftpSetPasswordAction {
	return &SftpSetPasswordAction{
		executor: osCommandExecutor{},
	}
}

func NewSftpSetPasswordActionForTest(executor commandExecutor) *SftpSetPasswordAction {
	return &SftpSetPasswordAction{
		executor: executor,
	}
}

func (a *SftpSetPasswordAction) Name() string {
	return "sftp.set_password"
}

func (a *SftpSetPasswordAction) Validate(payload []byte) error {
	var p SftpSetPasswordPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if len(p.Password) < 12 {
		return errors.New("password must be at least 12 characters")
	}
	return nil
}

func (a *SftpSetPasswordAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p SftpSetPasswordPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if err := ValidateLinuxUsername(p.Username); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if len(p.Password) < 12 {
		return &ActionResult{Success: false, Error: "password must be at least 12 characters"}, errors.New("password must be at least 12 characters")
	}

	logs := []string{fmt.Sprintf("setting SFTP password for user '%s' via chpasswd stdin", p.Username)}

	if dryRun {
		logs = append(logs, fmt.Sprintf("[dry-run] simulated chpasswd update for user '%s'", p.Username))
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username": p.Username,
				"updated":  true,
				"dry_run":  true,
			},
			Logs: logs,
		}, nil
	}

	// Feed credentials strictly via stdin to chpasswd (never as command-line arguments)
	stdinData := strings.NewReader(fmt.Sprintf("%s:%s\n", p.Username, p.Password))
	out, err := runWithStdin(ctx, a.executor, stdinData, "chpasswd")
	if err != nil {
		// Never leak password or stdin in error
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("chpasswd failed: %s", string(out)),
		}, fmt.Errorf("chpasswd failed: %w", err)
	}

	logs = append(logs, fmt.Sprintf("password successfully updated for user '%s'", p.Username))

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username": p.Username,
			"updated":  true,
		},
		Logs: logs,
	}, nil
}

// -----------------------------------------------------------------------------
// sshd.effective Action (Read-only Diagnostic)
// -----------------------------------------------------------------------------

type SshdEffectivePayload struct {
	Username string `json:"username"`
}

type SshdEffectiveAction struct {
	executor commandExecutor
}

func NewSshdEffectiveAction() *SshdEffectiveAction {
	return &SshdEffectiveAction{
		executor: osCommandExecutor{},
	}
}

func NewSshdEffectiveActionForTest(executor commandExecutor) *SshdEffectiveAction {
	return &SshdEffectiveAction{
		executor: executor,
	}
}

func (a *SshdEffectiveAction) Name() string {
	return "sshd.effective"
}

func (a *SshdEffectiveAction) Validate(payload []byte) error {
	var p SshdEffectivePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	return ValidateLinuxUsername(p.Username)
}

func (a *SshdEffectiveAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p SshdEffectivePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if err := ValidateLinuxUsername(p.Username); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	// Check if user exists on Linux
	userExists := false
	if _, err := a.executor.CombinedOutput(ctx, "id", "-u", p.Username); err == nil {
		userExists = true
	}

	// Run sshd -T -C user=<user> to evaluate effective settings
	out, err := a.executor.CombinedOutput(ctx, "sshd", "-T", "-C", fmt.Sprintf("user=%s", p.Username))
	if err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("sshd -T evaluation failed: %s", string(out)),
		}, fmt.Errorf("sshd -T failed: %s: %w", string(out), err)
	}

	// Parse settings
	var passwordAuth = "no"
	var forceCommand = "none"
	var chrootDir = "none"
	var pubkeyAuth = "no"

	scanner := bufio.NewScanner(bytes.NewReader(out))
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		parts := strings.SplitN(line, " ", 2)
		if len(parts) < 2 {
			continue
		}
		key := strings.ToLower(parts[0])
		val := strings.TrimSpace(parts[1])

		switch key {
		case "passwordauthentication":
			passwordAuth = val
		case "forcecommand":
			forceCommand = val
		case "chrootdirectory":
			chrootDir = val
		case "pubkeyauthentication":
			pubkeyAuth = val
		}
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username":                p.Username,
			"user_exists":             userExists,
			"password_authentication": passwordAuth,
			"force_command":           forceCommand,
			"chroot_directory":        chrootDir,
			"pubkey_authentication":   pubkeyAuth,
			"ready_for_sftp":          userExists && passwordAuth == "yes" && strings.HasPrefix(forceCommand, "internal-sftp") && (chrootDir == "none" || chrootDir == ""),
		},
		Logs: []string{
			fmt.Sprintf("evaluated effective sshd configuration for '%s': user_exists=%v, pass_auth=%s, force_command=%s, chroot=%s",
				p.Username, userExists, passwordAuth, forceCommand, chrootDir),
		},
	}, nil
}
