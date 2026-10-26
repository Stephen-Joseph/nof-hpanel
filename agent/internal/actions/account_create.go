package actions

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"html/template"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
)

const accountCreateFpmPoolTemplate = `; PHP-FPM Pool Configuration for [{{.Username}}]
[{{.Username}}]
user = {{.Username}}
group = {{.Username}}

listen = /run/php/php{{.PHPVersion}}-fpm-{{.Username}}.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = ondemand
pm.max_children = {{.PHPWorkers}}
pm.process_idle_timeout = 10s
pm.max_requests = 500

chdir = /home/{{.Username}}

php_admin_value[open_basedir] = /home/{{.Username}}/:/tmp/:/usr/share/php/
php_admin_value[upload_tmp_dir] = /home/{{.Username}}/tmp
php_admin_value[session.save_path] = /home/{{.Username}}/tmp
php_admin_value[memory_limit] = {{.PHPMemoryMB}}M
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,curl_multi_exec,parse_ini_file,show_source
php_admin_flag[log_errors] = on
php_admin_value[error_log] = /home/{{.Username}}/logs/php_error.log
`

const accountCreateNginxVhostTemplate = `server {
    listen 80;
    listen [::]:80;
    server_name {{.ServerNames}};

    root /home/{{.Username}}/public_html;
    index index.php index.html;

    access_log /home/{{.Username}}/logs/access.log;
    error_log /home/{{.Username}}/logs/error.log;

    location ^~ /.well-known/acme-challenge/ {
        default_type "text/plain";
        root /home/{{.Username}}/public_html;
    }

    location / {
        try_files $uri $uri/ /index.html /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php{{.PHPVersion}}-fpm-{{.Username}}.sock;
    }
}
`

type commandExecutor interface {
	CombinedOutput(ctx context.Context, name string, args ...string) ([]byte, error)
	Run(ctx context.Context, name string, args ...string) error
	LookPath(file string) (string, error)
}

type osCommandExecutor struct{}

func (osCommandExecutor) CombinedOutput(ctx context.Context, name string, args ...string) ([]byte, error) {
	return exec.CommandContext(ctx, name, args...).CombinedOutput()
}

func (osCommandExecutor) Run(ctx context.Context, name string, args ...string) error {
	return exec.CommandContext(ctx, name, args...).Run()
}

func (osCommandExecutor) LookPath(file string) (string, error) {
	return exec.LookPath(file)
}

type AccountCreatePayload struct {
	Username      string `json:"username"`
	PrimaryDomain string `json:"primary_domain"`
	PHPVersion    string `json:"php_version"`
	StorageMB     int64  `json:"storage_mb"`
	Inodes        int64  `json:"inodes"`
	PHPWorkers    int    `json:"php_workers"`
	PHPMemoryMB   int    `json:"php_memory_mb"`
	CPUCores      int    `json:"cpu_cores,omitempty"`
	MemoryLimitMB int    `json:"memory_limit_mb,omitempty"`
}

func (p AccountCreatePayload) ServerNames() string {
	bare := strings.TrimPrefix(p.PrimaryDomain, "www.")
	return fmt.Sprintf("%s www.%s", bare, bare)
}

type AccountCreateAction struct {
	executor          commandExecutor
	homeBaseDir       string
	tempDir           string
	phpConfigBaseDir  string
	vhostAvailableDir string
	vhostEnabledDir   string
	systemdConfigDir  string
	sshdConfigDir     string
	isLinux           bool
}

func NewAccountCreateAction() *AccountCreateAction {
	return &AccountCreateAction{
		executor:          osCommandExecutor{},
		homeBaseDir:       "/home",
		tempDir:           "/var/lib/panel-agent/tmp",
		phpConfigBaseDir:  "/etc/php",
		vhostAvailableDir: "/etc/nginx/sites-available",
		vhostEnabledDir:   "/etc/nginx/sites-enabled",
		systemdConfigDir:  "/etc/systemd/system",
		sshdConfigDir:     "/etc/ssh/sshd_config.d",
		isLinux:           runtime.GOOS == "linux",
	}
}

