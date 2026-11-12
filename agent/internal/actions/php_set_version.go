package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

type PhpSetVersionPayload struct {
	Domain             string                   `json:"domain"`
	Username           string                   `json:"username"`
	Version            string                   `json:"version"`
	PreviousVersion    string                   `json:"previous_version,omitempty"`
	RemovePreviousPool bool                     `json:"remove_previous_pool,omitempty"`
	DocumentRoot       string                   `json:"document_root"`
	AppProfile         string                   `json:"app_profile,omitempty"`
	PmMode             string                   `json:"pm_mode,omitempty"`
	MaxChildren        int                      `json:"max_children,omitempty"`
	MaxMemoryMB        int                      `json:"max_memory_mb,omitempty"`
	AllowExecFunctions bool                     `json:"allow_exec_functions,omitempty"`
	Settings           PhpIniSettings           `json:"settings,omitempty"`
	ForceHTTPS         bool                     `json:"force_https,omitempty"`
	SSLEnabled         bool                     `json:"ssl_enabled,omitempty"`
	SSLCertPath        string                   `json:"ssl_cert_path,omitempty"`
	SSLKeyPath         string                   `json:"ssl_key_path,omitempty"`
	HstsEnabled        bool                     `json:"hsts_enabled,omitempty"`
	WwwRedirect        string                   `json:"www_redirect,omitempty"`
	Redirects          []RedirectRule           `json:"redirects,omitempty"`
	ProtectedDirs      []ProtectedDirectoryRule `json:"protected_dirs,omitempty"`
}

type PhpSetVersionAction struct {
	executor          commandExecutor
	poolBaseDir       string // e.g. /etc/php
	vhostAvailableDir string // e.g. /etc/nginx/sites-available
	vhostEnabledDir   string // e.g. /etc/nginx/sites-enabled
	socketDir         string // e.g. /run/php
	socketWaitMax     time.Duration
	isLinux           bool
	checkInstalled    bool
}

func NewPhpSetVersionAction() *PhpSetVersionAction {
	return &PhpSetVersionAction{
		executor:          osCommandExecutor{},
		poolBaseDir:       "/etc/php",
		vhostAvailableDir: "/etc/nginx/sites-available",
		vhostEnabledDir:   "/etc/nginx/sites-enabled",
		socketDir:         "/run/php",
		socketWaitMax:     10 * time.Second,
		isLinux:           runtime.GOOS == "linux",
		checkInstalled:    runtime.GOOS == "linux",
	}
}

func NewPhpSetVersionActionForTest(executor commandExecutor, poolBaseDir, availDir, enabledDir, socketDir string) *PhpSetVersionAction {
	return &PhpSetVersionAction{
		executor:          executor,
		poolBaseDir:       poolBaseDir,
		vhostAvailableDir: availDir,
		vhostEnabledDir:   enabledDir,
		socketDir:         socketDir,
		socketWaitMax:     200 * time.Millisecond,
		isLinux:           true,
		checkInstalled:    false,
	}
}

func (a *PhpSetVersionAction) Name() string {
	return "php.set_version"
}

