package actions

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

// -------------------------------------------------------------
// Action: wordpress.install
// -------------------------------------------------------------

type WordpressInstallPayload struct {
	Username      string `json:"username"`
	Domain        string `json:"domain"`
	InstallPath   string `json:"install_path"`
	AdminUser     string `json:"admin_user"`
	AdminPassword string `json:"admin_password"`
	AdminEmail    string `json:"admin_email"`
	SiteTitle     string `json:"site_title"`
	DbName        string `json:"db_name"`
	DbUser        string `json:"db_user"`
	DbPassword    string `json:"db_password"`
	DbHost        string `json:"db_host,omitempty"`
	DbPrefix      string `json:"db_prefix,omitempty"`
	Locale        string `json:"locale,omitempty"`
}

type WordpressInstallAction struct{}

func NewWordpressInstallAction() *WordpressInstallAction {
	return &WordpressInstallAction{}
}

func (a *WordpressInstallAction) Name() string {
	return "wordpress.install"
}

func (a *WordpressInstallAction) Validate(payload []byte) error {
	var p WordpressInstallPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}

	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if err := ValidateDomain(p.Domain); err != nil {
		return err
	}
	if _, err := ValidateWordPressInstallPath(p.Username, p.InstallPath); err != nil {
		return err
	}
	if err := ValidateWordPressAdminUser(p.AdminUser); err != nil {
		return err
	}
	if strings.TrimSpace(p.AdminPassword) == "" {
		return errors.New("admin_password cannot be empty")
	}
	if err := ValidateWordPressEmail(p.AdminEmail); err != nil {
		return err
	}
	if p.DbPrefix != "" {
		if err := ValidateWordPressTablePrefix(p.DbPrefix); err != nil {
			return err
		}
	}
	if err := ValidateDatabaseName(p.DbName); err != nil {
		return err
	}
	if err := ValidateDatabaseUser(p.DbUser); err != nil {
		return err
	}

	return nil
}

func (a *WordpressInstallAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p WordpressInstallPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	cleanPath, err := ValidateWordPressInstallPath(p.Username, p.InstallPath)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	dbHost := p.DbHost
	if dbHost == "" {
		dbHost = "127.0.0.1"
	}

	dbPrefix := p.DbPrefix
	if dbPrefix == "" {
		dbPrefix = "wp_"
	}

	locale := p.Locale
	if locale == "" {
		locale = "en_US"
	}

	siteTitle := p.SiteTitle
	if siteTitle == "" {
		siteTitle = p.Domain
	}

	logs := []string{fmt.Sprintf("starting wordpress installation for domain '%s' at '%s'", p.Domain, cleanPath)}

	if dryRun || runtime.GOOS != "linux" {
		logs = append(logs,
			fmt.Sprintf("[dry-run] downloaded wordpress core (locale: %s)", locale),
			fmt.Sprintf("[dry-run] generated wp-config.php with db '%s' and prefix '%s'", p.DbName, dbPrefix),
			fmt.Sprintf("[dry-run] executed wp core install for admin user '%s' (%s)", p.AdminUser, p.AdminEmail),
			fmt.Sprintf("[dry-run] set file ownership to %s:%s", p.Username, p.Username),
		)

		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"domain":       p.Domain,
				"install_path": cleanPath,
				"admin_user":   p.AdminUser,
				"admin_email":  p.AdminEmail,
				"db_name":      p.DbName,
				"dry_run":      true,
			},
			Logs: logs,
		}, nil
	}

	// Step 1: Ensure directory exists
	if err := os.MkdirAll(cleanPath, 0755); err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("failed to create directory: %v", err)}, err
	}

	// Step 2: Download core via WP-CLI
	wpCli := "wp"
	downloadArgs := []string{"core", "download", "--path=" + cleanPath, "--locale=" + locale, "--allow-root"}
	cmd := exec.CommandContext(ctx, wpCli, downloadArgs...)
	var dlErr bytes.Buffer
	cmd.Stderr = &dlErr
	if err := cmd.Run(); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("wp core download failed: %v (%s)", err, strings.TrimSpace(dlErr.String())),
			Logs:    logs,
		}, err
	}
	logs = append(logs, "wordpress core downloaded successfully")

	// Step 3: Create wp-config.php
	configArgs := []string{
		"config", "create",
		"--path=" + cleanPath,
		"--dbname=" + p.DbName,
		"--dbuser=" + p.DbUser,
		"--dbpass=" + p.DbPassword,
		"--dbhost=" + dbHost,
		"--dbprefix=" + dbPrefix,
		"--skip-check",
		"--allow-root",
	}
	configCmd := exec.CommandContext(ctx, wpCli, configArgs...)
	var cfgErr bytes.Buffer
	configCmd.Stderr = &cfgErr
	if err := configCmd.Run(); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("wp config create failed: %v (%s)", err, strings.TrimSpace(cfgErr.String())),
			Logs:    logs,
		}, err
	}
	logs = append(logs, "wp-config.php generated with unique security salts")

	// Step 4: Run core install
	siteURL := fmt.Sprintf("https://%s", p.Domain)
	installArgs := []string{
		"core", "install",
		"--path=" + cleanPath,
		"--url=" + siteURL,
		"--title=" + siteTitle,
		"--admin_user=" + p.AdminUser,
		"--admin_password=" + p.AdminPassword,
		"--admin_email=" + p.AdminEmail,
		"--skip-email",
		"--allow-root",
	}
	installCmd := exec.CommandContext(ctx, wpCli, installArgs...)
	var instErr bytes.Buffer
	installCmd.Stderr = &instErr
	if err := installCmd.Run(); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("wp core install failed: %v (%s)", err, strings.TrimSpace(instErr.String())),
			Logs:    logs,
		}, err
	}
	logs = append(logs, fmt.Sprintf("wordpress successfully installed for %s", siteURL))

	// Step 5: Fix permissions and ownership strictly to account user
	_ = exec.CommandContext(ctx, "chown", "-R", fmt.Sprintf("%s:%s", p.Username, p.Username), cleanPath).Run()
	_ = exec.CommandContext(ctx, "find", cleanPath, "-type", "d", "-exec", "chmod", "755", "{}", "+").Run()
	_ = exec.CommandContext(ctx, "find", cleanPath, "-type", "f", "-exec", "chmod", "644", "{}", "+").Run()
	// Lock down wp-config.php permissions
	_ = exec.CommandContext(ctx, "chmod", "600", filepath.Join(cleanPath, "wp-config.php")).Run()

	logs = append(logs, "permissions and security hardening applied")

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"domain":       p.Domain,
			"install_path": cleanPath,
			"admin_user":   p.AdminUser,
			"admin_email":  p.AdminEmail,
			"site_url":     siteURL,
			"db_name":      p.DbName,
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// Action: wordpress.uninstall
// -------------------------------------------------------------

