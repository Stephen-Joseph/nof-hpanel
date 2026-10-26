package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestValidateLinuxUsername(t *testing.T) {
	valid := []string{"john", "user123", "alpha", "site42", "cust01"}
	for _, u := range valid {
		if err := ValidateLinuxUsername(u); err != nil {
			t.Errorf("expected '%s' to be valid, got: %v", u, err)
		}
	}

	invalid := []string{
		"",                        // empty
		"ab",                      // too short (min 3)
		"1user",                   // starts with number
		"John",                    // uppercase
		"user_name",               // underscore not allowed
		"user-name",               // hyphen not allowed
		"user.name",               // dot not allowed
		"waytoolongusername12345", // exceeds 16 chars
		"root",                    // reserved
		"www-data",                // reserved
		"daemon",                  // reserved
		"nginx",                   // reserved
	}
	for _, u := range invalid {
		if err := ValidateLinuxUsername(u); err == nil {
			t.Errorf("expected '%s' to be rejected, but it passed", u)
		}
	}
}

func TestValidateDomain(t *testing.T) {
	valid := []string{"example.com", "sub.domain.co.uk", "my-site.org", "app123.internal.io"}
	for _, d := range valid {
		if err := ValidateDomain(d); err != nil {
			t.Errorf("expected '%s' to be valid, got: %v", d, err)
		}
	}

	invalid := []string{
		"",
		"invalid_domain.com", // underscore
		"-leading-dash.com",
		"http://example.com", // url
		"example..com",       // double dot
	}
	for _, d := range invalid {
		if err := ValidateDomain(d); err == nil {
			t.Errorf("expected '%s' to be rejected, but it passed", d)
		}
	}
}

func TestAccountCreateTemplateAndDryRun(t *testing.T) {
	action := NewAccountCreateAction()

	payloadData := AccountCreatePayload{
		Username:      "cust01",
		PrimaryDomain: "cust01.example.com",
		PHPVersion:    "8.3",
		StorageMB:     10240,
		Inodes:        100000,
		PHPWorkers:    8,
		PHPMemoryMB:   512,
		CPUCores:      2,
		MemoryLimitMB: 1024,
	}

	// 1. Test template rendering
	rendered, err := action.RenderPoolConfig(payloadData)
	if err != nil {
		t.Fatalf("failed to render pool config: %v", err)
	}

	expectedDirectives := []string{
		"[cust01]",
		"user = cust01",
		"listen = /run/php/php8.3-fpm-cust01.sock",
		"pm.max_children = 8",
		"php_admin_value[memory_limit] = 512M",
		"php_admin_value[open_basedir] = /home/cust01/:/tmp/:/usr/share/php/",
		"php_admin_value[disable_functions] = exec,passthru,shell_exec,system",
	}
	for _, directive := range expectedDirectives {
		if !strings.Contains(rendered, directive) {
			t.Errorf("expected rendered config to contain '%s', got:\n%s", directive, rendered)
		}
	}

	// 2. Test Validation
	payloadBytes, _ := json.Marshal(payloadData)
	if err := action.Validate(payloadBytes); err != nil {
		t.Fatalf("validation failed unexpectedly: %v", err)
	}

	// 3. Test Dry-Run Execution
	res, err := action.Execute(context.Background(), payloadBytes, true)
	if err != nil {
		t.Fatalf("dry run execution failed: %v", err)
	}
	if !res.Success {
		t.Fatalf("expected res.Success to be true")
	}
	if res.Data["username"] != "cust01" {
		t.Errorf("expected username cust01, got %v", res.Data["username"])
	}
	if dry, ok := res.Data["dry_run"].(bool); !ok || !dry {
		t.Errorf("expected dry_run to be true")
	}
}

func TestAccountSuspendAndUnsuspendDryRun(t *testing.T) {
	suspendAction := NewAccountSuspendAction()
	unsuspendAction := NewAccountUnsuspendAction()

	payload, _ := json.Marshal(AccountSuspendPayload{
		Username: "cust02",
		Reason:   "Invoice overdue #442",
	})

	// Suspend
	if err := suspendAction.Validate(payload); err != nil {
		t.Fatalf("suspend validate failed: %v", err)
	}
	resSuspend, err := suspendAction.Execute(context.Background(), payload, true)
	if err != nil || !resSuspend.Success {
		t.Fatalf("suspend dry-run failed: %v", err)
	}
	if resSuspend.Data["suspended"] != true {
		t.Fatalf("expected suspended=true")
	}

	// Unsuspend
	unsuspendPayload, _ := json.Marshal(AccountUnsuspendPayload{Username: "cust02"})
	if err := unsuspendAction.Validate(unsuspendPayload); err != nil {
		t.Fatalf("unsuspend validate failed: %v", err)
	}
	resUnsuspend, err := unsuspendAction.Execute(context.Background(), unsuspendPayload, true)
	if err != nil || !resUnsuspend.Success {
		t.Fatalf("unsuspend dry-run failed: %v", err)
	}
	if resUnsuspend.Data["suspended"] != false {
		t.Fatalf("expected suspended=false")
	}
}