func NewAccountCreateActionForTest(executor commandExecutor, homeBaseDir, tempDir, phpConfigBaseDir, vhostAvailableDir, vhostEnabledDir, systemdConfigDir string) *AccountCreateAction {
	return &AccountCreateAction{
		executor:          executor,
		homeBaseDir:       homeBaseDir,
		tempDir:           tempDir,
		phpConfigBaseDir:  phpConfigBaseDir,
		vhostAvailableDir: vhostAvailableDir,
		vhostEnabledDir:   vhostEnabledDir,
		systemdConfigDir:  systemdConfigDir,
		sshdConfigDir:     filepath.Join(filepath.Dir(tempDir), "etc", "ssh", "sshd_config.d"),
		isLinux:           true,
	}
}

func (a *AccountCreateAction) WithSshdConfigDir(dir string) *AccountCreateAction {
	a.sshdConfigDir = dir
	return a
}

func (a *AccountCreateAction) Name() string {
	return "account.create"
}

func (a *AccountCreateAction) Validate(payload []byte) error {
	var p AccountCreatePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}

	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if err := ValidateDomain(p.PrimaryDomain); err != nil {
		return err
	}
	if p.PHPVersion == "" {
		p.PHPVersion = "8.3"
	}
	if err := ValidatePHPVersion(p.PHPVersion); err != nil {
		return err
	}
	if p.StorageMB <= 0 {
		return errors.New("storage_mb must be greater than 0")
	}
	if p.Inodes <= 0 {
		return errors.New("inodes must be greater than 0")
	}
	if p.PHPWorkers <= 0 {
		return errors.New("php_workers must be greater than 0")
	}
	if p.PHPMemoryMB <= 0 {
		return errors.New("php_memory_mb must be greater than 0")
	}

	return nil
}

func (a *AccountCreateAction) RenderPoolConfig(p AccountCreatePayload) (string, error) {
	tmpl, err := template.New("pool").Parse(accountCreateFpmPoolTemplate)
	if err != nil {
		return "", err
	}
	var buf bytes.Buffer
	if err := tmpl.Execute(&buf, p); err != nil {
		return "", err
	}
	return buf.String(), nil
}

func (a *AccountCreateAction) renderVhostConfig(p AccountCreatePayload) (string, error) {
	tmpl, err := template.New("vhost").Parse(accountCreateNginxVhostTemplate)
	if err != nil {
		return "", err
	}

	var buf bytes.Buffer
	if err := tmpl.Execute(&buf, p); err != nil {
		return "", err
	}

	return buf.String(), nil
}

