package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

type VhostRegenerateItem struct {
	Username            string                   `json:"username"`
	Domain              string                   `json:"domain"`
	ServerNames         string                   `json:"server_names,omitempty"`
	DocumentRoot        string                   `json:"document_root,omitempty"`
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
	IsLaravelPublicRoot bool                     `json:"is_laravel_public_root,omitempty"`
}

type VhostRegenerateAllPayload struct {
	Vhosts []VhostRegenerateItem `json:"vhosts"`
}

type VhostRegenerateAllAction struct {
	vhostAvailableDir string
	vhostEnabledDir   string
	isLinux           bool
}

func NewVhostRegenerateAllAction() *VhostRegenerateAllAction {
	return &VhostRegenerateAllAction{
		vhostAvailableDir: "/etc/nginx/sites-available",
		vhostEnabledDir:   "/etc/nginx/sites-enabled",
		isLinux:           true,
	}
}

func NewVhostRegenerateAllActionForTest(availDir, enabledDir string) *VhostRegenerateAllAction {
	return &VhostRegenerateAllAction{
		vhostAvailableDir: availDir,
		vhostEnabledDir:   enabledDir,
		isLinux:           false,
	}
}

func (a *VhostRegenerateAllAction) Name() string {
	return "vhost.regenerate_all"
}

func (a *VhostRegenerateAllAction) Validate(payload []byte) error {
	var p VhostRegenerateAllPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}

	for _, item := range p.Vhosts {
		if err := ValidateDomain(item.Domain); err != nil {
			return fmt.Errorf("invalid domain '%s': %w", item.Domain, err)
		}
		if item.Username != "" {
			if err := ValidateLinuxUsername(item.Username); err != nil {
				return fmt.Errorf("invalid username '%s' for domain '%s': %w", item.Username, item.Domain, err)
			}
		}
	}

	return nil
}

func (a *VhostRegenerateAllAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p VhostRegenerateAllPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{
		fmt.Sprintf("regenerating %d vhosts with standard ACME and HTTP challenge layout", len(p.Vhosts)),
	}

	if dryRun {
		var domains []string
		for _, v := range p.Vhosts {
			domains = append(domains, v.Domain)
		}
		logs = append(logs, fmt.Sprintf("[dry-run] simulated regeneration of vhosts: %v", domains))
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"dry_run":           true,
				"regenerated_count": len(domains),
				"domains":           domains,
			},
			Logs: logs,
		}, nil
	}

	if err := EnsureDefaultVhost(a.vhostAvailableDir, a.vhostEnabledDir); err != nil {
		logs = append(logs, fmt.Sprintf("warning: could not ensure default vhost: %v", err))
	}
	if err := EnsureAcmeDirectories(); err != nil {
		logs = append(logs, fmt.Sprintf("warning: could not ensure acme directories: %v", err))
	}

	// Map of original file to backup file
	backups := make(map[string]string)
	createdFiles := make([]string, 0)
	var regeneratedDomains []string

	rollback := func() {
		logs = append(logs, "rolling back all regenerated vhosts to backups...")
		for orig, bak := range backups {
			_ = copyFile(bak, orig)
			_ = os.Remove(bak)
			// Restore symlink
			baseName := filepath.Base(orig)
			enabledLink := filepath.Join(a.vhostEnabledDir, baseName)
			_ = os.Remove(enabledLink)
			_ = os.Symlink(orig, enabledLink)
		}
		for _, created := range createdFiles {
			if _, hadBackup := backups[created]; !hadBackup {
				_ = os.Remove(created)
				baseName := filepath.Base(created)
				_ = os.Remove(filepath.Join(a.vhostEnabledDir, baseName))
			}
		}
	}

	for _, item := range p.Vhosts {
		availPath := filepath.Join(a.vhostAvailableDir, fmt.Sprintf("%s.conf", item.Domain))
		enabledPath := filepath.Join(a.vhostEnabledDir, fmt.Sprintf("%s.conf", item.Domain))

		customBlock := ""
		if existingBytes, err := os.ReadFile(availPath); err == nil {
			// Extract custom block
			if extracted, ok := ExtractCustomBlock(string(existingBytes)); ok {
				customBlock = extracted
			}
			// Create backup
			bakPath := availPath + ".bak"
			if err := copyFile(availPath, bakPath); err == nil {
				backups[availPath] = bakPath
			}
		}

		data := VhostTemplateData{
			Username:            item.Username,
			Domain:              item.Domain,
			ServerNames:         item.ServerNames,
			DocumentRoot:        item.DocumentRoot,
			AppProfile:          item.AppProfile,
			PHPVersion:          item.PHPVersion,
			ForceHTTPS:          item.ForceHTTPS,
			SSLEnabled:          item.SSLEnabled,
			SSLCertPath:         item.SSLCertPath,
			SSLKeyPath:          item.SSLKeyPath,
			HstsEnabled:         item.HstsEnabled,
			WwwRedirect:         item.WwwRedirect,
			Redirects:           item.Redirects,
			ProtectedDirs:       item.ProtectedDirs,
			IsLaravelPublicRoot: item.IsLaravelPublicRoot,
			CustomBlock:         customBlock,
		}

		rendered, err := RenderVhostConfig(data)
		if err != nil {
			rollback()
			errStr := fmt.Sprintf("failed to render vhost for %s: %v", item.Domain, err)
			return &ActionResult{Success: false, Error: errStr, Logs: logs}, fmt.Errorf(errStr)
		}

		if err := WriteConfigFileAtomically(availPath, []byte(rendered), 0640); err != nil {
			rollback()
			errStr := fmt.Sprintf("failed to write vhost for %s: %v", item.Domain, err)
			return &ActionResult{Success: false, Error: errStr, Logs: logs}, fmt.Errorf(errStr)
		}
		createdFiles = append(createdFiles, availPath)

		// Ensure symlink
		_ = os.Remove(enabledPath)
		_ = os.Symlink(availPath, enabledPath)

		regeneratedDomains = append(regeneratedDomains, item.Domain)
	}

	// Run nginx -t once at the end
	if a.isLinux {
		if _, err := exec.LookPath("nginx"); err == nil {
			cmdTest := exec.CommandContext(ctx, "nginx", "-t")
			if out, err := cmdTest.CombinedOutput(); err != nil {
				rollback()
				errStr := fmt.Sprintf("nginx syntax test failed: %s", strings.TrimSpace(string(out)))
				logs = append(logs, errStr)
				return &ActionResult{
					Success: false,
					Error:   errStr,
					Logs:    logs,
				}, fmt.Errorf(errStr)
			}
		}

		// Reload Nginx
		if _, err := exec.LookPath("systemctl"); err == nil {
			_ = exec.CommandContext(ctx, "systemctl", "reload", "nginx").Run()
		} else if _, err := exec.LookPath("nginx"); err == nil {
			_ = exec.CommandContext(ctx, "nginx", "-s", "reload").Run()
		}
	}

	// Clean up all backups on success
	for _, bak := range backups {
		_ = os.Remove(bak)
	}

	logs = append(logs, fmt.Sprintf("successfully regenerated %d vhosts and reloaded nginx", len(regeneratedDomains)))

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"regenerated_count": len(regeneratedDomains),
			"domains":           regeneratedDomains,
		},
		Logs: logs,
	}, nil
}