func TestAccountUpgradeDryRun(t *testing.T) {
	upgradeAction := NewAccountUpgradeAction(nil)
	payload, _ := json.Marshal(AccountUpgradePayload{
		Username:    "cust03",
		PHPVersion:  "8.4",
		StorageMB:   20480,
		Inodes:      250000,
		PHPWorkers:  16,
		PHPMemoryMB: 1024,
	})

	if err := upgradeAction.Validate(payload); err != nil {
		t.Fatalf("upgrade validate failed: %v", err)
	}

	res, err := upgradeAction.Execute(context.Background(), payload, true)
	if err != nil || !res.Success {
		t.Fatalf("upgrade dry-run failed: %v", err)
	}
	if res.Data["php_version"] != "8.4" {
		t.Fatalf("expected php_version 8.4, got %v", res.Data["php_version"])
	}
}

func TestAccountTerminateValidationAndToken(t *testing.T) {
	action := NewAccountTerminateAction()

	// Bad token
	badPayload, _ := json.Marshal(AccountTerminatePayload{
		Username:     "cust04",
		ConfirmToken: "WRONG-TOKEN",
	})
	if err := action.Validate(badPayload); err == nil {
		t.Fatal("expected validation to fail on incorrect token")
	}

	// Correct token
	correctPayload, _ := json.Marshal(AccountTerminatePayload{
		Username:     "cust04",
		ConfirmToken: "TERMINATE-cust04",
		TakeBackup:   true,
	})
	if err := action.Validate(correctPayload); err != nil {
		t.Fatalf("expected valid token to pass, got: %v", err)
	}

	res, err := action.Execute(context.Background(), correctPayload, true)
	if err != nil || !res.Success {
		t.Fatalf("terminate dry-run failed: %v", err)
	}
	if res.Data["terminated"] != true {
		t.Fatal("expected terminated=true")
	}
}

func TestAccountTerminateCleansUpVhostsAndPools(t *testing.T) {
	tempBase := t.TempDir()
	homeBase := filepath.Join(tempBase, "home")
	availDir := filepath.Join(tempBase, "sites-available")
	enabledDir := filepath.Join(tempBase, "sites-enabled")
	phpBase := filepath.Join(tempBase, "php")
	sshdDir := filepath.Join(tempBase, "sshd_config.d")

	_ = os.MkdirAll(homeBase, 0755)
	_ = os.MkdirAll(availDir, 0755)
	_ = os.MkdirAll(enabledDir, 0755)
	_ = os.MkdirAll(filepath.Join(phpBase, "8.3", "fpm", "pool.d"), 0755)
	_ = os.MkdirAll(filepath.Join(phpBase, "8.4", "fpm", "pool.d"), 0755)
	_ = os.MkdirAll(sshdDir, 0755)

	// Create user home directory with subdirectories and root-owned/read-only file
	userHome := filepath.Join(homeBase, "smoketest1")
	_ = os.MkdirAll(filepath.Join(userHome, "public_html"), 0755)
	_ = os.MkdirAll(filepath.Join(userHome, "logs"), 0750)
	_ = os.MkdirAll(filepath.Join(userHome, "tmp"), 0750)
	_ = os.MkdirAll(filepath.Join(userHome, "backups"), 0750)
	_ = os.MkdirAll(filepath.Join(userHome, ".ssh"), 0700)
	rootFile := filepath.Join(userHome, "public_html", "root-file.txt")
	_ = os.WriteFile(rootFile, []byte("root owned"), 0400)

	// Create user vhost
	userVhostAvail := filepath.Join(availDir, "smoketest1.test.conf")
	_ = os.WriteFile(userVhostAvail, []byte(fmt.Sprintf("server { root %s/public_html; }", userHome)), 0644)
	userVhostEnabled := filepath.Join(enabledDir, "smoketest1.test.conf")
	_ = os.Symlink(userVhostAvail, userVhostEnabled)

	// Create user PHP pool
	userPool83 := filepath.Join(phpBase, "8.3", "fpm", "pool.d", "smoketest1.conf")
	_ = os.WriteFile(userPool83, []byte("[smoketest1]"), 0640)
	userPool84 := filepath.Join(phpBase, "8.4", "fpm", "pool.d", "smoketest1.conf")
	_ = os.WriteFile(userPool84, []byte("[smoketest1]"), 0640)

	// Create sshd match config
	sshdConf := filepath.Join(sshdDir, "hpanel-smoketest1.conf")
	_ = os.WriteFile(sshdConf, []byte("Match User smoketest1\nChrootDirectory /home/%u\n"), 0644)

	// Create other user's files to verify isolation
	otherVhostAvail := filepath.Join(availDir, "otheruser.test.conf")
	_ = os.WriteFile(otherVhostAvail, []byte("server { root /home/otheruser/public_html; }"), 0644)
	otherVhostEnabled := filepath.Join(enabledDir, "otheruser.test.conf")
	_ = os.Symlink(otherVhostAvail, otherVhostEnabled)

	fakeExec := &fakeCommandExecutor{}
	action := NewAccountTerminateActionForTest(fakeExec, homeBase, availDir, enabledDir, phpBase, sshdDir)

	payload, _ := json.Marshal(AccountTerminatePayload{
		Username:     "smoketest1",
		ConfirmToken: "TERMINATE-smoketest1",
		TakeBackup:   false,
		Domains:      []string{"smoketest1.test"},
	})

	res, err := action.Execute(context.Background(), payload, false)
	if err != nil || !res.Success {
		t.Fatalf("execute failed: %v", err)
	}

	// Verify smoketest1 home directory completely removed
	if _, err := os.Stat(userHome); !os.IsNotExist(err) {
		t.Errorf("expected user home directory to be completely removed")
	}

	// Verify smoketest1 vhost files removed
	if _, err := os.Stat(userVhostAvail); !os.IsNotExist(err) {
		t.Errorf("expected user vhost in available to be removed")
	}
	if _, err := os.Lstat(userVhostEnabled); !os.IsNotExist(err) {
		t.Errorf("expected user vhost link in enabled to be removed")
	}

	// Verify PHP pools removed
	if _, err := os.Stat(userPool83); !os.IsNotExist(err) {
		t.Errorf("expected user pool 8.3 to be removed")
	}
	if _, err := os.Stat(userPool84); !os.IsNotExist(err) {
		t.Errorf("expected user pool 8.4 to be removed")
	}

	// Verify sshd config removed
	if _, err := os.Stat(sshdConf); !os.IsNotExist(err) {
		t.Errorf("expected sshd config to be removed")
	}

	// Verify other user's files remain untouched
	if _, err := os.Stat(otherVhostAvail); os.IsNotExist(err) {
		t.Errorf("other user vhost in available should not be removed")
	}
	if _, err := os.Lstat(otherVhostEnabled); os.IsNotExist(err) {
		t.Errorf("other user vhost in enabled should not be removed")
	}
}

