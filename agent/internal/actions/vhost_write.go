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
	"strings"
)

type VhostWritePayload struct {
	Username            string         `json:"username"`
	Domain              string         `json:"domain"`
	Aliases             []string       `json:"aliases,omitempty"`
	DocumentRoot        string         `json:"document_root"`
	AppProfile          string         `json:"app_profile,omitempty"`
	PHPVersion          string         `json:"php_version,omitempty"`
	ForceHTTPS          bool           `json:"force_https,omitempty"`
	SSLEnabled          bool           `json:"ssl_enabled,omitempty"`
	SSLCertPath         string         `json:"ssl_cert_path,omitempty"`
	SSLKeyPath          string         `json:"ssl_key_path,omitempty"`
	HstsEnabled         bool           `json:"hsts_enabled,omitempty"`
	WwwRedirect         string         `json:"www_redirect,omitempty"`
	Redirects           []RedirectRule           `json:"redirects,omitempty"`
	ProtectedDirs       []ProtectedDirectoryRule `json:"protected_dirs,omitempty"`
	MaxWebsites         int                      `json:"max_websites,omitempty"`
	CurrentWebsiteCount int            `json:"current_website_count,omitempty"`
}

type VhostWriteAction struct{}

func NewVhostWriteAction() *VhostWriteAction {
	return &VhostWriteAction{}
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

	serverNamesList := []string{p.Domain}
	serverNamesList = append(serverNamesList, p.Aliases...)
	serverNames := strings.Join(serverNamesList, " ")

	vhostData := VhostTemplateData{
		Username:     p.Username,
		Domain:       p.Domain,
		ServerNames:  serverNames,
		DocumentRoot: p.DocumentRoot,
		AppProfile:   p.AppProfile,
		PHPVersion:   p.PHPVersion,
		ForceHTTPS:   p.ForceHTTPS,
		SSLEnabled:   p.SSLEnabled,
		SSLCertPath:  p.SSLCertPath,
		SSLKeyPath:   p.SSLKeyPath,
		HstsEnabled:  p.HstsEnabled,
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
			fmt.Sprintf("[dry-run] simulated atomic move to /etc/nginx/sites-available/%s.conf", p.Domain),
			fmt.Sprintf("[dry-run] simulated symlink to /etc/nginx/sites-enabled/%s.conf and reload", p.Domain),
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
	tempDir := "/tmp/panel-agent"
	_ = os.MkdirAll(tempDir, 0700)
	tempFile := filepath.Join(tempDir, fmt.Sprintf("vhost-%s.conf", p.Domain))

	if err := os.WriteFile(tempFile, []byte(configContent), 0640); err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("writing temp vhost: %v", err), Logs: logs}, err
	}

	availPath := fmt.Sprintf("/etc/nginx/sites-available/%s.conf", p.Domain)
	enabledPath := fmt.Sprintf("/etc/nginx/sites-enabled/%s.conf", p.Domain)
	backupPath := availPath + ".bak"

	if runtime.GOOS == "linux" {
		// Run syntax test with nginx -t
		if _, err := exec.LookPath("nginx"); err == nil {
			testCmd := exec.CommandContext(ctx, "nginx", "-t")
			if out, err := testCmd.CombinedOutput(); err != nil {
				_ = os.Remove(tempFile)
				return &ActionResult{
					Success: false,
					Error:   fmt.Sprintf("nginx syntax test failed: %s", strings.TrimSpace(string(out))),
					Logs:    logs,
				}, fmt.Errorf("nginx test failed: %s", string(out))
			}
		}

		// Backup previous configuration if present
		if _, err := os.Stat(availPath); err == nil {
			_ = copyFile(availPath, backupPath)
		}

		// Atomically install new config
		_ = os.MkdirAll(filepath.Dir(availPath), 0755)
		_ = os.MkdirAll(filepath.Dir(enabledPath), 0755)

		if err := os.Rename(tempFile, availPath); err != nil {
			return &ActionResult{Success: false, Error: fmt.Sprintf("failed to move config: %v", err), Logs: logs}, err
		}

		// Symlink to sites-enabled
		_ = os.Remove(enabledPath) // remove existing link if any
		if err := os.Symlink(availPath, enabledPath); err != nil {
			// Rollback
			if _, statErr := os.Stat(backupPath); statErr == nil {
				_ = os.Rename(backupPath, availPath)
			}
			return &ActionResult{Success: false, Error: fmt.Sprintf("failed to symlink: %v", err), Logs: logs}, err
		}

		// Reload Nginx
		reloadCmd := exec.CommandContext(ctx, "systemctl", "reload", "nginx")
		if out, err := reloadCmd.CombinedOutput(); err != nil {
			// Rollback on reload failure
			logs = append(logs, fmt.Sprintf("reload failed (%v), restoring backup...", err))
			if _, statErr := os.Stat(backupPath); statErr == nil {
				_ = os.Rename(backupPath, availPath)
				_ = exec.CommandContext(ctx, "systemctl", "reload", "nginx").Run()
			}
			return &ActionResult{
				Success: false,
				Error:   fmt.Sprintf("nginx reload failed: %s", strings.TrimSpace(string(out))),
				Logs:    logs,
			}, err
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