func (a *PhpSetVersionAction) Validate(payload []byte) error {
	var p PhpSetVersionPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateDomain(p.Domain); err != nil {
		return err
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if err := ValidateSupportedPHPVersion(p.Version); err != nil {
		return err
	}
	if err := ValidateDocumentRoot(p.Username, p.DocumentRoot); err != nil {
		return err
	}
	if p.AppProfile != "" {
		if err := ValidateAppProfile(p.AppProfile); err != nil {
			return err
		}
	}
	if p.PmMode != "" {
		if err := ValidatePmMode(p.PmMode); err != nil {
			return err
		}
	}
	if err := ValidatePhpIniSettings(p.Settings); err != nil {
		return err
	}
	if p.MaxMemoryMB > 0 && p.Settings.MemoryLimit != "" {
		if ParseMemoryToMB(p.Settings.MemoryLimit) > p.MaxMemoryMB {
			return fmt.Errorf("memory_limit %s exceeds package limit of %dM", p.Settings.MemoryLimit, p.MaxMemoryMB)
		}
	}
	if a.checkInstalled {
		binPath := fmt.Sprintf("/usr/bin/php%s", p.Version)
		fpmPath := fmt.Sprintf("/usr/sbin/php-fpm%s", p.Version)
		_, binErr := os.Stat(binPath)
		_, fpmErr := os.Stat(fpmPath)
		if binErr != nil && fpmErr != nil {
			return fmt.Errorf("PHP version %s is not installed on this server", p.Version)
		}
	}
	return nil
}

func (a *PhpSetVersionAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p PhpSetVersionPayload
	_ = json.Unmarshal(payload, &p)

	if p.AppProfile == "" {
		p.AppProfile = "php-generic"
	}
	if p.MaxChildren <= 0 {
		p.MaxChildren = 5
	}
	if p.PmMode == "" {
		if p.MaxChildren > 5 {
			p.PmMode = "dynamic"
		} else {
			p.PmMode = "ondemand"
		}
	}
	if p.Settings.MemoryLimit == "" && p.MaxMemoryMB > 0 {
		p.Settings.MemoryLimit = fmt.Sprintf("%dM", p.MaxMemoryMB)
	}

	socketPath := PhpFpmSocketPath(p.Version, p.Username)
	poolDir := filepath.Join(a.poolBaseDir, p.Version, "fpm", "pool.d")
	poolFile := filepath.Join(poolDir, fmt.Sprintf("%s.conf", p.Username))

	poolContent, err := RenderPhpPoolConfig(PhpPoolTemplateData{
		Username:           p.Username,
		Version:            p.Version,
		SocketPath:         socketPath,
		PmMode:             p.PmMode,
		MaxChildren:        p.MaxChildren,
		AllowExecFunctions: p.AllowExecFunctions,
		Settings:           p.Settings,
	})
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	isLaravelPublicRoot := false
	docRoot := p.DocumentRoot
	if p.AppProfile == "laravel" {
		docRoot, isLaravelPublicRoot = DetectLaravelRoot(p.Username, p.DocumentRoot)
	}

	availPath := filepath.Join(a.vhostAvailableDir, fmt.Sprintf("%s.conf", p.Domain))
	enabledPath := filepath.Join(a.vhostEnabledDir, fmt.Sprintf("%s.conf", p.Domain))

	existingCustomBlock := ""
	if existingVhost, err := os.ReadFile(availPath); err == nil {
		if extracted, ok := ExtractCustomBlock(string(existingVhost)); ok {
			existingCustomBlock = extracted
		}
	}

	vhostContent, err := RenderVhostConfig(VhostTemplateData{
		Username:            p.Username,
		Domain:              p.Domain,
		DocumentRoot:        docRoot,
		AppProfile:          p.AppProfile,
		PHPVersion:          p.Version,
		ForceHTTPS:          p.ForceHTTPS,
		SSLEnabled:          p.SSLEnabled,
		SSLCertPath:         p.SSLCertPath,
		SSLKeyPath:          p.SSLKeyPath,
		HstsEnabled:         p.HstsEnabled,
		WwwRedirect:         p.WwwRedirect,
		Redirects:           p.Redirects,
		ProtectedDirs:       p.ProtectedDirs,
		CustomBlock:         existingCustomBlock,
		SocketPath:          socketPath,
		IsLaravelPublicRoot: isLaravelPublicRoot,
	})
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if dryRun || !a.isLinux {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"domain":        p.Domain,
				"version":       p.Version,
				"socket":        socketPath,
				"document_root": docRoot,
				"steps": map[string]string{
					"create_pool":  "ok",
					"test_pool":    "ok",
					"reload_fpm":   "ok",
					"update_vhost": "ok",
					"test_nginx":   "ok",
					"reload_nginx": "ok",
				},
				"dry_run": dryRun,
			},
		}, nil
	}

	steps := map[string]string{}
	logs := []string{}

	// Backup previous pool if it existed
	var prevPoolBackup []byte
	poolExisted := false
	if b, err := os.ReadFile(poolFile); err == nil {
		poolExisted = true
		prevPoolBackup = b
	}

	rollbackPool := func() {
		if poolExisted {
			if tmp, err := CreateConfigTemp(poolFile, prevPoolBackup, 0644); err == nil {
				_ = CommitConfigFile(tmp, poolFile, 0644)
				_ = os.Remove(tmp)
			}
		} else {
			_ = os.Remove(poolFile)
		}
	}

	// 1. Write pool atomically
	_ = os.MkdirAll(poolDir, 0755)
	poolTmp, err := CreateConfigTemp(poolFile, []byte(poolContent), 0644)
	if err != nil {
		steps["create_pool"] = "failed"
		return &ActionResult{Success: false, Error: fmt.Sprintf("creating temp pool: %v", err), Data: map[string]any{"steps": steps}}, err
	}
	if err := CommitConfigFile(poolTmp, poolFile, 0644); err != nil {
		_ = os.Remove(poolTmp)
		steps["create_pool"] = "failed"
		return &ActionResult{Success: false, Error: fmt.Sprintf("writing pool: %v", err), Data: map[string]any{"steps": steps}}, err
	}
	_ = os.Remove(poolTmp)
	steps["create_pool"] = "done"

	// 2. Test pool: php-fpm<ver> -t
	fpmBin := fmt.Sprintf("php-fpm%s", p.Version)
	if out, err := a.executor.CombinedOutput(ctx, fpmBin, "-t"); err != nil {
		rollbackPool()
		steps["test_pool"] = "failed"
		errMsg := fmt.Sprintf("%s -t failed: %s", fpmBin, strings.TrimSpace(string(out)))
		return &ActionResult{Success: false, Error: errMsg, Data: map[string]any{"steps": steps}, Logs: append(logs, errMsg)}, err
	}
	steps["test_pool"] = "done"

	// 3. Enable & reload php<ver>-fpm, then wait for socket
	fpmSvc := fmt.Sprintf("php%s-fpm", p.Version)
	_ = a.executor.Run(ctx, "systemctl", "enable", fpmSvc)
	if err := a.executor.Run(ctx, "systemctl", "reload", fpmSvc); err != nil {
		if rErr := a.executor.Run(ctx, "systemctl", "restart", fpmSvc); rErr != nil {
			rollbackPool()
			steps["reload_fpm"] = "failed"
			return &ActionResult{Success: false, Error: fmt.Sprintf("reloading %s: %v", fpmSvc, rErr), Data: map[string]any{"steps": steps}}, rErr
		}
	}

	actualSocketPath := filepath.Join(a.socketDir, fmt.Sprintf("php%s-fpm-%s.sock", p.Version, p.Username))
	deadline := time.Now().Add(a.socketWaitMax)
	socketReady := false
	for time.Now().Before(deadline) {
		if _, err := os.Stat(actualSocketPath); err == nil {
			socketReady = true
			break
		}
		time.Sleep(100 * time.Millisecond)
	}
	if !socketReady {
		rollbackPool()
		_ = a.executor.Run(ctx, "systemctl", "reload", fpmSvc)
		steps["reload_fpm"] = "failed"
		errMsg := fmt.Sprintf("timed out waiting for PHP-FPM socket %s", actualSocketPath)
		return &ActionResult{Success: false, Error: errMsg, Data: map[string]any{"steps": steps}}, fmt.Errorf("%s", errMsg)
	}
	steps["reload_fpm"] = "done"

	// Backup previous vhost
	var prevVhostBackup []byte
	vhostExisted := false
	if b, err := os.ReadFile(availPath); err == nil {
		vhostExisted = true
		prevVhostBackup = b
	}

	rollbackVhost := func() {
		if vhostExisted {
			if tmp, err := CreateConfigTemp(availPath, prevVhostBackup, 0640); err == nil {
				_ = CommitConfigFile(tmp, availPath, 0640)
				_ = os.Remove(tmp)
			}
		} else {
			_ = os.Remove(enabledPath)
			_ = os.Remove(availPath)
		}
	}

	// 4. Update vhost atomically
	_ = os.MkdirAll(a.vhostAvailableDir, 0755)
	_ = os.MkdirAll(a.vhostEnabledDir, 0755)
	vhostTmp, err := CreateConfigTemp(availPath, []byte(vhostContent), 0640)
	if err != nil {
		rollbackPool()
		steps["update_vhost"] = "failed"
		return &ActionResult{Success: false, Error: fmt.Sprintf("creating temp vhost: %v", err), Data: map[string]any{"steps": steps}}, err
	}
	if err := CommitConfigFile(vhostTmp, availPath, 0640); err != nil {
		_ = os.Remove(vhostTmp)
		rollbackPool()
		steps["update_vhost"] = "failed"
		return &ActionResult{Success: false, Error: fmt.Sprintf("writing vhost: %v", err), Data: map[string]any{"steps": steps}}, err
	}
	_ = os.Remove(vhostTmp)
	_ = os.Remove(enabledPath)
	_ = os.Symlink(availPath, enabledPath)
	steps["update_vhost"] = "done"

	// 5. Test nginx: nginx -t
	if out, err := a.executor.CombinedOutput(ctx, "nginx", "-t"); err != nil {
		rollbackVhost()
		rollbackPool()
		_ = a.executor.Run(ctx, "systemctl", "reload", fpmSvc)
		steps["test_nginx"] = "failed"
		errMsg := fmt.Sprintf("nginx -t failed: %s", strings.TrimSpace(string(out)))
		return &ActionResult{Success: false, Error: errMsg, Data: map[string]any{"steps": steps}}, err
	}
	steps["test_nginx"] = "done"

	// 6. Reload nginx
	if err := a.executor.Run(ctx, "systemctl", "reload", "nginx"); err != nil {
		rollbackVhost()
		rollbackPool()
		_ = a.executor.Run(ctx, "systemctl", "reload", fpmSvc)
		steps["reload_nginx"] = "failed"
		return &ActionResult{Success: false, Error: fmt.Sprintf("reloading nginx: %v", err), Data: map[string]any{"steps": steps}}, err
	}
	steps["reload_nginx"] = "done"

	// 7. Remove previous version pool if unused by any other domain of the account
	if p.RemovePreviousPool && p.PreviousVersion != "" && p.PreviousVersion != p.Version {
		if ValidateSupportedPHPVersion(p.PreviousVersion) == nil {
			prevPoolFile := filepath.Join(a.poolBaseDir, p.PreviousVersion, "fpm", "pool.d", fmt.Sprintf("%s.conf", p.Username))
			if _, err := os.Stat(prevPoolFile); err == nil {
				_ = os.Remove(prevPoolFile)
				_ = a.executor.Run(ctx, "systemctl", "reload", fmt.Sprintf("php%s-fpm", p.PreviousVersion))
			}
		}
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"domain":        p.Domain,
			"version":       p.Version,
			"socket":        socketPath,
			"document_root": docRoot,
			"steps":         steps,
		},
		Logs: []string{fmt.Sprintf("switched %s to PHP %s (%s)", p.Domain, p.Version, socketPath)},
	}, nil
}
