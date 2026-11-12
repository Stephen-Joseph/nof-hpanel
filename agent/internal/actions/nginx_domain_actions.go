package actions

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"sort"
	"strings"
	"time"
)

const defaultConfigBackupDir = "/var/lib/panel-agent/config-backups"

var homePathMentionRegex = regexp.MustCompile(`/home/([a-z0-9_-]+)`)

type NginxTaggedLine struct {
	Line   int    `json:"line"`
	Text   string `json:"text"`
	Locked bool   `json:"locked"`
}

// ExtractCustomBlock returns the text strictly between CustomRegionStart and CustomRegionEnd.
func ExtractCustomBlock(content string) (string, bool) {
	lines := strings.Split(content, "\n")
	startIdx := -1
	endIdx := -1
	for i, l := range lines {
		trimmed := strings.TrimSpace(l)
		if trimmed == CustomRegionStart && startIdx == -1 {
			startIdx = i
		} else if trimmed == CustomRegionEnd && startIdx != -1 {
			endIdx = i
			break
		}
	}
	if startIdx == -1 || endIdx == -1 || endIdx <= startIdx {
		return "", false
	}
	return strings.Join(lines[startIdx+1:endIdx], "\n"), true
}

// ReplaceCustomBlock replaces the lines between CustomRegionStart and CustomRegionEnd.
func ReplaceCustomBlock(content string, newCustom string) (string, error) {
	lines := strings.Split(content, "\n")
	startIdx := -1
	endIdx := -1
	for i, l := range lines {
		trimmed := strings.TrimSpace(l)
		if trimmed == CustomRegionStart && startIdx == -1 {
			startIdx = i
		} else if trimmed == CustomRegionEnd && startIdx != -1 {
			endIdx = i
			break
		}
	}
	if startIdx == -1 || endIdx == -1 || endIdx <= startIdx {
		return "", errors.New("vhost file does not contain editable custom region markers")
	}

	var out []string
	out = append(out, lines[:startIdx+1]...)
	if strings.TrimSpace(newCustom) != "" {
		out = append(out, strings.TrimRight(newCustom, "\n"))
	}
	out = append(out, lines[endIdx:]...)
	return strings.Join(out, "\n"), nil
}

// TagNginxLines splits content into lines, tagging lines between CustomRegionStart and CustomRegionEnd as editable.
func TagNginxLines(content string) ([]NginxTaggedLine, string, bool) {
	lines := strings.Split(content, "\n")
	tagged := make([]NginxTaggedLine, 0, len(lines))
	inCustom := false
	hasMarkers := false
	var customLines []string

	for i, l := range lines {
		trimmed := strings.TrimSpace(l)
		if trimmed == CustomRegionStart {
			hasMarkers = true
			tagged = append(tagged, NginxTaggedLine{Line: i + 1, Text: l, Locked: true})
			inCustom = true
			continue
		}
		if trimmed == CustomRegionEnd && inCustom {
			inCustom = false
			tagged = append(tagged, NginxTaggedLine{Line: i + 1, Text: l, Locked: true})
			continue
		}
		if inCustom {
			customLines = append(customLines, l)
			tagged = append(tagged, NginxTaggedLine{Line: i + 1, Text: l, Locked: false})
		} else {
			tagged = append(tagged, NginxTaggedLine{Line: i + 1, Text: l, Locked: true})
		}
	}

	return tagged, strings.Join(customLines, "\n"), hasMarkers
}

func isProtectedNginxDomain(domain string) bool {
	d := strings.ToLower(strings.TrimSpace(domain))
	if d == "" || d == "00-default-deny" || d == "00-default-deny.conf" || d == "default" || d == "nginx" {
		return true
	}
	panelDomain := strings.ToLower(strings.TrimSpace(os.Getenv("PANEL_DOMAIN")))
	if panelDomain != "" && d == panelDomain {
		return true
	}
	return false
}