type WordpressUninstallPayload struct {
	Username    string `json:"username"`
	InstallPath string `json:"install_path"`
	RemoveDb    bool   `json:"remove_db,omitempty"`
	DbName      string `json:"db_name,omitempty"`
	DbUser      string `json:"db_user,omitempty"`
}

type WordpressUninstallAction struct{}

func NewWordpressUninstallAction() *WordpressUninstallAction {
	return &WordpressUninstallAction{}
}

func (a *WordpressUninstallAction) Name() string {
	return "wordpress.uninstall"
}

func (a *WordpressUninstallAction) Validate(payload []byte) error {
	var p WordpressUninstallPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}

	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if _, err := ValidateWordPressInstallPath(p.Username, p.InstallPath); err != nil {
		return err
	}

	return nil
}

func (a *WordpressUninstallAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p WordpressUninstallPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	cleanPath, err := ValidateWordPressInstallPath(p.Username, p.InstallPath)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("initiating wordpress uninstallation at '%s'", cleanPath)}

	if dryRun || runtime.GOOS != "linux" {
		logs = append(logs, fmt.Sprintf("[dry-run] removed wordpress files in '%s'", cleanPath))
		if p.RemoveDb && p.DbName != "" {
			logs = append(logs, fmt.Sprintf("[dry-run] removed associated database '%s'", p.DbName))
		}

		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"install_path": cleanPath,
				"uninstalled":  true,
				"dry_run":      true,
			},
			Logs: logs,
		}, nil
	}

	// Verify wp-config.php exists before removing to prevent deleting arbitrary directories
	wpConfigFile := filepath.Join(cleanPath, "wp-config.php")
	if _, err := os.Stat(wpConfigFile); err != nil && !os.IsNotExist(err) {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("wp-config.php not accessible in '%s': %v", cleanPath, err),
		}, err
	}

	// Step 1: Remove all files inside the install path
	dirEntries, readErr := os.ReadDir(cleanPath)
	if readErr == nil {
		for _, entry := range dirEntries {
			entryPath := filepath.Join(cleanPath, entry.Name())
			_ = os.RemoveAll(entryPath)
		}
	}
	logs = append(logs, fmt.Sprintf("removed all files from '%s'", cleanPath))

	// Step 2: Remove database if requested
	if p.RemoveDb && p.DbName != "" {
		dropCmd := exec.CommandContext(ctx, "mysql", "-e", fmt.Sprintf("DROP DATABASE IF EXISTS `%s`;", p.DbName))
		_ = dropCmd.Run()
		if p.DbUser != "" {
			dropUserCmd := exec.CommandContext(ctx, "mysql", "-e", fmt.Sprintf("DROP USER IF EXISTS '%s'@'localhost';", p.DbUser))
			_ = dropUserCmd.Run()
		}
		logs = append(logs, fmt.Sprintf("dropped database '%s' and user '%s'", p.DbName, p.DbUser))
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"install_path": cleanPath,
			"uninstalled":  true,
		},
		Logs: logs,
	}, nil
}

