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

var nginxConfigNameRegex = regexp.MustCompile(`^[a-z0-9.-]+\.conf$`)

const maxConfigSizeBytes = 256 * 1024 // 256 KB

// ValidateNginxConfigName checks that name matches ^[a-z0-9.-]+\.conf$
// and prevents access to /etc/nginx/nginx.conf or directory traversal.
func ValidateNginxConfigName(name string) error {
	trimmed := strings.TrimSpace(name)
	if trimmed == "" {
		return errors.New("nginx config name cannot be empty")
	}
	if trimmed == "nginx.conf" {
		return errors.New("access to nginx.conf is prohibited")
	}
	if strings.Contains(trimmed, "..") || strings.Contains(trimmed, "/") || strings.Contains(trimmed, "\\") {
		return errors.New("invalid nginx config name: path traversal rejected")
	}
	if !nginxConfigNameRegex.MatchString(trimmed) {
		return fmt.Errorf("invalid config name '%s': must match ^[a-z0-9.-]+\\.conf$", trimmed)
	}
	return nil
}

// ensurePathInside verifies that path is inside baseDir and does not escape via symlinks.
func ensurePathInside(baseDir, path string) error {
	cleanBase, err := filepath.Abs(baseDir)
	if err != nil {
		return fmt.Errorf("evaluating base directory: %w", err)
	}
	cleanPath, err := filepath.Abs(path)
	if err != nil {
		return fmt.Errorf("evaluating path: %w", err)
	}

	rel, err := filepath.Rel(cleanBase, cleanPath)
	if err != nil || strings.HasPrefix(rel, "..") || rel == "." && cleanBase != cleanPath {
		return errors.New("path escapes directory")
	}

	// If file exists, evaluate symlinks
	if _, err := os.Lstat(cleanPath); err == nil {
		eval, err := filepath.EvalSymlinks(cleanPath)
		if err == nil {
			evalRel, err := filepath.Rel(cleanBase, eval)
			if err != nil || strings.HasPrefix(evalRel, "..") {
				return errors.New("symlink escapes allowed directory")
			}
		}
	}

	return nil
}

// pruneBackups keeps the most recent 20 backups for a given filename prefix.
func pruneBackups(backupDir, baseName string) {
	entries, err := os.ReadDir(backupDir)
	if err != nil {
		return
	}

	prefix := baseName + "."
	var matching []os.FileInfo
	for _, entry := range entries {
		if entry.IsDir() {
			continue
		}
		if strings.HasPrefix(entry.Name(), prefix) {
			if info, err := entry.Info(); err == nil {
				matching = append(matching, info)
			}
		}
	}

	if len(matching) <= 20 {
		return
	}

	// Sort newest first
	sort.Slice(matching, func(i, j int) bool {
		return matching[i].ModTime().After(matching[j].ModTime())
	})

	// Remove older than 20
	for _, old := range matching[20:] {
		_ = os.Remove(filepath.Join(backupDir, old.Name()))
	}
}

// ============================================================================
// 1. nginx.config.list
// ============================================================================

type NginxConfigFileInfo struct {
	Name        string `json:"name"`
	Enabled     bool   `json:"enabled"`
	Owner       string `json:"owner"`
	Size        int64  `json:"size"`
	Mtime       int64  `json:"mtime"`
	MtimeHuman  string `json:"mtime_human"`
}

type NginxConfigListAction struct {
	vhostAvailableDir string
	vhostEnabledDir   string
}

func NewNginxConfigListAction() *NginxConfigListAction {
	return &NginxConfigListAction{
		vhostAvailableDir: "/etc/nginx/sites-available",
		vhostEnabledDir:   "/etc/nginx/sites-enabled",
	}
}

func NewNginxConfigListActionForTest(vhostAvailableDir, vhostEnabledDir string) *NginxConfigListAction {
	return &NginxConfigListAction{
		vhostAvailableDir: vhostAvailableDir,
		vhostEnabledDir:   vhostEnabledDir,
	}
}

func (a *NginxConfigListAction) Name() string {
	return "nginx.config.list"
}

func (a *NginxConfigListAction) Validate(payload []byte) error {
	return nil
}

func (a *NginxConfigListAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	filesMap := make(map[string]*NginxConfigFileInfo)

	// Scan sites-available
	if entries, err := os.ReadDir(a.vhostAvailableDir); err == nil {
		for _, entry := range entries {
			name := entry.Name()
			if !nginxConfigNameRegex.MatchString(name) || name == "nginx.conf" {
				continue
			}
			fullPath := filepath.Join(a.vhostAvailableDir, name)
			if err := ensurePathInside(a.vhostAvailableDir, fullPath); err != nil {
				continue
			}

			info, err := entry.Info()
			if err != nil {
				continue
			}

			owner := detectConfigOwner(fullPath)
			filesMap[name] = &NginxConfigFileInfo{
				Name:       name,
				Enabled:    false,
				Owner:      owner,
				Size:       info.Size(),
				Mtime:      info.ModTime().Unix(),
				MtimeHuman: info.ModTime().UTC().Format(time.RFC3339),
			}
		}
	}

	// Scan sites-enabled to mark enabled or discover direct files
	if entries, err := os.ReadDir(a.vhostEnabledDir); err == nil {
		for _, entry := range entries {
			name := entry.Name()
			if !nginxConfigNameRegex.MatchString(name) || name == "nginx.conf" {
				continue
			}
			enabledPath := filepath.Join(a.vhostEnabledDir, name)

			if existing, ok := filesMap[name]; ok {
				existing.Enabled = true
			} else {
				info, err := entry.Info()
				if err != nil {
					continue
				}
				filesMap[name] = &NginxConfigFileInfo{
					Name:       name,
					Enabled:    true,
					Owner:      detectConfigOwner(enabledPath),
					Size:       info.Size(),
					Mtime:      info.ModTime().Unix(),
					MtimeHuman: info.ModTime().UTC().Format(time.RFC3339),
				}
			}
		}
	}

	result := make([]*NginxConfigFileInfo, 0, len(filesMap))
	for _, item := range filesMap {
		result = append(result, item)
	}

	sort.Slice(result, func(i, j int) bool {
		return result[i].Name < result[j].Name
	})

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"files": result,
			"count": len(result),
		},
	}, nil
}

