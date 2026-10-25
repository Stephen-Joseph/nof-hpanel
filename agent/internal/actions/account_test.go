package actions

import (
	"context"
	"encoding/json"
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