func listDomainBackups(backupDir, domain string) []string {
	entries, err := os.ReadDir(backupDir)
	if err != nil {
		return nil
	}
	prefix := domain + "."
	type bItem struct {
		name  string
		mtime time.Time
	}
	var items []bItem
	for _, e := range entries {
		if e.IsDir() || !strings.HasPrefix(e.Name(), prefix) {
			continue
		}
		if info, err := e.Info(); err == nil {
			items = append(items, bItem{name: e.Name(), mtime: info.ModTime()})
		}
	}
	sort.Slice(items, func(i, j int) bool {
		return items[i].mtime.After(items[j].mtime)
	})
	out := make([]string, 0, len(items))
	for _, it := range items {
		out = append(out, it.name)
	}
	return out
}

func backupDomainConfig(availPath, backupDir, domain string) (string, error) {
	if _, err := os.Stat(availPath); err != nil {
		return "", nil
	}
	if err := os.MkdirAll(backupDir, 0750); err != nil {
		return "", err
	}
	data, err := os.ReadFile(availPath)
	if err != nil {
		return "", err
	}
	backupID := fmt.Sprintf("%s.%s", domain, time.Now().UTC().Format("20060102T150405.000000Z"))
	backupPath := filepath.Join(backupDir, backupID)
	if err := atomicWriteFile(backupPath, data, 0640, -1, -1); err != nil {
		return "", err
	}
	pruneBackups(backupDir, domain)
	return backupID, nil
}

func validateAdvancedModeContent(content string, username string) error {
	if strings.Contains(content, "\r") {
		return errors.New("carriage returns are not permitted")
	}
	if len(content) > maxConfigSizeBytes {
		return errors.New("configuration exceeds maximum allowed size")
	}
	if username != "" {
		matches := homePathMentionRegex.FindAllStringSubmatch(content, -1)
		for _, m := range matches {
			if len(m) > 1 && m[1] != username {
				return fmt.Errorf("advanced mode edit cannot reference another account's home directory (/home/%s)", m[1])
			}
		}
	}
	return nil
}

func applyAndTestNginxDomainConfig(ctx context.Context, executor commandExecutor, availPath string, newContent []byte, backupPath string, isLinux bool) (string, error) {
	tempFile, err := CreateConfigTemp(availPath, newContent, 0640)
	if err != nil {
		return "", fmt.Errorf("creating temp config: %w", err)
	}
	defer os.Remove(tempFile)

	if err := CommitConfigFile(tempFile, availPath, 0640); err != nil {
		return "", fmt.Errorf("committing config: %w", err)
	}

	if !isLinux {
		return "simulated nginx -t: syntax is ok", nil
	}

	var testOutput string
	if _, err := executor.LookPath("nginx"); err == nil {
		out, err := executor.CombinedOutput(ctx, "nginx", "-t")
		testOutput = strings.TrimSpace(string(out))
		if err != nil {
			if backupPath != "" {
				if prevData, rErr := os.ReadFile(backupPath); rErr == nil {
					if rbTmp, tErr := CreateConfigTemp(availPath, prevData, 0640); tErr == nil {
						_ = CommitConfigFile(rbTmp, availPath, 0640)
						_ = os.Remove(rbTmp)
					}
				}
			}
			return testOutput, fmt.Errorf("nginx -t failed: %s", testOutput)
		}
	}

	if _, err := executor.LookPath("systemctl"); err == nil {
		if err := executor.Run(ctx, "systemctl", "reload", "nginx"); err != nil {
			if backupPath != "" {
				if prevData, rErr := os.ReadFile(backupPath); rErr == nil {
					if rbTmp, tErr := CreateConfigTemp(availPath, prevData, 0640); tErr == nil {
						_ = CommitConfigFile(rbTmp, availPath, 0640)
						_ = os.Remove(rbTmp)
					}
				}
			}
			return testOutput, fmt.Errorf("nginx reload failed: %w", err)
		}
	}

	return testOutput, nil
}

// ============================================================================
// 1. nginx.domain.read
// ============================================================================

type NginxDomainReadPayload struct {
	Domain string `json:"domain"`
}

type NginxDomainReadAction struct {
	vhostAvailableDir string
	backupDir         string
}

func NewNginxDomainReadAction() *NginxDomainReadAction {
	return &NginxDomainReadAction{
		vhostAvailableDir: "/etc/nginx/sites-available",
		backupDir:         defaultConfigBackupDir,
	}
}