func (a *AccountCreateAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p AccountCreatePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	if p.PHPVersion == "" {
		p.PHPVersion = "8.3"
	}

	homeDir := filepath.Join(a.homeBaseDir, p.Username)
	logs := []string{fmt.Sprintf("initiating account.create for user '%s' (%s)", p.Username, p.PrimaryDomain)}

	if dryRun {
		poolConfig, _ := a.RenderPoolConfig(p)
		logs = append(logs,
			fmt.Sprintf("[dry-run] simulated useradd for '%s' with nologin shell and home %s", p.Username, homeDir),
			fmt.Sprintf("[dry-run] simulated directory layout: %s/{public_html,logs,tmp,backups,.ssh}", homeDir),
			fmt.Sprintf("[dry-run] simulated quota apply: %d MB, %d inodes", p.StorageMB, p.Inodes),
			fmt.Sprintf("[dry-run] rendered PHP-FPM pool for version %s (%d bytes)", p.PHPVersion, len(poolConfig)),
			fmt.Sprintf("[dry-run] simulated Nginx vhost for %s", p.PrimaryDomain),
			fmt.Sprintf("[dry-run] simulated sshd chroot match block: /etc/ssh/sshd_config.d/hpanel-%s.conf", p.Username),
			fmt.Sprintf("[dry-run] simulated systemd slice limits: %d cores, %d MB RAM", p.CPUCores, p.MemoryLimitMB),
		)

		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username":       p.Username,
				"primary_domain": p.PrimaryDomain,
				"home_dir":       homeDir,
				"php_version":    p.PHPVersion,
				"pool_socket":    fmt.Sprintf("/run/php/php%s-fpm-%s.sock", p.PHPVersion, p.Username),
				"dry_run":        true,
			},
			Logs: logs,
		}, nil
	}

	if !a.isLinux {
		logs = append(logs, fmt.Sprintf("mocked system provisioning on non-linux OS (%s)", runtime.GOOS))
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username":       p.Username,
				"primary_domain": p.PrimaryDomain,
				"home_dir":       homeDir,
				"php_version":    p.PHPVersion,
				"pool_socket":    fmt.Sprintf("/run/php/php%s-fpm-%s.sock", p.PHPVersion, p.Username),
				"dry_run":        false,
			},
			Logs: logs,
		}, nil
	}

	if err := a.ensureUser(ctx, p.Username, homeDir); err != nil {
		return a.stepFailure("create_user", err, logs)
	}
	logs = append(logs, fmt.Sprintf("user '%s' is present", p.Username))

	if err := a.ensureDirectories(ctx, p.Username, homeDir); err != nil {
		return a.stepFailure("directories", err, logs)
	}
	logs = append(logs, "verified home directory layout")

	if err := a.ensureDefaultIndex(ctx, p.Username, homeDir, p.PrimaryDomain); err != nil {
		return a.stepFailure("directories", err, logs)
	}

	quotaSkipped, quotaSkipReason, err := a.applyQuota(ctx, p)
	if err != nil {
		return a.stepFailure("quota", err, logs)
	}
	if quotaSkipped {
		log.Printf("warning: quota unsupported on this filesystem")
		logs = append(logs, "warning: quota unsupported on this filesystem")
		logs = append(logs, fmt.Sprintf("step 'quota' skipped with reason: %s", quotaSkipReason))
	} else {
		logs = append(logs, fmt.Sprintf("applied quota settings (%d MB, %d inodes)", p.StorageMB, p.Inodes))
	}

	if err := a.deployFpmPool(ctx, p); err != nil {
		return a.stepFailure("php_pool", err, logs)
	}
	logs = append(logs, fmt.Sprintf("deployed PHP-FPM pool for PHP %s", p.PHPVersion))

	if err := a.deployVhost(ctx, p); err != nil {
		return a.stepFailure("vhost", err, logs)
	}
	logs = append(logs, fmt.Sprintf("deployed vhost for domain '%s'", p.PrimaryDomain))

	if err := a.deploySshdConfig(ctx, p); err != nil {
		return a.stepFailure("sftp_chroot", err, logs)
	}
	logs = append(logs, fmt.Sprintf("configured SFTP chroot match block for user '%s'", p.Username))

	if err := a.applySystemdSlice(ctx, p); err != nil {
		return a.stepFailure("systemd_slice", err, logs)
	}
	if p.CPUCores > 0 || p.MemoryLimitMB > 0 {
		logs = append(logs, "applied systemd slice resource limits")
	}

	logs = append(logs, "account.create completed successfully")

	data := map[string]any{
		"username":       p.Username,
		"primary_domain": p.PrimaryDomain,
		"home_dir":       homeDir,
		"php_version":    p.PHPVersion,
		"pool_socket":    fmt.Sprintf("/run/php/php%s-fpm-%s.sock", p.PHPVersion, p.Username),
		"dry_run":        false,
	}
	if quotaSkipped {
		data["quota_skipped"] = true
		data["quota_skipped_reason"] = quotaSkipReason
		data["skipped_steps"] = map[string]string{
			"quota": quotaSkipReason,
		}
	}

	return &ActionResult{
		Success: true,
		Data:    data,
		Logs:    logs,
	}, nil
}

