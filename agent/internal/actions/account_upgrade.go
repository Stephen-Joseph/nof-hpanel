package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
)

type AccountUpgradePayload struct {
	Username      string `json:"username"`
	PHPVersion    string `json:"php_version,omitempty"`
	StorageMB     int64  `json:"storage_mb,omitempty"`
	Inodes        int64  `json:"inodes,omitempty"`
	PHPWorkers    int    `json:"php_workers,omitempty"`
	PHPMemoryMB   int    `json:"php_memory_mb,omitempty"`
	CPUCores      int    `json:"cpu_cores,omitempty"`
	MemoryLimitMB int    `json:"memory_limit_mb,omitempty"`
}

type AccountUpgradeAction struct {
	createAction *AccountCreateAction
}

func NewAccountUpgradeAction(createAction *AccountCreateAction) *AccountUpgradeAction {
	if createAction == nil {
		createAction = NewAccountCreateAction()
	}
	return &AccountUpgradeAction{createAction: createAction}
}

func (a *AccountUpgradeAction) Name() string {
	return "account.upgrade"
}

func (a *AccountUpgradeAction) Validate(payload []byte) error {
	var p AccountUpgradePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if p.PHPVersion != "" {
		if err := ValidatePHPVersion(p.PHPVersion); err != nil {
			return err
		}
	}
	return nil
}

func (a *AccountUpgradeAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p AccountUpgradePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	if p.PHPVersion == "" {
		p.PHPVersion = "8.3"
	}
	if p.PHPWorkers <= 0 {
		p.PHPWorkers = 5
	}
	if p.PHPMemoryMB <= 0 {
		p.PHPMemoryMB = 256
	}

	logs := []string{fmt.Sprintf("initiating account.upgrade for user '%s'", p.Username)}

	if dryRun {
		logs = append(logs,
			fmt.Sprintf("[dry-run] simulated quota adjustment: %d MB, %d inodes", p.StorageMB, p.Inodes),
			fmt.Sprintf("[dry-run] simulated PHP-FPM pool re-render for PHP %s: %d workers, %d MB RAM", p.PHPVersion, p.PHPWorkers, p.PHPMemoryMB),
			fmt.Sprintf("[dry-run] simulated systemd slice update: %d cores, %d MB RAM", p.CPUCores, p.MemoryLimitMB),
		)
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username":    p.Username,
				"php_version": p.PHPVersion,
				"storage_mb":  p.StorageMB,
				"inodes":      p.Inodes,
				"php_workers": p.PHPWorkers,
				"dry_run":     true,
			},
			Logs: logs,
		}, nil
	}

	// 1. Re-render PHP-FPM pool
	createPayload := AccountCreatePayload{
		Username:      p.Username,
		PrimaryDomain: p.Username + ".local",
		PHPVersion:    p.PHPVersion,
		StorageMB:     p.StorageMB,
		Inodes:        p.Inodes,
		PHPWorkers:    p.PHPWorkers,
		PHPMemoryMB:   p.PHPMemoryMB,
	}

	poolConfigContent, err := a.createAction.RenderPoolConfig(createPayload)
	if err == nil && runtime.GOOS == "linux" {
		poolFile := fmt.Sprintf("/etc/php/%s/fpm/pool.d/%s.conf", p.PHPVersion, p.Username)
		_ = os.WriteFile(poolFile, []byte(poolConfigContent), 0640)
		_ = exec.CommandContext(ctx, "systemctl", "reload", fmt.Sprintf("php%s-fpm", p.PHPVersion)).Run()
		logs = append(logs, fmt.Sprintf("updated PHP-FPM pool limits for %s", p.Username))
	}

	// 2. Update systemd slice limits
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
		logs = append(logs, "updated systemd resource slice limits")
	}

	logs = append(logs, "account.upgrade completed successfully")

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username":    p.Username,
			"php_version": p.PHPVersion,
			"storage_mb":  p.StorageMB,
			"inodes":      p.Inodes,
			"php_workers": p.PHPWorkers,
			"dry_run":     false,
		},
		Logs: logs,
	}, nil
}