func NewNginxDomainReadActionForTest(availDir, backupDir string) *NginxDomainReadAction {
	return &NginxDomainReadAction{
		vhostAvailableDir: availDir,
		backupDir:         backupDir,
	}
}

func (a *NginxDomainReadAction) Name() string {
	return "nginx.domain.read"
}

func (a *NginxDomainReadAction) Validate(payload []byte) error {
	var p NginxDomainReadPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateDomain(p.Domain); err != nil {
		return err
	}
	if isProtectedNginxDomain(p.Domain) {
		return fmt.Errorf("domain '%s' is protected and cannot be edited", p.Domain)
	}
	return nil
}

func (a *NginxDomainReadAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p NginxDomainReadPayload
	_ = json.Unmarshal(payload, &p)

	confName := fmt.Sprintf("%s.conf", p.Domain)
	availPath := filepath.Join(a.vhostAvailableDir, confName)
	if err := ensurePathInside(a.vhostAvailableDir, availPath); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	backups := listDomainBackups(a.backupDir, p.Domain)

	data, err := os.ReadFile(availPath)
	if err != nil {
		if os.IsNotExist(err) {
			return &ActionResult{
				Success: true,
				Data: map[string]any{
					"domain":       p.Domain,
					"exists":       false,
					"content":      "",
					"custom_block": "",
					"has_markers":  false,
					"lines":        []NginxTaggedLine{},
					"backups":      backups,
				},
			}, nil
		}
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	content := string(data)
	tagged, customBlock, hasMarkers := TagNginxLines(content)

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"domain":       p.Domain,
			"exists":       true,
			"content":      content,
			"custom_block": customBlock,
			"has_markers":  hasMarkers,
			"lines":        tagged,
			"backups":      backups,
		},
	}, nil
}

// ============================================================================
// 2. nginx.domain.write
// ============================================================================

type NginxDomainWritePayload struct {
	Domain       string `json:"domain"`
	Username     string `json:"username"`
	CustomBlock  string `json:"custom_block"`
	MaxBodyMB    int    `json:"max_body_size_mb,omitempty"`
	AdvancedMode bool   `json:"advanced_mode,omitempty"`
	FullContent  string `json:"full_content,omitempty"`
}

type NginxDomainWriteAction struct {
	executor          commandExecutor
	vhostAvailableDir string
	backupDir         string
	isLinux           bool
}

func NewNginxDomainWriteAction() *NginxDomainWriteAction {
	return &NginxDomainWriteAction{
		executor:          osCommandExecutor{},
		vhostAvailableDir: "/etc/nginx/sites-available",
		backupDir:         defaultConfigBackupDir,
		isLinux:           runtime.GOOS == "linux",
	}
}

func NewNginxDomainWriteActionForTest(executor commandExecutor, availDir, backupDir string) *NginxDomainWriteAction {
	return &NginxDomainWriteAction{
		executor:          executor,
		vhostAvailableDir: availDir,
		backupDir:         backupDir,
		isLinux:           true,
	}
}

func (a *NginxDomainWriteAction) Name() string {
	return "nginx.domain.write"
}

func (a *NginxDomainWriteAction) Validate(payload []byte) error {
	var p NginxDomainWritePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateDomain(p.Domain); err != nil {
		return err
	}
	if isProtectedNginxDomain(p.Domain) {
		return fmt.Errorf("domain '%s' is protected and cannot be modified", p.Domain)
	}
	if p.Username != "" {
		if err := ValidateLinuxUsername(p.Username); err != nil {
			return err
		}
	}
	if p.AdvancedMode {
		return validateAdvancedModeContent(p.FullContent, p.Username)
	}
	return ParseAndValidateCustomBlock(p.CustomBlock, p.Username, p.MaxBodyMB)
}