func (a *AccountCreateAction) stepFailure(step string, err error, logs []string) (*ActionResult, error) {
	msg := fmt.Sprintf("step '%s' failed: %v", step, err)
	logs = append(logs, msg)

	return &ActionResult{
		Success: false,
		Error:   msg,
		Logs:    logs,
	}, err
}

func (a *AccountCreateAction) ensureUser(ctx context.Context, username, homeDir string) error {
	if err := a.executor.Run(ctx, "id", "-u", username); err == nil {
		return nil
	}

	out, err := a.executor.CombinedOutput(ctx, "useradd",
		"-m",
		"-d", homeDir,
		"-s", "/usr/sbin/nologin",
		"-c", fmt.Sprintf("Hosting Account %s", username),
		username,
	)
	if err != nil {
		trimmed := strings.TrimSpace(string(out))
		if strings.Contains(strings.ToLower(trimmed), "already exists") {
			return nil
		}
		return fmt.Errorf("failed to create user: %v (%s)", err, trimmed)
	}

	return nil
}

func (a *AccountCreateAction) ensureDirectories(ctx context.Context, username, homeDir string) error {
	// 1. Ensure home directory exists, set to 0711, and owned by username:username
	if err := os.MkdirAll(homeDir, 0711); err != nil {
		return fmt.Errorf("creating home directory %s: %w", homeDir, err)
	}
	if err := os.Chmod(homeDir, 0711); err != nil {
		return fmt.Errorf("chmod home directory %s: %w", homeDir, err)
	}
	_ = a.executor.Run(ctx, "chmod", "0711", homeDir)
	if err := a.executor.Run(ctx, "chown", fmt.Sprintf("%s:%s", username, username), homeDir); err != nil {
		return fmt.Errorf("chown home directory %s: %w", homeDir, err)
	}

	// 2. Subdirectories:
	// public_html: 0755
	// logs: 0750
	// tmp: 0750
	// backups: 0700
	// .ssh: 0700
	subdirs := []struct {
		name string
		perm os.FileMode
	}{
		{"public_html", 0755},
		{"logs", 0750},
		{"tmp", 0750},
		{"backups", 0700},
		{".ssh", 0700},
	}

	for _, sub := range subdirs {
		dirPath := filepath.Join(homeDir, sub.name)
		if err := os.MkdirAll(dirPath, sub.perm); err != nil {
			return fmt.Errorf("creating %s: %w", dirPath, err)
		}
		if err := os.Chmod(dirPath, sub.perm); err != nil {
			return fmt.Errorf("chmod %s: %w", dirPath, err)
		}
		_ = a.executor.Run(ctx, "chmod", fmt.Sprintf("%04o", sub.perm), dirPath)
		if err := a.executor.Run(ctx, "chown", fmt.Sprintf("%s:%s", username, username), dirPath); err != nil {
			return fmt.Errorf("chown %s: %w", dirPath, err)
		}
	}

	// 3. Ensure any existing subdirectories and files in public_html have correct permissions and ownership
	publicHtml := filepath.Join(homeDir, "public_html")
	if err := a.enforcePublicHtmlPermissions(ctx, username, publicHtml); err != nil {
		return err
	}

	return nil
}

func (a *AccountCreateAction) enforcePublicHtmlPermissions(ctx context.Context, username, publicHtml string) error {
	return filepath.Walk(publicHtml, func(path string, info os.FileInfo, err error) error {
		if err != nil {
			return err
		}
		if path == publicHtml {
			return nil
		}
		// Security: do not follow or chmod symlink targets outside public_html
		if info.Mode()&os.ModeSymlink != 0 {
			return nil
		}
		if info.IsDir() {
			if err := os.Chmod(path, 0755); err != nil {
				return fmt.Errorf("chmod %s: %w", path, err)
			}
		} else {
			if err := os.Chmod(path, 0644); err != nil {
				return fmt.Errorf("chmod %s: %w", path, err)
			}
		}
		if err := a.executor.Run(ctx, "chown", fmt.Sprintf("%s:%s", username, username), path); err != nil {
			return fmt.Errorf("chown %s: %w", path, err)
		}
		return nil
	})
}