func detectConfigOwner(path string) string {
	content, err := os.ReadFile(path)
	if err != nil {
		return "root"
	}
	s := string(content)

	// Check access_log /home/<user>/logs/...
	reAccess := regexp.MustCompile(`/home/([a-z0-9_-]+)/logs`)
	if matches := reAccess.FindStringSubmatch(s); len(matches) > 1 {
		return matches[1]
	}

	// Check root /home/<user>/...
	reRoot := regexp.MustCompile(`root\s+/home/([a-z0-9_-]+)/`)
	if matches := reRoot.FindStringSubmatch(s); len(matches) > 1 {
		return matches[1]
	}

	// Check fastcgi_pass unix:/run/php/php.*-fpm-<user>.sock
	rePhp := regexp.MustCompile(`fastcgi_pass\s+unix:/run/php/php[0-9.]+-fpm-([a-z0-9_-]+)\.sock`)
	if matches := rePhp.FindStringSubmatch(s); len(matches) > 1 {
		return matches[1]
	}

	if strings.Contains(path, "00-default") {
		return "system"
	}

	return "system"
}

// ============================================================================
// 2. nginx.config.read
// ============================================================================

type NginxConfigReadPayload struct {
	Name string `json:"name"`
}

type NginxConfigReadAction struct {
	vhostAvailableDir string
	vhostEnabledDir   string
}

func NewNginxConfigReadAction() *NginxConfigReadAction {
	return &NginxConfigReadAction{
		vhostAvailableDir: "/etc/nginx/sites-available",
		vhostEnabledDir:   "/etc/nginx/sites-enabled",
	}
}

func NewNginxConfigReadActionForTest(vhostAvailableDir, vhostEnabledDir string) *NginxConfigReadAction {
	return &NginxConfigReadAction{
		vhostAvailableDir: vhostAvailableDir,
		vhostEnabledDir:   vhostEnabledDir,
	}
}

func (a *NginxConfigReadAction) Name() string {
	return "nginx.config.read"
}

func (a *NginxConfigReadAction) Validate(payload []byte) error {
	var p NginxConfigReadPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	return ValidateNginxConfigName(p.Name)
}

func (a *NginxConfigReadAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p NginxConfigReadPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	availPath := filepath.Join(a.vhostAvailableDir, p.Name)
	if err := ensurePathInside(a.vhostAvailableDir, availPath); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	targetPath := availPath
	if _, err := os.Stat(targetPath); os.IsNotExist(err) {
		// Check sites-enabled
		enabledPath := filepath.Join(a.vhostEnabledDir, p.Name)
		if err := ensurePathInside(a.vhostEnabledDir, enabledPath); err != nil {
			return &ActionResult{Success: false, Error: "config file not found"}, os.ErrNotExist
		}
		if _, err := os.Stat(enabledPath); err == nil {
			targetPath = enabledPath
		} else {
			return &ActionResult{Success: false, Error: "config file not found"}, os.ErrNotExist
		}
	}

	info, err := os.Stat(targetPath)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	if info.Size() > maxConfigSizeBytes {
		return &ActionResult{Success: false, Error: "config file exceeds 256 KB maximum size"}, errors.New("file too large")
	}

	content, err := os.ReadFile(targetPath)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("reading config: %v", err)}, err
	}

	enabledPath := filepath.Join(a.vhostEnabledDir, p.Name)
	_, enabledErr := os.Lstat(enabledPath)
	isEnabled := enabledErr == nil

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"name":        p.Name,
			"content":     string(content),
			"size":        len(content),
			"enabled":     isEnabled,
			"mtime":       info.ModTime().Unix(),
			"mtime_human": info.ModTime().UTC().Format(time.RFC3339),
		},
	}, nil
}

// ============================================================================
// 3. nginx.config.write
// ============================================================================

type NginxConfigWritePayload struct {
	Name           string `json:"name"`
	Content        string `json:"content"`
	AdminConfirmed bool   `json:"admin_confirmed,omitempty"`
}

type NginxConfigWriteAction struct {
	executor          commandExecutor
	vhostAvailableDir string
	vhostEnabledDir   string
	backupDir         string
	isLinux           bool
}

