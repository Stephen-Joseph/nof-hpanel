package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"strings"
)

type VhostWritePayload struct {
	Username            string                   `json:"username"`
	Domain              string                   `json:"domain"`
	Aliases             []string                 `json:"aliases,omitempty"`
	DocumentRoot        string                   `json:"document_root"`
	AppProfile          string                   `json:"app_profile,omitempty"`
	PHPVersion          string                   `json:"php_version,omitempty"`
	ForceHTTPS          bool                     `json:"force_https,omitempty"`
	SSLEnabled          bool                     `json:"ssl_enabled,omitempty"`
	SSLCertPath         string                   `json:"ssl_cert_path,omitempty"`
	SSLKeyPath          string                   `json:"ssl_key_path,omitempty"`
	HstsEnabled         bool                     `json:"hsts_enabled,omitempty"`
	WwwRedirect         string                   `json:"www_redirect,omitempty"`
	Redirects           []RedirectRule           `json:"redirects,omitempty"`
	ProtectedDirs       []ProtectedDirectoryRule `json:"protected_dirs,omitempty"`
	MaxWebsites         int                      `json:"max_websites,omitempty"`
	CurrentWebsiteCount int                      `json:"current_website_count,omitempty"`
}

type VhostWriteAction struct {
	executor          commandExecutor
	vhostAvailableDir string
	vhostEnabledDir   string
	isLinux           bool
}

func NewVhostWriteAction() *VhostWriteAction {
	return &VhostWriteAction{
		executor:          osCommandExecutor{},
		vhostAvailableDir: "/etc/nginx/sites-available",
		vhostEnabledDir:   "/etc/nginx/sites-enabled",
		isLinux:           runtime.GOOS == "linux",
	}
}

func NewVhostWriteActionForTest(executor commandExecutor, vhostAvailableDir, vhostEnabledDir string) *VhostWriteAction {
	return &VhostWriteAction{
		executor:          executor,
		vhostAvailableDir: vhostAvailableDir,
		vhostEnabledDir:   vhostEnabledDir,
		isLinux:           true,
	}
}

// EnsureDefaultVhost ensures that the default_server catch-all block exists in
// sites-available/00-default-deny.conf and is symlinked into sites-enabled/00-default-deny.conf.
func EnsureDefaultVhost(vhostAvailableDir, vhostEnabledDir string) error {
	defaultAvail := filepath.Join(vhostAvailableDir, "00-default-deny.conf")
	defaultEnabled := filepath.Join(vhostEnabledDir, "00-default-deny.conf")

	if err := os.MkdirAll(vhostAvailableDir, 0755); err != nil {
		return fmt.Errorf("creating vhost available directory: %w", err)
	}
	if err := os.MkdirAll(vhostEnabledDir, 0755); err != nil {
		return fmt.Errorf("creating vhost enabled directory: %w", err)
	}

	// Remove default Debian/Ubuntu placeholder link if present to avoid duplicate default_server conflicts
	debianDefaultLink := filepath.Join(vhostEnabledDir, "default")
	if info, err := os.Lstat(debianDefaultLink); err == nil && (info.Mode()&os.ModeSymlink != 0) {
		_ = os.Remove(debianDefaultLink)
	}

	// Clean up legacy 00-default.conf if present
	_ = os.Remove(filepath.Join(vhostEnabledDir, "00-default.conf"))
	_ = os.Remove(filepath.Join(vhostAvailableDir, "00-default.conf"))

	if _, err := os.Stat(defaultAvail); os.IsNotExist(err) {
		if err := atomicWriteFile(defaultAvail, []byte(RenderDefaultVhostConfig()), 0640, -1, -1); err != nil {
			return fmt.Errorf("committing default vhost config: %w", err)
		}
	}

	if info, err := os.Lstat(defaultEnabled); err == nil {
		if info.Mode()&os.ModeSymlink != 0 {
			return nil
		}
		return fmt.Errorf("default vhost path exists and is not a symlink: %s", defaultEnabled)
	} else if os.IsNotExist(err) {
		if err := os.Symlink(defaultAvail, defaultEnabled); err != nil {
			return fmt.Errorf("symlinking default vhost: %w", err)
		}
	} else {
		return fmt.Errorf("checking default vhost symlink: %w", err)
	}

	return nil
}