func (a *AccountCreateAction) ensureDefaultIndex(ctx context.Context, username, homeDir, domain string) error {
	indexPath := filepath.Join(homeDir, "public_html", "index.html")
	if _, err := os.Stat(indexPath); err == nil {
		if err := os.Chmod(indexPath, 0644); err != nil {
			return fmt.Errorf("chmod %s: %w", indexPath, err)
		}
		if err := a.executor.Run(ctx, "chown", fmt.Sprintf("%s:%s", username, username), indexPath); err != nil {
			return fmt.Errorf("chown %s: %w", indexPath, err)
		}
		return nil
	} else if !errors.Is(err, os.ErrNotExist) {
		return fmt.Errorf("checking %s: %w", indexPath, err)
	}

	indexContent := fmt.Sprintf("<!DOCTYPE html><html><head><title>Welcome to %s</title></head><body><h1>Welcome to %s</h1><p>Website is successfully provisioned.</p></body></html>", domain, domain)
	if err := os.WriteFile(indexPath, []byte(indexContent), 0644); err != nil {
		return fmt.Errorf("writing %s: %w", indexPath, err)
	}
	if err := os.Chmod(indexPath, 0644); err != nil {
		return fmt.Errorf("chmod %s: %w", indexPath, err)
	}
	if err := a.executor.Run(ctx, "chown", fmt.Sprintf("%s:%s", username, username), indexPath); err != nil {
		return fmt.Errorf("chown %s: %w", indexPath, err)
	}

	return nil
}

func isQuotaUnsupported(msg string) bool {
	lower := strings.ToLower(msg)
	return strings.Contains(lower, "quota not enabled") ||
		strings.Contains(lower, "cannot find filesystem") ||
		strings.Contains(lower, "not supported") ||
		strings.Contains(lower, "quotactl")
}

func (a *AccountCreateAction) applyQuota(ctx context.Context, p AccountCreatePayload) (skipped bool, reason string, err error) {
	if _, err := a.executor.LookPath("setquota"); err != nil {
		return false, "", nil
	}

	blocks := p.StorageMB * 1024
	out, err := a.executor.CombinedOutput(ctx, "setquota",
		"-u",
		p.Username,
		fmt.Sprintf("%d", blocks),
		fmt.Sprintf("%d", blocks),
		fmt.Sprintf("%d", p.Inodes),
		fmt.Sprintf("%d", p.Inodes),
		"/",
	)
	if err != nil {
		combined := strings.TrimSpace(string(out))
		if combined != "" {
			if err.Error() != "" && !strings.Contains(combined, err.Error()) {
				combined += ": " + err.Error()
			}
		} else {
			combined = err.Error()
		}

		if isQuotaUnsupported(combined) {
			return true, "quota unsupported on this filesystem", nil
		}

		if strings.Contains(strings.ToLower(combined), "operation not permitted") {
			quotaonOut, quotaonErr := a.executor.CombinedOutput(ctx, "quotaon", "-p", "/")
			quotaonStr := strings.ToLower(string(quotaonOut))
			if quotaonErr != nil || strings.Contains(quotaonStr, "is off") || strings.Contains(quotaonStr, "not supported") || strings.Contains(quotaonStr, "disabled") || !strings.Contains(quotaonStr, "is on") {
				return true, "quota unsupported on this filesystem", nil
			}
		}

		return false, "", fmt.Errorf("setquota command failed: %s", combined)
	}

	return false, "", nil
}