func NewNginxConfigWriteAction() *NginxConfigWriteAction {
	return &NginxConfigWriteAction{
		executor:          osCommandExecutor{},
		vhostAvailableDir: "/etc/nginx/sites-available",
		vhostEnabledDir:   "/etc/nginx/sites-enabled",
		backupDir:         "/var/lib/panel-agent/config-backups",
		isLinux:           runtime.GOOS == "linux",
	}
}

func NewNginxConfigWriteActionForTest(executor commandExecutor, vhostAvailableDir, vhostEnabledDir, backupDir string) *NginxConfigWriteAction {
	return &NginxConfigWriteAction{
		executor:          executor,
		vhostAvailableDir: vhostAvailableDir,
		vhostEnabledDir:   vhostEnabledDir,
		backupDir:         backupDir,
		isLinux:           true,
	}
}

func (a *NginxConfigWriteAction) Name() string {
	return "nginx.config.write"
}

func (a *NginxConfigWriteAction) Validate(payload []byte) error {
	var p NginxConfigWritePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateNginxConfigName(p.Name); err != nil {
		return err
	}
	if len(p.Content) > maxConfigSizeBytes {
		return errors.New("content exceeds 256 KB maximum size limit")
	}
	return nil
}

func (a *NginxConfigWriteAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p NginxConfigWritePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	destPath := filepath.Join(a.vhostAvailableDir, p.Name)
	if err := ensurePathInside(a.vhostAvailableDir, destPath); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"name":    p.Name,
				"dry_run": true,
			},
		}, nil
	}

	_ = os.MkdirAll(a.vhostAvailableDir, 0755)
	_ = os.MkdirAll(a.backupDir, 0700)

	// Backup existing file if it exists
	var backupFile string
	hadPrevious := false
	if _, err := os.Stat(destPath); err == nil {
		hadPrevious = true
		backupFile = filepath.Join(a.backupDir, fmt.Sprintf("%s.%d", p.Name, time.Now().UnixNano()))
		if err := copyFile(destPath, backupFile); err != nil {
			return &ActionResult{Success: false, Error: fmt.Sprintf("creating backup: %v", err)}, err
		}
		pruneBackups(a.backupDir, p.Name)
	}

	// Write new content to temp file and commit
	tmpPath, err := CreateConfigTemp(destPath, []byte(p.Content), 0640)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("creating temp config: %v", err)}, err
	}

	if err := CommitConfigFile(tmpPath, destPath, 0640); err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("committing config: %v", err)}, err
	}

	// Test syntax via nginx -t
	if a.isLinux {
		if _, err := a.executor.LookPath("nginx"); err == nil {
			testOut, testErr := a.executor.CombinedOutput(ctx, "nginx", "-t")
			if testErr != nil {
				// Rollback
				if hadPrevious && backupFile != "" {
					_ = copyFile(backupFile, destPath)
				} else {
					_ = os.Remove(destPath)
				}
				errText := strings.TrimSpace(string(testOut))
				return &ActionResult{
					Success: false,
					Error:   fmt.Sprintf("nginx syntax test failed: %s", errText),
					Data: map[string]any{
						"nginx_output": errText,
						"rolled_back":  true,
					},
				}, fmt.Errorf("nginx syntax test failed: %s", errText)
			}

			// Reload nginx
			if _, err := a.executor.LookPath("systemctl"); err == nil {
				_ = a.executor.Run(ctx, "systemctl", "reload", "nginx")
			}
			return &ActionResult{
				Success: true,
				Data: map[string]any{
					"name":         p.Name,
					"nginx_output": strings.TrimSpace(string(testOut)),
					"reloaded":     true,
				},
			}, nil
		}
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"name":     p.Name,
			"reloaded": false,
		},
	}, nil
}

// ============================================================================
// 4. nginx.config.enable & nginx.config.disable
// ============================================================================

type NginxConfigTogglePayload struct {
	Name string `json:"name"`
}

type NginxConfigEnableAction struct {
	executor          commandExecutor
	vhostAvailableDir string
	vhostEnabledDir   string
	isLinux           bool
}

func NewNginxConfigEnableAction() *NginxConfigEnableAction {
	return &NginxConfigEnableAction{
		executor:          osCommandExecutor{},
		vhostAvailableDir: "/etc/nginx/sites-available",
		vhostEnabledDir:   "/etc/nginx/sites-enabled",
		isLinux:           runtime.GOOS == "linux",
	}
}

func NewNginxConfigEnableActionForTest(executor commandExecutor, vhostAvailableDir, vhostEnabledDir string) *NginxConfigEnableAction {
	return &NginxConfigEnableAction{
		executor:          executor,
		vhostAvailableDir: vhostAvailableDir,
		vhostEnabledDir:   vhostEnabledDir,
		isLinux:           true,
	}
}

func (a *NginxConfigEnableAction) Name() string {
	return "nginx.config.enable"
}

func (a *NginxConfigEnableAction) Validate(payload []byte) error {
	var p NginxConfigTogglePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	return ValidateNginxConfigName(p.Name)
}