func (a *NginxDomainWriteAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p NginxDomainWritePayload
	_ = json.Unmarshal(payload, &p)

	availPath := filepath.Join(a.vhostAvailableDir, fmt.Sprintf("%s.conf", p.Domain))
	if err := ensurePathInside(a.vhostAvailableDir, availPath); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	var nextContent string
	if p.AdvancedMode {
		nextContent = p.FullContent
	} else {
		existingBytes, err := os.ReadFile(availPath)
		if err != nil {
			return &ActionResult{Success: false, Error: fmt.Sprintf("reading existing vhost: %v", err)}, err
		}
		replaced, err := ReplaceCustomBlock(string(existingBytes), p.CustomBlock)
		if err != nil {
			return &ActionResult{Success: false, Error: err.Error()}, err
		}
		nextContent = replaced
	}

	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"domain":            p.Domain,
				"nginx_test_output": "[dry-run] syntax is ok",
				"dry_run":           true,
			},
			Logs: []string{fmt.Sprintf("[dry-run] validated custom nginx config for %s", p.Domain)},
		}, nil
	}

	backupID, err := backupDomainConfig(availPath, a.backupDir, p.Domain)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("creating backup: %v", err)}, err
	}
	var backupPath string
	if backupID != "" {
		backupPath = filepath.Join(a.backupDir, backupID)
	}

	testOut, err := applyAndTestNginxDomainConfig(ctx, a.executor, availPath, []byte(nextContent), backupPath, a.isLinux)
	if err != nil {
		return &ActionResult{
			Success: false,
			Error:   err.Error(),
			Data: map[string]any{
				"domain":            p.Domain,
				"nginx_test_output": testOut,
				"rolled_back":       true,
			},
			Logs: []string{fmt.Sprintf("nginx config write failed for %s: %v", p.Domain, err)},
		}, err
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"domain":            p.Domain,
			"backup_id":         backupID,
			"nginx_test_output": testOut,
			"backups":           listDomainBackups(a.backupDir, p.Domain),
		},
		Logs: []string{fmt.Sprintf("updated nginx config for %s and reloaded nginx", p.Domain)},
	}, nil
}

// ============================================================================
// 3. nginx.domain.restore
// ============================================================================

type NginxDomainRestorePayload struct {
	Domain   string `json:"domain"`
	BackupID string `json:"backup_id"`
}

type NginxDomainRestoreAction struct {
	executor          commandExecutor
	vhostAvailableDir string
	backupDir         string
	isLinux           bool
}

func NewNginxDomainRestoreAction() *NginxDomainRestoreAction {
	return &NginxDomainRestoreAction{
		executor:          osCommandExecutor{},
		vhostAvailableDir: "/etc/nginx/sites-available",
		backupDir:         defaultConfigBackupDir,
		isLinux:           runtime.GOOS == "linux",
	}
}

func NewNginxDomainRestoreActionForTest(executor commandExecutor, availDir, backupDir string) *NginxDomainRestoreAction {
	return &NginxDomainRestoreAction{
		executor:          executor,
		vhostAvailableDir: availDir,
		backupDir:         backupDir,
		isLinux:           true,
	}
}

func (a *NginxDomainRestoreAction) Name() string {
	return "nginx.domain.restore"
}

func (a *NginxDomainRestoreAction) Validate(payload []byte) error {
	var p NginxDomainRestorePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateDomain(p.Domain); err != nil {
		return err
	}
	if isProtectedNginxDomain(p.Domain) {
		return fmt.Errorf("domain '%s' is protected", p.Domain)
	}
	if strings.TrimSpace(p.BackupID) == "" || strings.Contains(p.BackupID, "/") || strings.Contains(p.BackupID, "\\") || strings.Contains(p.BackupID, "..") {
		return errors.New("invalid backup_id")
	}
	if !strings.HasPrefix(p.BackupID, p.Domain+".") {
		return errors.New("backup_id does not belong to target domain")
	}
	return nil
}

func (a *NginxDomainRestoreAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p NginxDomainRestorePayload
	_ = json.Unmarshal(payload, &p)

	availPath := filepath.Join(a.vhostAvailableDir, fmt.Sprintf("%s.conf", p.Domain))
	backupSrc := filepath.Join(a.backupDir, p.BackupID)
	if err := ensurePathInside(a.backupDir, backupSrc); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	restoreBytes, err := os.ReadFile(backupSrc)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("reading backup: %v", err)}, err
	}

	if dryRun {
		return &ActionResult{Success: true, Data: map[string]any{"domain": p.Domain, "backup_id": p.BackupID, "dry_run": true}}, nil
	}

	prevBackupID, err := backupDomainConfig(availPath, a.backupDir, p.Domain)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("backing up current config: %v", err)}, err
	}
	var prevBackupPath string
	if prevBackupID != "" {
		prevBackupPath = filepath.Join(a.backupDir, prevBackupID)
	}

	testOut, err := applyAndTestNginxDomainConfig(ctx, a.executor, availPath, restoreBytes, prevBackupPath, a.isLinux)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Data: map[string]any{"nginx_test_output": testOut}}, err
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"domain":            p.Domain,
			"restored_backup":   p.BackupID,
			"nginx_test_output": testOut,
		},
		Logs: []string{fmt.Sprintf("restored nginx config for %s from %s", p.Domain, p.BackupID)},
	}, nil
}