func (a *AccountCreateAction) deployFpmPool(ctx context.Context, p AccountCreatePayload) error {
	poolConfigContent, err := a.RenderPoolConfig(p)
	if err != nil {
		return fmt.Errorf("rendering fpm pool: %w", err)
	}

	poolDir := filepath.Join(a.phpConfigBaseDir, p.PHPVersion, "fpm", "pool.d")
	if err := os.MkdirAll(poolDir, 0755); err != nil {
		return fmt.Errorf("creating pool directory: %w", err)
	}
	targetPoolFile := filepath.Join(poolDir, fmt.Sprintf("%s.conf", p.Username))

	tempPoolFile, err := CreateConfigTemp(targetPoolFile, []byte(poolConfigContent), 0640)
	if err != nil {
		return fmt.Errorf("writing temp fpm pool: %w", err)
	}
	defer os.Remove(tempPoolFile)

	fpmBin, err := a.resolveFpmBinary(p.PHPVersion)
	if err != nil {
		return err
	}
	if out, err := a.executor.CombinedOutput(ctx, fpmBin, "-t"); err != nil {
		return fmt.Errorf("php-fpm syntax test failed: %s", strings.TrimSpace(string(out)))
	}

	if err := CommitConfigFile(tempPoolFile, targetPoolFile, 0640); err != nil {
		return fmt.Errorf("installing pool config: %w", err)
	}

	if err := a.executor.Run(ctx, "systemctl", "reload", fmt.Sprintf("php%s-fpm", p.PHPVersion)); err != nil {
		return fmt.Errorf("reloading php%s-fpm: %w", p.PHPVersion, err)
	}

	return nil
}

func (a *AccountCreateAction) resolveFpmBinary(version string) (string, error) {
	candidates := []string{
		fmt.Sprintf("php-fpm%s", version),
		fmt.Sprintf("/usr/sbin/php-fpm%s", version),
		fmt.Sprintf("php-fpm%s", strings.ReplaceAll(version, ".", "")),
		fmt.Sprintf("/usr/sbin/php-fpm%s", strings.ReplaceAll(version, ".", "")),
	}
	for _, candidate := range candidates {
		if _, err := a.executor.LookPath(candidate); err == nil {
			return candidate, nil
		}
	}

	return "", fmt.Errorf("php-fpm binary not found for version %s", version)
}

func (a *AccountCreateAction) deployVhost(ctx context.Context, p AccountCreatePayload) error {
	if err := os.MkdirAll(a.vhostAvailableDir, 0755); err != nil {
		return fmt.Errorf("ensuring vhost available dir: %w", err)
	}
	if err := os.MkdirAll(a.vhostEnabledDir, 0755); err != nil {
		return fmt.Errorf("ensuring vhost enabled dir: %w", err)
	}

	if err := EnsureDefaultVhost(a.vhostAvailableDir, a.vhostEnabledDir); err != nil {
		return fmt.Errorf("ensuring default vhost: %w", err)
	}

	configContent, err := a.renderVhostConfig(p)
	if err != nil {
		return fmt.Errorf("rendering vhost template: %w", err)
	}

	targetPath := filepath.Join(a.vhostAvailableDir, fmt.Sprintf("%s.conf", p.PrimaryDomain))
	symlinkPath := filepath.Join(a.vhostEnabledDir, fmt.Sprintf("%s.conf", p.PrimaryDomain))

	tempPath, err := CreateConfigTemp(targetPath, []byte(configContent), 0644)
	if err != nil {
		return fmt.Errorf("writing temp vhost file: %w", err)
	}
	defer os.Remove(tempPath)

	if err := CommitConfigFile(tempPath, targetPath, 0644); err != nil {
		return fmt.Errorf("installing vhost file: %w", err)
	}

	if info, err := os.Lstat(symlinkPath); err == nil {
		if info.Mode()&os.ModeSymlink != 0 {
			currentTarget, readErr := os.Readlink(symlinkPath)
			if readErr == nil && currentTarget == targetPath {
				// already linked correctly
			} else {
				if removeErr := os.Remove(symlinkPath); removeErr != nil {
					return fmt.Errorf("removing old symlink: %w", removeErr)
				}
				if linkErr := os.Symlink(targetPath, symlinkPath); linkErr != nil {
					return fmt.Errorf("creating vhost symlink: %w", linkErr)
				}
			}
		} else {
			return fmt.Errorf("path exists and is not a symlink: %s", symlinkPath)
		}
	} else if errors.Is(err, os.ErrNotExist) {
		if linkErr := os.Symlink(targetPath, symlinkPath); linkErr != nil {
			return fmt.Errorf("creating vhost symlink: %w", linkErr)
		}
	} else {
		return fmt.Errorf("checking vhost symlink: %w", err)
	}

	if out, err := a.executor.CombinedOutput(ctx, "nginx", "-t"); err != nil {
		return fmt.Errorf("nginx config test failed: %s", strings.TrimSpace(string(out)))
	}
	if err := a.executor.Run(ctx, "systemctl", "reload", "nginx"); err != nil {
		return fmt.Errorf("reloading nginx: %w", err)
	}

	return nil
}