func (a *NginxConfigEnableAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p NginxConfigTogglePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	availPath := filepath.Join(a.vhostAvailableDir, p.Name)
	if err := ensurePathInside(a.vhostAvailableDir, availPath); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	if _, err := os.Stat(availPath); os.IsNotExist(err) {
		return &ActionResult{Success: false, Error: fmt.Sprintf("config file %s does not exist in sites-available", p.Name)}, os.ErrNotExist
	}

	enabledPath := filepath.Join(a.vhostEnabledDir, p.Name)
	if err := ensurePathInside(a.vhostEnabledDir, enabledPath); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if dryRun {
		return &ActionResult{Success: true, Data: map[string]any{"name": p.Name, "enabled": true, "dry_run": true}}, nil
	}

	_ = os.MkdirAll(a.vhostEnabledDir, 0755)

	// Check if already enabled
	if info, err := os.Lstat(enabledPath); err == nil && (info.Mode()&os.ModeSymlink != 0) {
		return &ActionResult{Success: true, Data: map[string]any{"name": p.Name, "enabled": true, "already_enabled": true}}, nil
	}

	_ = os.Remove(enabledPath)
	if err := os.Symlink(availPath, enabledPath); err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("creating symlink: %v", err)}, err
	}

	// Validate with nginx -t
	if a.isLinux {
		if _, err := a.executor.LookPath("nginx"); err == nil {
			testOut, testErr := a.executor.CombinedOutput(ctx, "nginx", "-t")
			if testErr != nil {
				// Rollback symlink
				_ = os.Remove(enabledPath)
				errText := strings.TrimSpace(string(testOut))
				return &ActionResult{
					Success: false,
					Error:   fmt.Sprintf("nginx syntax test failed: %s", errText),
					Data: map[string]any{
						"nginx_output": errText,
						"rolled_back":  true,
					},
				}, fmt.Errorf("nginx syntax test failed: %s", errText)
			}

			if _, err := a.executor.LookPath("systemctl"); err == nil {
				_ = a.executor.Run(ctx, "systemctl", "reload", "nginx")
			}
			return &ActionResult{
				Success: true,
				Data: map[string]any{
					"name":         p.Name,
					"enabled":      true,
					"nginx_output": strings.TrimSpace(string(testOut)),
				},
			}, nil
		}
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"name":    p.Name,
			"enabled": true,
		},
	}, nil
}

type NginxConfigDisableAction struct {
	executor          commandExecutor
	vhostAvailableDir string
	vhostEnabledDir   string
	isLinux           bool
}

func NewNginxConfigDisableAction() *NginxConfigDisableAction {
	return &NginxConfigDisableAction{
		executor:          osCommandExecutor{},
		vhostAvailableDir: "/etc/nginx/sites-available",
		vhostEnabledDir:   "/etc/nginx/sites-enabled",
		isLinux:           runtime.GOOS == "linux",
	}
}

func NewNginxConfigDisableActionForTest(executor commandExecutor, vhostAvailableDir, vhostEnabledDir string) *NginxConfigDisableAction {
	return &NginxConfigDisableAction{
		executor:          executor,
		vhostAvailableDir: vhostAvailableDir,
		vhostEnabledDir:   vhostEnabledDir,
		isLinux:           true,
	}
}

func (a *NginxConfigDisableAction) Name() string {
	return "nginx.config.disable"
}

func (a *NginxConfigDisableAction) Validate(payload []byte) error {
	var p NginxConfigTogglePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	return ValidateNginxConfigName(p.Name)
}

func (a *NginxConfigDisableAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p NginxConfigTogglePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	availPath := filepath.Join(a.vhostAvailableDir, p.Name)
	enabledPath := filepath.Join(a.vhostEnabledDir, p.Name)

	if err := ensurePathInside(a.vhostEnabledDir, enabledPath); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if dryRun {
		return &ActionResult{Success: true, Data: map[string]any{"name": p.Name, "enabled": false, "dry_run": true}}, nil
	}

	// Check if currently exists
	if _, err := os.Lstat(enabledPath); os.IsNotExist(err) {
		return &ActionResult{Success: true, Data: map[string]any{"name": p.Name, "enabled": false, "already_disabled": true}}, nil
	}

	// Remove symlink
	if err := os.Remove(enabledPath); err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("removing symlink: %v", err)}, err
	}

	// Validate with nginx -t
	if a.isLinux {
		if _, err := a.executor.LookPath("nginx"); err == nil {
			testOut, testErr := a.executor.CombinedOutput(ctx, "nginx", "-t")
			if testErr != nil {
				// Rollback: recreate symlink
				_ = os.Symlink(availPath, enabledPath)
				errText := strings.TrimSpace(string(testOut))
				return &ActionResult{
					Success: false,
					Error:   fmt.Sprintf("nginx syntax test failed: %s", errText),
					Data: map[string]any{
						"nginx_output": errText,
						"rolled_back":  true,
					},
				}, fmt.Errorf("nginx syntax test failed: %s", errText)
			}

			if _, err := a.executor.LookPath("systemctl"); err == nil {
				_ = a.executor.Run(ctx, "systemctl", "reload", "nginx")
			}
			return &ActionResult{
				Success: true,
				Data: map[string]any{
					"name":         p.Name,
					"enabled":      false,
					"nginx_output": strings.TrimSpace(string(testOut)),
				},
			}, nil
		}
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"name":    p.Name,
			"enabled": false,
		},
	}, nil
}

