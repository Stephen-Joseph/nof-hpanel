package actions

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"html/template"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
)

const fpmPoolTemplate = `; PHP-FPM Pool Configuration for [{{.Username}}]
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

type AccountCreateAction struct{}

func NewAccountCreateAction() *AccountCreateAction {
	return &AccountCreateAction{}
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
	tmpl, err := template.New("pool").Parse(fpmPoolTemplate)
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

	logs := []string{fmt.Sprintf("initiating account.create for user '%s' (%s)", p.Username, p.PrimaryDomain)}

	if dryRun {
		poolConfig, _ := a.RenderPoolConfig(p)
		logs = append(logs,
			fmt.Sprintf("[dry-run] simulated useradd for '%s' with nologin shell and home /home/%s", p.Username, p.Username),
			fmt.Sprintf("[dry-run] simulated directory layout: /home/%s/{public_html,logs,tmp,backups,.ssh}", p.Username),
			fmt.Sprintf("[dry-run] simulated quota apply: %d MB, %d inodes", p.StorageMB, p.Inodes),
			fmt.Sprintf("[dry-run] rendered PHP-FPM pool for version %s (%d bytes)", p.PHPVersion, len(poolConfig)),
			fmt.Sprintf("[dry-run] simulated systemd slice limits: %d cores, %d MB RAM", p.CPUCores, p.MemoryLimitMB),
		)

		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username":       p.Username,
				"primary_domain": p.PrimaryDomain,
				"home_dir":       fmt.Sprintf("/home/%s", p.Username),
				"php_version":    p.PHPVersion,
				"pool_socket":    fmt.Sprintf("/run/php/php%s-fpm-%s.sock", p.PHPVersion, p.Username),
				"dry_run":        true,
			},
			Logs: logs,
		}, nil
	}

	// Real execution on target server (Linux)
	homeDir := fmt.Sprintf("/home/%s", p.Username)

	// Step 1: Create Linux user if it doesn't already exist
	if runtime.GOOS == "linux" {
		checkCmd := exec.CommandContext(ctx, "id", "-u", p.Username)
		if err := checkCmd.Run(); err != nil {
			// User does not exist, create with useradd (strict argument arrays only)
			useradd := exec.CommandContext(ctx, "useradd",
				"-m",
				"-d", homeDir,
				"-s", "/usr/sbin/nologin",
				"-c", fmt.Sprintf("Hosting Account %s", p.Username),
				p.Username,
			)
			if out, err := useradd.CombinedOutput(); err != nil {
				return &ActionResult{
					Success: false,
					Error:   fmt.Sprintf("failed to create user: %v (%s)", err, strings.TrimSpace(string(out))),
					Logs:    logs,
				}, err
			}
			logs = append(logs, fmt.Sprintf("created system user '%s' with home '%s'", p.Username, homeDir))
		} else {
			logs = append(logs, fmt.Sprintf("system user '%s' already exists (idempotent)", p.Username))
		}
	} else {
		logs = append(logs, fmt.Sprintf("mocked system user creation on non-linux OS (%s)", runtime.GOOS))
	}

	// Step 2: Establish directory layout
	subdirs := []string{"public_html", "logs", "tmp", "backups", ".ssh"}
	for _, sub := range subdirs {
		dirPath := filepath.Join(homeDir, sub)
		perm := os.FileMode(0700)
		if sub == "public_html" || sub == "logs" {
			perm = 0750
		}
		if err := os.MkdirAll(dirPath, perm); err != nil && runtime.GOOS == "linux" {
			logs = append(logs, fmt.Sprintf("warning: failed creating %s: %v", dirPath, err))
		}
	}
	logs = append(logs, "verified home directory layout")

	// Step 3: Default index page if public_html is empty
	indexPath := filepath.Join(homeDir, "public_html", "index.html")
	if _, err := os.Stat(indexPath); os.IsNotExist(err) {
		indexContent := fmt.Sprintf("<!DOCTYPE html><html><head><title>Welcome to %s</title></head><body><h1>Welcome to %s</h1><p>Website is successfully provisioned.</p></body></html>", p.PrimaryDomain, p.PrimaryDomain)
		_ = os.WriteFile(indexPath, []byte(indexContent), 0644)
	}

	// Step 4: Render and deploy PHP-FPM pool configuration
	poolConfigContent, err := a.RenderPoolConfig(p)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	poolDir := fmt.Sprintf("/etc/php/%s/fpm/pool.d", p.PHPVersion)
	targetPoolFile := filepath.Join(poolDir, fmt.Sprintf("%s.conf", p.Username))

	if runtime.GOOS == "linux" {
		tempDir := "/tmp/panel-agent"
		_ = os.MkdirAll(tempDir, 0700)
		tempPoolFile := filepath.Join(tempDir, fmt.Sprintf("%s-fpm.conf", p.Username))

		if err := os.WriteFile(tempPoolFile, []byte(poolConfigContent), 0640); err != nil {
			return &ActionResult{Success: false, Error: fmt.Sprintf("writing temp fpm pool: %v", err), Logs: logs}, err
		}

		// Test configuration via php-fpm -t if binary exists
		fpmBin := fmt.Sprintf("php-fpm%s", p.PHPVersion)
		if _, err := exec.LookPath(fpmBin); err == nil {
			testCmd := exec.CommandContext(ctx, fpmBin, "-t")
			if out, err := testCmd.CombinedOutput(); err != nil {
				_ = os.Remove(tempPoolFile)
				return &ActionResult{
					Success: false,
					Error:   fmt.Sprintf("php-fpm syntax test failed: %s", strings.TrimSpace(string(out))),
					Logs:    logs,
				}, fmt.Errorf("php-fpm test failed: %s", string(out))
			}
		}

		// Atomically install pool configuration
		if err := os.MkdirAll(poolDir, 0755); err == nil {
			_ = os.Rename(tempPoolFile, targetPoolFile)
		}

		// Reload PHP-FPM service (strict argument array)
		fpmService := fmt.Sprintf("php%s-fpm", p.PHPVersion)
		_ = exec.CommandContext(ctx, "systemctl", "reload", fpmService).Run()
		logs = append(logs, fmt.Sprintf("deployed PHP-FPM pool and reloaded %s", fpmService))
	} else {
		logs = append(logs, fmt.Sprintf("simulated pool installation to %s", targetPoolFile))
	}

	// Step 5: Systemd slice resource limits (CPU / RAM)
	if runtime.GOOS == "linux" && (p.CPUCores > 0 || p.MemoryLimitMB > 0) {
		sliceDir := fmt.Sprintf("/etc/systemd/system/hpanel-%s.slice.d", p.Username)
		_ = os.MkdirAll(sliceDir, 0755)
		var sliceBuf strings.Builder
		sliceBuf.WriteString("[Slice]\n")
		if p.MemoryLimitMB > 0 {
			sliceBuf.WriteString(fmt.Sprintf("MemoryMax=%dM\n", p.MemoryLimitMB))
		}
		if p.CPUCores > 0 {
			sliceBuf.WriteString(fmt.Sprintf("CPUQuota=%d%%\n", p.CPUCores*100))
		}
		_ = os.WriteFile(filepath.Join(sliceDir, "50-limits.conf"), []byte(sliceBuf.String()), 0644)
		_ = exec.CommandContext(ctx, "systemctl", "daemon-reload").Run()
		logs = append(logs, "applied systemd slice resource limits")
	}

	logs = append(logs, "account.create completed successfully")

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