func (a *AccountCreateAction) applySystemdSlice(ctx context.Context, p AccountCreatePayload) error {
	if p.CPUCores <= 0 && p.MemoryLimitMB <= 0 {
		return nil
	}

	sliceDir := filepath.Join(a.systemdConfigDir, fmt.Sprintf("hpanel-%s.slice.d", p.Username))
	if err := os.MkdirAll(sliceDir, 0755); err != nil {
		return fmt.Errorf("creating slice directory: %w", err)
	}

	var sliceBuf strings.Builder
	sliceBuf.WriteString("[Slice]\n")
	if p.MemoryLimitMB > 0 {
		sliceBuf.WriteString(fmt.Sprintf("MemoryMax=%dM\n", p.MemoryLimitMB))
	}
	if p.CPUCores > 0 {
		sliceBuf.WriteString(fmt.Sprintf("CPUQuota=%d%%\n", p.CPUCores*100))
	}
	sliceFile := filepath.Join(sliceDir, "50-limits.conf")
	if err := WriteConfigFileAtomically(sliceFile, []byte(sliceBuf.String()), 0644); err != nil {
		return fmt.Errorf("writing slice limits: %w", err)
	}

	if err := a.executor.Run(ctx, "systemctl", "daemon-reload"); err != nil {
		return fmt.Errorf("systemd daemon-reload: %w", err)
	}

	return nil
}

func (a *AccountCreateAction) deploySshdConfig(ctx context.Context, p AccountCreatePayload) error {
	sshdDir := a.sshdConfigDir
	if sshdDir == "" {
		sshdDir = "/etc/ssh/sshd_config.d"
	}
	if err := os.MkdirAll(sshdDir, 0755); err != nil {
		return fmt.Errorf("failed to create sshd_config.d: %w", err)
	}

	confPath := filepath.Join(sshdDir, fmt.Sprintf("hpanel-%s.conf", p.Username))
	content := fmt.Sprintf(`# HPanel SFTP Chroot configuration for %s
Match User %s
    ChrootDirectory /home/%s
    ForceCommand internal-sftp
    AllowTcpForwarding no
    X11Forwarding no
`, p.Username, p.Username, p.Username)

	if err := atomicWriteFile(confPath, []byte(content), 0644, 0, 0); err != nil {
		return fmt.Errorf("failed to write sshd match block: %w", err)
	}

	// Validate with sshd -t
	if out, err := a.executor.CombinedOutput(ctx, "sshd", "-t"); err != nil {
		_ = os.Remove(confPath)
		return fmt.Errorf("sshd -t validation failed: %s: %w", string(out), err)
	}

	// Reload ssh service
	if err := a.executor.Run(ctx, "systemctl", "reload", "ssh"); err != nil {
		_ = a.executor.Run(ctx, "systemctl", "reload", "sshd")
	}

	return nil
}