// ============================================================================
// 5. nginx.config.backups & nginx.config.restore
// ============================================================================

type NginxConfigBackupsPayload struct {
	Name string `json:"name"`
}

type NginxConfigBackupItem struct {
	ID            string `json:"id"`
	Name          string `json:"name"`
	Timestamp     int64  `json:"timestamp"`
	FormattedTime string `json:"formatted_time"`
	Size          int64  `json:"size"`
}

type NginxConfigBackupsAction struct {
	backupDir string
}

func NewNginxConfigBackupsAction() *NginxConfigBackupsAction {
	return &NginxConfigBackupsAction{
		backupDir: "/var/lib/panel-agent/config-backups",
	}
}

func NewNginxConfigBackupsActionForTest(backupDir string) *NginxConfigBackupsAction {
	return &NginxConfigBackupsAction{
		backupDir: backupDir,
	}
}

func (a *NginxConfigBackupsAction) Name() string {
	return "nginx.config.backups"
}

func (a *NginxConfigBackupsAction) Validate(payload []byte) error {
	var p NginxConfigBackupsPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	return ValidateNginxConfigName(p.Name)
}

func (a *NginxConfigBackupsAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p NginxConfigBackupsPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	entries, err := os.ReadDir(a.backupDir)
	if err != nil && !os.IsNotExist(err) {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	var items []NginxConfigBackupItem
	prefix := p.Name + "."
	for _, entry := range entries {
		if entry.IsDir() {
			continue
		}
		if strings.HasPrefix(entry.Name(), prefix) {
			info, err := entry.Info()
			if err != nil {
				continue
			}
			items = append(items, NginxConfigBackupItem{
				ID:            entry.Name(),
				Name:          p.Name,
				Timestamp:     info.ModTime().Unix(),
				FormattedTime: info.ModTime().UTC().Format(time.RFC3339),
				Size:          info.Size(),
			})
		}
	}

	sort.Slice(items, func(i, j int) bool {
		return items[i].Timestamp > items[j].Timestamp
	})

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"name":    p.Name,
			"backups": items,
			"count":   len(items),
		},
	}, nil
}

type NginxConfigRestorePayload struct {
	Name     string `json:"name"`
	BackupID string `json:"backup_id"`
}

type NginxConfigRestoreAction struct {
	executor          commandExecutor
	vhostAvailableDir string
	backupDir         string
	isLinux           bool
}

func NewNginxConfigRestoreAction() *NginxConfigRestoreAction {
	return &NginxConfigRestoreAction{
		executor:          osCommandExecutor{},
		vhostAvailableDir: "/etc/nginx/sites-available",
		backupDir:         "/var/lib/panel-agent/config-backups",
		isLinux:           runtime.GOOS == "linux",
	}
}

func NewNginxConfigRestoreActionForTest(executor commandExecutor, vhostAvailableDir, backupDir string) *NginxConfigRestoreAction {
	return &NginxConfigRestoreAction{
		executor:          executor,
		vhostAvailableDir: vhostAvailableDir,
		backupDir:         backupDir,
		isLinux:           true,
	}
}

func (a *NginxConfigRestoreAction) Name() string {
	return "nginx.config.restore"
}

func (a *NginxConfigRestoreAction) Validate(payload []byte) error {
	var p NginxConfigRestorePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateNginxConfigName(p.Name); err != nil {
		return err
	}
	if strings.Contains(p.BackupID, "..") || strings.Contains(p.BackupID, "/") || strings.Contains(p.BackupID, "\\") {
		return errors.New("invalid backup_id: path traversal rejected")
	}
	if !strings.HasPrefix(p.BackupID, p.Name+".") {
		return errors.New("backup_id does not match config name")
	}
	return nil
}

func (a *NginxConfigRestoreAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p NginxConfigRestorePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	backupPath := filepath.Join(a.backupDir, p.BackupID)
	if err := ensurePathInside(a.backupDir, backupPath); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	backupContent, err := os.ReadFile(backupPath)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("reading backup: %v", err)}, err
	}

	destPath := filepath.Join(a.vhostAvailableDir, p.Name)
	if err := ensurePathInside(a.vhostAvailableDir, destPath); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if dryRun {
		return &ActionResult{Success: true, Data: map[string]any{"name": p.Name, "restored": true, "dry_run": true}}, nil
	}

	// Backup current file first
	var currentBackup string
	hadPrevious := false
	if _, err := os.Stat(destPath); err == nil {
		hadPrevious = true
		currentBackup = filepath.Join(a.backupDir, fmt.Sprintf("%s.%d", p.Name, time.Now().UnixNano()))
		_ = copyFile(destPath, currentBackup)
	}

	// Write restored content
	tmpPath, err := CreateConfigTemp(destPath, backupContent, 0640)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("writing temp file: %v", err)}, err
	}

	if err := CommitConfigFile(tmpPath, destPath, 0640); err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("committing file: %v", err)}, err
	}

	// Validate with nginx -t
	if a.isLinux {
		if _, err := a.executor.LookPath("nginx"); err == nil {
			testOut, testErr := a.executor.CombinedOutput(ctx, "nginx", "-t")
			if testErr != nil {
				// Rollback to what it was right before restore
				if hadPrevious && currentBackup != "" {
					_ = copyFile(currentBackup, destPath)
				} else {
					_ = os.Remove(destPath)
				}
				errText := strings.TrimSpace(string(testOut))
				return &ActionResult{
					Success: false,
					Error:   fmt.Sprintf("nginx syntax test failed: %s", errText),
					Data: map[string]any{
						"nginx_output": errText,
						"rolled_back":  true,
					},
				}, fmt.Errorf("nginx syntax test failed: %s", errText)
			}

			if _, err := a.executor.LookPath("systemctl"); err == nil {
				_ = a.executor.Run(ctx, "systemctl", "reload", "nginx")
			}
			return &ActionResult{
				Success: true,
				Data: map[string]any{
					"name":         p.Name,
					"backup_id":    p.BackupID,
					"restored":     true,
					"nginx_output": strings.TrimSpace(string(testOut)),
				},
			}, nil
		}
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"name":      p.Name,
			"backup_id": p.BackupID,
			"restored":  true,
		},
	}, nil
}