func (a *VhostWriteAction) Name() string {
	return "vhost.write"
}

func (a *VhostWriteAction) Validate(payload []byte) error {
	var p VhostWritePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}

	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if err := ValidateDomain(p.Domain); err != nil {
		return err
	}
	for _, alias := range p.Aliases {
		if err := ValidateDomain(alias); err != nil {
			return fmt.Errorf("invalid alias: %w", err)
		}
	}
	if err := ValidateDocumentRoot(p.Username, p.DocumentRoot); err != nil {
		return err
	}
	if err := ValidateAppProfile(p.AppProfile); err != nil {
		return err
	}
	if err := ValidateWwwRedirect(p.WwwRedirect); err != nil {
		return err
	}
	if p.PHPVersion != "" {
		if err := ValidatePHPVersion(p.PHPVersion); err != nil {
			return err
		}
	}

	// Enforce package website quota
	if p.MaxWebsites > 0 && p.CurrentWebsiteCount > p.MaxWebsites {
		return fmt.Errorf("package quota exceeded: current websites (%d) exceeds max allowed (%d)", p.CurrentWebsiteCount, p.MaxWebsites)
	}

	return nil
}

func (a *VhostWriteAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p VhostWritePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	if p.PHPVersion == "" {
		p.PHPVersion = "8.3"
	}
	if p.AppProfile == "" {
		p.AppProfile = "php-generic"
	}

	if p.DocumentRoot == "" && p.Username != "" {
		p.DocumentRoot = fmt.Sprintf("/home/%s/public_html", p.Username)
	}

	bareDomain := strings.TrimPrefix(p.Domain, "www.")
	wwwDomain := "www." + bareDomain
	var serverNamesList []string
	switch p.WwwRedirect {
	case "force_www":
		serverNamesList = []string{wwwDomain}
	case "strip_www":
		serverNamesList = []string{bareDomain}
	default:
		serverNamesList = []string{bareDomain, wwwDomain}
	}
	for _, alias := range p.Aliases {
		alias = strings.TrimSpace(alias)
		if alias != "" && alias != bareDomain && alias != wwwDomain {
			serverNamesList = append(serverNamesList, alias)
		}
	}
	serverNames := strings.Join(serverNamesList, " ")

	vhostData := VhostTemplateData{
		Username:      p.Username,
		Domain:        p.Domain,
		ServerNames:   serverNames,
		DocumentRoot:  p.DocumentRoot,
		AppProfile:    p.AppProfile,
		PHPVersion:    p.PHPVersion,
		ForceHTTPS:    p.ForceHTTPS,
		SSLEnabled:    p.SSLEnabled,
		SSLCertPath:   p.SSLCertPath,
		SSLKeyPath:    p.SSLKeyPath,
		HstsEnabled:   p.HstsEnabled,
		WwwRedirect:   p.WwwRedirect,
		Redirects:     p.Redirects,
		ProtectedDirs: p.ProtectedDirs,
	}

	configContent, err := RenderVhostConfig(vhostData)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{
		fmt.Sprintf("rendering Nginx vhost for domain '%s' (profile: %s, php: %s)", p.Domain, p.AppProfile, p.PHPVersion),
	}

	if dryRun {
		logs = append(logs,
			fmt.Sprintf("[dry-run] simulated syntax validation for %s", p.Domain),
			fmt.Sprintf("[dry-run] simulated atomic move to %s/%s.conf", a.vhostAvailableDir, p.Domain),
			fmt.Sprintf("[dry-run] simulated symlink to %s/%s.conf and reload", a.vhostEnabledDir, p.Domain),
		)
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"domain":        p.Domain,
				"app_profile":   p.AppProfile,
				"document_root": p.DocumentRoot,
				"config_bytes":  len(configContent),
				"dry_run":       true,
			},
			Logs: logs,
		}, nil
	}

	// Real Linux Nginx deployment
	availPath := filepath.Join(a.vhostAvailableDir, fmt.Sprintf("%s.conf", p.Domain))
	enabledPath := filepath.Join(a.vhostEnabledDir, fmt.Sprintf("%s.conf", p.Domain))
	backupPath := availPath + ".bak"

	_ = os.MkdirAll(a.vhostAvailableDir, 0755)
	_ = os.MkdirAll(a.vhostEnabledDir, 0755)

	// Ensure default catch-all exists
	if err := EnsureDefaultVhost(a.vhostAvailableDir, a.vhostEnabledDir); err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("ensuring default vhost: %v", err), Logs: logs}, err
	}

	tempFile, err := CreateConfigTemp(availPath, []byte(configContent), 0640)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("writing temp vhost: %v", err), Logs: logs}, err
	}
	defer os.Remove(tempFile)

	if a.isLinux {
		hadPreviousConfig := false
		if _, err := os.Stat(availPath); err == nil {
			hadPreviousConfig = true
			_ = copyFile(availPath, backupPath)
		}

		// Atomically install new config into sites-available
		if err := CommitConfigFile(tempFile, availPath, 0640); err != nil {
			return &ActionResult{Success: false, Error: fmt.Sprintf("failed to move config: %v", err), Logs: logs}, err
		}

		// Symlink to sites-enabled
		_ = os.Remove(enabledPath) // remove existing link if any
		if err := os.Symlink(availPath, enabledPath); err != nil {
			// Rollback
			if hadPreviousConfig {
				_ = CommitConfigFile(backupPath, availPath, 0640)
				_ = os.Remove(backupPath)
			} else {
				_ = os.Remove(availPath)
			}
			return &ActionResult{Success: false, Error: fmt.Sprintf("failed to symlink: %v", err), Logs: logs}, err
		}

		// Run syntax test with nginx -t ONLY AFTER the file is linked into sites-enabled
		if _, err := a.executor.LookPath("nginx"); err == nil {
			out, err := a.executor.CombinedOutput(ctx, "nginx", "-t")
			if err != nil {
				// Rollback on syntax test failure
				logs = append(logs, fmt.Sprintf("nginx -t failed (%v), rolling back...", err))
				_ = os.Remove(enabledPath)
				if hadPreviousConfig {
					_ = CommitConfigFile(backupPath, availPath, 0640)
					_ = os.Remove(backupPath)
					// restore old link
					_ = os.Symlink(availPath, enabledPath)
				} else {
					_ = os.Remove(availPath)
				}
				return &ActionResult{
					Success: false,
					Error:   fmt.Sprintf("nginx syntax test failed: %s", strings.TrimSpace(string(out))),
					Logs:    logs,
				}, fmt.Errorf("nginx test failed: %s", string(out))
			}
		}

		// Reload Nginx only after syntax test passed with the file linked in sites-enabled
		if _, err := a.executor.LookPath("systemctl"); err == nil {
			if err := a.executor.Run(ctx, "systemctl", "reload", "nginx"); err != nil {
				// Rollback on reload failure
				logs = append(logs, fmt.Sprintf("reload failed (%v), restoring backup...", err))
				_ = os.Remove(enabledPath)
				if hadPreviousConfig {
					_ = CommitConfigFile(backupPath, availPath, 0640)
					_ = os.Remove(backupPath)
					_ = os.Symlink(availPath, enabledPath)
					_ = a.executor.Run(ctx, "systemctl", "reload", "nginx")
				} else {
					_ = os.Remove(availPath)
				}
				return &ActionResult{
					Success: false,
					Error:   fmt.Sprintf("nginx reload failed: %v", err),
					Logs:    logs,
				}, err
			}
		}

		_ = os.Remove(backupPath)
		logs = append(logs, fmt.Sprintf("successfully deployed vhost for %s and reloaded Nginx", p.Domain))
	} else {
		logs = append(logs, fmt.Sprintf("simulated atomic deployment to %s on %s", availPath, runtime.GOOS))
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"domain":        p.Domain,
			"app_profile":   p.AppProfile,
			"document_root": p.DocumentRoot,
			"php_version":   p.PHPVersion,
			"dry_run":       false,
		},
		Logs: logs,
	}, nil
}

func copyFile(src, dst string) error {
	data, err := os.ReadFile(src)
	if err != nil {
		return err
	}
	return os.WriteFile(dst, data, 0640)
}