func TestAccountTerminateReportsLeftoversExplicitly(t *testing.T) {
	tempBase := t.TempDir()
	homeBase := filepath.Join(tempBase, "home")
	availDir := filepath.Join(tempBase, "sites-available")
	enabledDir := filepath.Join(tempBase, "sites-enabled")
	phpBase := filepath.Join(tempBase, "php")
	sshdDir := filepath.Join(tempBase, "sshd_config.d")

	_ = os.MkdirAll(homeBase, 0755)
	_ = os.MkdirAll(availDir, 0755)
	_ = os.MkdirAll(enabledDir, 0755)
	_ = os.MkdirAll(filepath.Join(phpBase, "8.3", "fpm", "pool.d"), 0755)
	_ = os.MkdirAll(sshdDir, 0755)

	// Create user home
	userHome := filepath.Join(homeBase, "lockeduser")
	_ = os.MkdirAll(userHome, 0755)

	// Make sshdConf a non-empty directory so os.Remove(sshdConf) cannot delete it
	sshdConf := filepath.Join(sshdDir, "hpanel-lockeduser.conf")
	_ = os.MkdirAll(sshdConf, 0755)
	_ = os.WriteFile(filepath.Join(sshdConf, "unremovable.txt"), []byte("stubborn"), 0644)

	fakeExec := &fakeCommandExecutor{}
	action := NewAccountTerminateActionForTest(fakeExec, homeBase, availDir, enabledDir, phpBase, sshdDir)

	payload, _ := json.Marshal(AccountTerminatePayload{
		Username:     "lockeduser",
		ConfirmToken: "TERMINATE-lockeduser",
		TakeBackup:   false,
	})

	res, err := action.Execute(context.Background(), payload, false)
	if err == nil {
		t.Fatal("expected termination to fail when leftover path exists")
	}
	if res.Success {
		t.Fatal("expected res.Success=false on leftover path")
	}
	if !strings.Contains(err.Error(), "leftover paths remain after termination") {
		t.Errorf("expected error message to mention leftover paths, got: %v", err)
	}
	if !strings.Contains(err.Error(), sshdConf) {
		t.Errorf("expected error to explicitly include leftover path %s, got: %v", sshdConf, err)
	}
	leftovers, ok := res.Data["leftovers"].([]string)
	if !ok || len(leftovers) == 0 {
		t.Errorf("expected res.Data[leftovers] to contain leftover paths, got: %v", res.Data["leftovers"])
	}
}