// ============================================================================
// 6. nginx.config.test
// ============================================================================

type NginxConfigTestAction struct {
	executor commandExecutor
	isLinux  bool
}

func NewNginxConfigTestAction() *NginxConfigTestAction {
	return &NginxConfigTestAction{
		executor: osCommandExecutor{},
		isLinux:  runtime.GOOS == "linux",
	}
}

func NewNginxConfigTestActionForTest(executor commandExecutor) *NginxConfigTestAction {
	return &NginxConfigTestAction{
		executor: executor,
		isLinux:  true,
	}
}

func (a *NginxConfigTestAction) Name() string {
	return "nginx.config.test"
}

func (a *NginxConfigTestAction) Validate(payload []byte) error {
	return nil
}

func (a *NginxConfigTestAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	if a.isLinux {
		if _, err := a.executor.LookPath("nginx"); err == nil {
			testOut, testErr := a.executor.CombinedOutput(ctx, "nginx", "-t")
			outStr := strings.TrimSpace(string(testOut))
			if testErr != nil {
				return &ActionResult{
					Success: false,
					Error:   fmt.Sprintf("nginx syntax test failed: %s", outStr),
					Data: map[string]any{
						"valid":        false,
						"nginx_output": outStr,
					},
				}, nil
			}
			return &ActionResult{
				Success: true,
				Data: map[string]any{
					"valid":        true,
					"nginx_output": outStr,
				},
			}, nil
		}
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"valid":        true,
			"nginx_output": "nginx: simulated syntax is ok",
		},
	}, nil
}

// ============================================================================
// 7. nginx.custom.read
// ============================================================================

type NginxCustomReadPayload struct {
	Domain string `json:"domain"`
}

type NginxCustomReadAction struct {
	hpanelCustomDir string
}

func NewNginxCustomReadAction() *NginxCustomReadAction {
	return &NginxCustomReadAction{
		hpanelCustomDir: "/etc/nginx/hpanel.d",
	}
}

func NewNginxCustomReadActionForTest(hpanelCustomDir string) *NginxCustomReadAction {
	return &NginxCustomReadAction{
		hpanelCustomDir: hpanelCustomDir,
	}
}

func (a *NginxCustomReadAction) Name() string {
	return "nginx.custom.read"
}

func (a *NginxCustomReadAction) Validate(payload []byte) error {
	var p NginxCustomReadPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	return ValidateDomain(p.Domain)
}

func (a *NginxCustomReadAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p NginxCustomReadPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	customPath := filepath.Join(a.hpanelCustomDir, fmt.Sprintf("%s.custom.conf", p.Domain))
	if err := ensurePathInside(a.hpanelCustomDir, customPath); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	content, err := os.ReadFile(customPath)
	if err != nil {
		if os.IsNotExist(err) {
			return &ActionResult{
				Success: true,
				Data: map[string]any{
					"domain":  p.Domain,
					"content": "",
					"exists":  false,
				},
			}, nil
		}
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"domain":  p.Domain,
			"content": string(content),
			"exists":  true,
			"size":    len(content),
		},
	}, nil
}

// ============================================================================
// 8. nginx.custom.write
// ============================================================================

type CustomRedirectRule struct {
	Source string `json:"source"`
	Target string `json:"target"`
	Code   int    `json:"code"`
}

type NginxCustomSettingsDTO struct {
	Redirects           []CustomRedirectRule `json:"redirects,omitempty"`
	CustomError404      string               `json:"custom_error_404,omitempty"`
	CustomError403      string               `json:"custom_error_403,omitempty"`
	CleanUrls           *bool                `json:"clean_urls,omitempty"`
	ForceHTTPS          *bool                `json:"force_https,omitempty"`
	MaxUploadBodySizeMB int                  `json:"max_upload_body_size_mb,omitempty"`
	CacheLifetimeStatic string               `json:"cache_lifetime_static,omitempty"`
	DefaultIndex        []string             `json:"default_index,omitempty"`
}

type NginxCustomWritePayload struct {
	Domain   string                 `json:"domain"`
	Settings NginxCustomSettingsDTO `json:"settings"`
}

type NginxCustomWriteAction struct {
	executor        commandExecutor
	hpanelCustomDir string
	backupDir       string
	isLinux         bool
}