// ============================================================================
// 4. nginx.domain.reset
// ============================================================================

type NginxDomainResetPayload struct {
	Username      string                   `json:"username"`
	Domain        string                   `json:"domain"`
	DocumentRoot  string                   `json:"document_root"`
	AppProfile    string                   `json:"app_profile,omitempty"`
	PHPVersion    string                   `json:"php_version,omitempty"`
	ForceHTTPS    bool                     `json:"force_https,omitempty"`
	SSLEnabled    bool                     `json:"ssl_enabled,omitempty"`
	SSLCertPath   string                   `json:"ssl_cert_path,omitempty"`
	SSLKeyPath    string                   `json:"ssl_key_path,omitempty"`
	HstsEnabled   bool                     `json:"hsts_enabled,omitempty"`
	WwwRedirect   string                   `json:"www_redirect,omitempty"`
	Redirects     []RedirectRule           `json:"redirects,omitempty"`
	ProtectedDirs []ProtectedDirectoryRule `json:"protected_dirs,omitempty"`
}

type NginxDomainResetAction struct {
	executor          commandExecutor
	vhostAvailableDir string
	backupDir         string
	isLinux           bool
}

func NewNginxDomainResetAction() *NginxDomainResetAction {
	return &NginxDomainResetAction{
		executor:          osCommandExecutor{},
		vhostAvailableDir: "/etc/nginx/sites-available",
		backupDir:         defaultConfigBackupDir,
		isLinux:           runtime.GOOS == "linux",
	}
}

func NewNginxDomainResetActionForTest(executor commandExecutor, availDir, backupDir string) *NginxDomainResetAction {
	return &NginxDomainResetAction{
		executor:          executor,
		vhostAvailableDir: availDir,
		backupDir:         backupDir,
		isLinux:           true,
	}
}

func (a *NginxDomainResetAction) Name() string {
	return "nginx.domain.reset"
}

func (a *NginxDomainResetAction) Validate(payload []byte) error {
	var p NginxDomainResetPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if err := ValidateDomain(p.Domain); err != nil {
		return err
	}
	if isProtectedNginxDomain(p.Domain) {
		return fmt.Errorf("domain '%s' is protected", p.Domain)
	}
	if err := ValidateDocumentRoot(p.Username, p.DocumentRoot); err != nil {
		return err
	}
	return nil
}

func (a *NginxDomainResetAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p NginxDomainResetPayload
	_ = json.Unmarshal(payload, &p)

	rendered, err := RenderVhostConfig(VhostTemplateData{
		Username:      p.Username,
		Domain:        p.Domain,
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
		CustomBlock:   "",
	})
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if dryRun {
		return &ActionResult{Success: true, Data: map[string]any{"domain": p.Domain, "dry_run": true}}, nil
	}

	availPath := filepath.Join(a.vhostAvailableDir, fmt.Sprintf("%s.conf", p.Domain))
	backupID, err := backupDomainConfig(availPath, a.backupDir, p.Domain)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("creating backup: %v", err)}, err
	}
	var backupPath string
	if backupID != "" {
		backupPath = filepath.Join(a.backupDir, backupID)
	}

	testOut, err := applyAndTestNginxDomainConfig(ctx, a.executor, availPath, []byte(rendered), backupPath, a.isLinux)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Data: map[string]any{"nginx_test_output": testOut}}, err
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"domain":            p.Domain,
			"backup_id":         backupID,
			"nginx_test_output": testOut,
		},
		Logs: []string{fmt.Sprintf("reset nginx config for %s to default", p.Domain)},
	}, nil
}
