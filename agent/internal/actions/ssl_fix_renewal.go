package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

type SslFixRenewalPayload struct {
	RenewalDir string `json:"renewal_dir,omitempty"`
	HooksDir   string `json:"hooks_dir,omitempty"`
	WebrootDir string `json:"webroot_dir,omitempty"`
}

type SslFixRenewalAction struct {
	defaultRenewalDir string
	defaultHooksDir   string
	defaultWebrootDir string
}

func NewSslFixRenewalAction() *SslFixRenewalAction {
	return &SslFixRenewalAction{
		defaultRenewalDir: "/etc/letsencrypt/renewal",
		defaultHooksDir:   "/etc/letsencrypt/renewal-hooks/deploy",
		defaultWebrootDir: "/var/www/_acme",
	}
}

func NewSslFixRenewalActionForTest(renewalDir, hooksDir, webrootDir string) *SslFixRenewalAction {
	return &SslFixRenewalAction{
		defaultRenewalDir: renewalDir,
		defaultHooksDir:   hooksDir,
		defaultWebrootDir: webrootDir,
	}
}

func (a *SslFixRenewalAction) Name() string {
	return "ssl.fix_renewal"
}

func (a *SslFixRenewalAction) Validate(payload []byte) error {
	var p SslFixRenewalPayload
	if len(payload) > 0 {
		if err := json.Unmarshal(payload, &p); err != nil {
			return fmt.Errorf("malformed payload: %w", err)
		}
	}
	return nil
}

// RewriteRenewalConfig updates a single certbot renewal configuration content to use the specified webroot.
func RewriteRenewalConfig(content, webrootDir string) string {
	lines := strings.Split(content, "\n")
	var result []string
	inRenewalParams := false
	inWebrootMap := false
	hasAuthenticator := false
	hasWebrootPath := false

	reAuth := regexp.MustCompile(`^\s*authenticator\s*=`)
	reWebroot := regexp.MustCompile(`^\s*webroot_path\s*=`)
	reMapEntry := regexp.MustCompile(`^\s*([a-zA-Z0-9_\-\.]+)\s*=\s*(.*)$`)

	for _, line := range lines {
		trimmed := strings.TrimSpace(line)

		if strings.HasPrefix(trimmed, "[") && strings.HasSuffix(trimmed, "]") {
			if inRenewalParams && !hasAuthenticator {
				result = append(result, "authenticator = webroot")
				hasAuthenticator = true
			}
			if inRenewalParams && !hasWebrootPath {
				result = append(result, fmt.Sprintf("webroot_path = %s,", webrootDir))
				hasWebrootPath = true
			}

			if trimmed == "[renewalparams]" {
				inRenewalParams = true
				inWebrootMap = false
			} else if trimmed == "[[webroot_map]]" {
				inRenewalParams = false
				inWebrootMap = true
			} else {
				inRenewalParams = false
				inWebrootMap = false
			}
			result = append(result, line)
			continue
		}

		if inRenewalParams {
			if reAuth.MatchString(trimmed) {
				result = append(result, "authenticator = webroot")
				hasAuthenticator = true
				continue
			}
			if reWebroot.MatchString(trimmed) {
				result = append(result, fmt.Sprintf("webroot_path = %s,", webrootDir))
				hasWebrootPath = true
				continue
			}
		}

		if inWebrootMap {
			if m := reMapEntry.FindStringSubmatch(trimmed); len(m) > 1 {
				domainKey := m[1]
				result = append(result, fmt.Sprintf("%s = %s", domainKey, webrootDir))
				continue
			}
		}

		result = append(result, line)
	}

	if inRenewalParams {
		if !hasAuthenticator {
			result = append(result, "authenticator = webroot")
		}
		if !hasWebrootPath {
			result = append(result, fmt.Sprintf("webroot_path = %s,", webrootDir))
		}
	}

	return strings.Join(result, "\n")
}

func (a *SslFixRenewalAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p SslFixRenewalPayload
	if len(payload) > 0 {
		_ = json.Unmarshal(payload, &p)
	}

	renewalDir := a.defaultRenewalDir
	if p.RenewalDir != "" {
		renewalDir = p.RenewalDir
	}

	hooksDir := a.defaultHooksDir
	if p.HooksDir != "" {
		hooksDir = p.HooksDir
	}

	webrootDir := a.defaultWebrootDir
	if p.WebrootDir != "" {
		webrootDir = p.WebrootDir
	}

	logs := []string{
		fmt.Sprintf("fixing certbot renewal configurations in %s to use %s", renewalDir, webrootDir),
	}

	// 1. Ensure deploy hook exists
	hookScript := filepath.Join(hooksDir, "01-hpanel-nginx-reload.sh")
	hookContent := `#!/bin/sh
# HPanel automated Nginx reload hook after SSL certificate renewal
systemctl reload nginx 2>/dev/null || nginx -s reload 2>/dev/null || true
`

	if dryRun {
		logs = append(logs,
			fmt.Sprintf("[dry-run] would ensure deploy hook %s", hookScript),
			"[dry-run] simulated renewal configs rewrite",
		)
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"dry_run":    true,
				"hook_path":  hookScript,
				"webroot":    webrootDir,
				"renewal_dir": renewalDir,
			},
			Logs: logs,
		}, nil
	}

	if err := os.MkdirAll(hooksDir, 0755); err != nil {
		logs = append(logs, fmt.Sprintf("warning: failed to create hooks dir %s: %v", hooksDir, err))
	} else {
		if err := os.WriteFile(hookScript, []byte(hookContent), 0755); err != nil {
			logs = append(logs, fmt.Sprintf("warning: failed to write deploy hook %s: %v", hookScript, err))
		} else {
			_ = os.Chmod(hookScript, 0755)
			logs = append(logs, fmt.Sprintf("configured deploy hook %s", hookScript))
		}
	}

	// 2. Scan and rewrite renewal configs
	files, err := filepath.Glob(filepath.Join(renewalDir, "*.conf"))
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	var updatedFiles []string
	for _, file := range files {
		contentBytes, err := os.ReadFile(file)
		if err != nil {
			logs = append(logs, fmt.Sprintf("warning: failed to read %s: %v", file, err))
			continue
		}

		rewritten := RewriteRenewalConfig(string(contentBytes), webrootDir)
		if rewritten != string(contentBytes) {
			if err := atomicWriteFile(file, []byte(rewritten), 0644, -1, -1); err != nil {
				logs = append(logs, fmt.Sprintf("error: failed to write %s: %v", file, err))
			} else {
				updatedFiles = append(updatedFiles, filepath.Base(file))
				logs = append(logs, fmt.Sprintf("rewrote renewal config for %s", filepath.Base(file)))
			}
		} else {
			logs = append(logs, fmt.Sprintf("renewal config %s already up to date", filepath.Base(file)))
		}
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"hook_path":      hookScript,
			"updated_count":  len(updatedFiles),
			"updated_files":  updatedFiles,
			"webroot":        webrootDir,
		},
		Logs: logs,
	}, nil
}