func NewNginxCustomWriteAction() *NginxCustomWriteAction {
	return &NginxCustomWriteAction{
		executor:        osCommandExecutor{},
		hpanelCustomDir: "/etc/nginx/hpanel.d",
		backupDir:       "/var/lib/panel-agent/config-backups",
		isLinux:         runtime.GOOS == "linux",
	}
}

func NewNginxCustomWriteActionForTest(executor commandExecutor, hpanelCustomDir, backupDir string) *NginxCustomWriteAction {
	return &NginxCustomWriteAction{
		executor:        executor,
		hpanelCustomDir: hpanelCustomDir,
		backupDir:       backupDir,
		isLinux:         true,
	}
}

func (a *NginxCustomWriteAction) Name() string {
	return "nginx.custom.write"
}

// validateNoInjection ensures a string contains no ;, {, }, $, quotes, backticks or newlines.
func validateNoInjection(field, val string) error {
	if strings.ContainsAny(val, ";{}$'\"`\r\n") {
		return fmt.Errorf("field '%s' contains forbidden characters (;{} $'\"` or newlines)", field)
	}
	return nil
}

func (a *NginxCustomWriteAction) Validate(payload []byte) error {
	var p NginxCustomWritePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateDomain(p.Domain); err != nil {
		return err
	}

	s := p.Settings

	// Validate redirects
	if len(s.Redirects) > 20 {
		return errors.New("maximum 20 redirects allowed")
	}
	for i, r := range s.Redirects {
		if err := validateNoInjection(fmt.Sprintf("redirect[%d].source", i), r.Source); err != nil {
			return err
		}
		if err := validateNoInjection(fmt.Sprintf("redirect[%d].target", i), r.Target); err != nil {
			return err
		}
		if !strings.HasPrefix(r.Source, "/") {
			return fmt.Errorf("redirect source must start with '/': %s", r.Source)
		}
		if strings.Contains(r.Source, "..") {
			return errors.New("redirect source cannot contain '..'")
		}
		if r.Code != 301 && r.Code != 302 {
			return fmt.Errorf("redirect code must be 301 or 302, got %d", r.Code)
		}

		// Prevent redirect loop
		cleanSource := strings.TrimRight(r.Source, "/")
		cleanTarget := strings.TrimRight(r.Target, "/")
		if cleanTarget == cleanSource ||
			cleanTarget == "http://"+p.Domain+cleanSource ||
			cleanTarget == "https://"+p.Domain+cleanSource ||
			cleanTarget == "http://www."+p.Domain+cleanSource ||
			cleanTarget == "https://www."+p.Domain+cleanSource {
			return fmt.Errorf("redirect loop detected for source '%s'", r.Source)
		}
	}

	// Validate custom error pages
	if s.CustomError404 != "" {
		if err := validateNoInjection("custom_error_404", s.CustomError404); err != nil {
			return err
		}
		if !strings.HasPrefix(s.CustomError404, "/") || strings.Contains(s.CustomError404, "..") {
			return errors.New("custom_error_404 must start with '/' and cannot contain '..'")
		}
	}
	if s.CustomError403 != "" {
		if err := validateNoInjection("custom_error_403", s.CustomError403); err != nil {
			return err
		}
		if !strings.HasPrefix(s.CustomError403, "/") || strings.Contains(s.CustomError403, "..") {
			return errors.New("custom_error_403 must start with '/' and cannot contain '..'")
		}
	}

	// Validate max upload body size
	if s.MaxUploadBodySizeMB != 0 {
		if s.MaxUploadBodySizeMB < 1 || s.MaxUploadBodySizeMB > 128 {
			return fmt.Errorf("max_upload_body_size_mb must be between 1 and 128, got %d", s.MaxUploadBodySizeMB)
		}
	}

	// Validate cache lifetime
	if s.CacheLifetimeStatic != "" {
		if err := validateNoInjection("cache_lifetime_static", s.CacheLifetimeStatic); err != nil {
			return err
		}
		allowedLifetimes := map[string]bool{"none": true, "1d": true, "7d": true, "30d": true, "1 day": true, "7 days": true, "30 days": true}
		if !allowedLifetimes[s.CacheLifetimeStatic] {
			return fmt.Errorf("invalid cache_lifetime_static '%s': must be none, 1d, 7d, or 30d", s.CacheLifetimeStatic)
		}
	}

	// Validate default index files
	allowedIndexes := map[string]bool{"index.php": true, "index.html": true, "index.htm": true}
	for _, idx := range s.DefaultIndex {
		if err := validateNoInjection("default_index", idx); err != nil {
			return err
		}
		if !allowedIndexes[idx] {
			return fmt.Errorf("invalid default index file '%s': only index.php, index.html, index.htm allowed", idx)
		}
	}

	return nil
}

func (a *NginxCustomWriteAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p NginxCustomWritePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	customConfName := fmt.Sprintf("%s.custom.conf", p.Domain)
	destPath := filepath.Join(a.hpanelCustomDir, customConfName)
	if err := ensurePathInside(a.hpanelCustomDir, destPath); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	renderedConfig := renderNginxCustomConfig(p.Domain, p.Settings)

	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"domain":  p.Domain,
				"content": renderedConfig,
				"dry_run": true,
			},
		}, nil
	}

	_ = os.MkdirAll(a.hpanelCustomDir, 0755)
	_ = os.MkdirAll(a.backupDir, 0700)

	// Backup current file if it exists
	var backupFile string
	hadPrevious := false
	if _, err := os.Stat(destPath); err == nil {
		hadPrevious = true
		backupFile = filepath.Join(a.backupDir, fmt.Sprintf("%s.%d", customConfName, time.Now().UnixNano()))
		_ = copyFile(destPath, backupFile)
		pruneBackups(a.backupDir, customConfName)
	}

	// Write new content atomically
	tmpPath, err := CreateConfigTemp(destPath, []byte(renderedConfig), 0644)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("creating temp file: %v", err)}, err
	}

	if err := CommitConfigFile(tmpPath, destPath, 0644); err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("committing file: %v", err)}, err
	}

	// Test syntax via nginx -t
	if a.isLinux {
		if _, err := a.executor.LookPath("nginx"); err == nil {
			testOut, testErr := a.executor.CombinedOutput(ctx, "nginx", "-t")
			if testErr != nil {
				// Rollback
				if hadPrevious && backupFile != "" {
					_ = copyFile(backupFile, destPath)
				} else {
					// Restore clean empty comment if it didn't exist previously
					_ = os.WriteFile(destPath, []byte(fmt.Sprintf("# Custom Nginx configuration for %s\n", p.Domain)), 0644)
				}
				errText := strings.TrimSpace(string(testOut))
				return &ActionResult{
					Success: false,
					Error:   fmt.Sprintf("nginx syntax test failed: %s", errText),
					Data: map[string]any{
						"nginx_output": errText,
						"rolled_back":  true,
					},
				}, fmt.Errorf("nginx syntax test failed: %s", errText)
			}

			if _, err := a.executor.LookPath("systemctl"); err == nil {
				_ = a.executor.Run(ctx, "systemctl", "reload", "nginx")
			}
			return &ActionResult{
				Success: true,
				Data: map[string]any{
					"domain":       p.Domain,
					"nginx_output": strings.TrimSpace(string(testOut)),
					"reloaded":     true,
				},
			}, nil
		}
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"domain":   p.Domain,
			"reloaded": false,
		},
	}, nil
}

func renderNginxCustomConfig(domain string, s NginxCustomSettingsDTO) string {
	var sb strings.Builder

	sb.WriteString(fmt.Sprintf("# HPanel Custom Configuration for %s\n", domain))
	sb.WriteString("# Managed automatically by panel-agent. Do not edit manually.\n\n")

	// Max upload body size
	if s.MaxUploadBodySizeMB > 0 {
		sb.WriteString(fmt.Sprintf("client_max_body_size %dm;\n\n", s.MaxUploadBodySizeMB))
	}

	// Default index files
	if len(s.DefaultIndex) > 0 {
		sb.WriteString(fmt.Sprintf("index %s;\n\n", strings.Join(s.DefaultIndex, " ")))
	}

	// Custom error pages
	if s.CustomError404 != "" {
		sb.WriteString(fmt.Sprintf("error_page 404 %s;\n", s.CustomError404))
	}
	if s.CustomError403 != "" {
		sb.WriteString(fmt.Sprintf("error_page 403 %s;\n", s.CustomError403))
	}
	if s.CustomError404 != "" || s.CustomError403 != "" {
		sb.WriteString("\n")
	}

	// Force HTTPS inside custom block if set
	if s.ForceHTTPS != nil && *s.ForceHTTPS {
		sb.WriteString("# Force HTTPS\n")
		sb.WriteString("if ($scheme != \"https\") {\n")
		sb.WriteString("    return 301 https://$host$request_uri;\n")
		sb.WriteString("}\n\n")
	}

	// Redirects
	if len(s.Redirects) > 0 {
		sb.WriteString("# Custom Redirects\n")
		for _, r := range s.Redirects {
			sb.WriteString(fmt.Sprintf("location = %s {\n", r.Source))
			sb.WriteString(fmt.Sprintf("    return %d %s;\n", r.Code, r.Target))
			sb.WriteString("}\n")
		}
		sb.WriteString("\n")
	}

	// Static cache
	lifetime := s.CacheLifetimeStatic
	if lifetime != "" && lifetime != "none" {
		normLifetime := lifetime
		switch lifetime {
		case "1 day":
			normLifetime = "1d"
		case "7 days":
			normLifetime = "7d"
		case "30 days":
			normLifetime = "30d"
		}
		sb.WriteString("# Static Assets Caching\n")
		sb.WriteString("location ~* \\.(?:css|js|png|jpg|jpeg|gif|svg|woff2)$ {\n")
		sb.WriteString(fmt.Sprintf("    expires %s;\n", normLifetime))
		sb.WriteString("    add_header Cache-Control \"public, no-transform\";\n")
		sb.WriteString("}\n\n")
	}

	// Clean URLs override: if explicitly set to false, disable clean URLs
	if s.CleanUrls != nil && !*s.CleanUrls {
		sb.WriteString("# Clean URLs Disabled\n")
		sb.WriteString("location ~ ^/[^.]+$ {\n")
		sb.WriteString("    try_files $uri $uri/ =404;\n")
		sb.WriteString("}\n\n")
	}

	return sb.String()
}

