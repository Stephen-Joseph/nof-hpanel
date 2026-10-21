package actions

import (
	"context"
	"encoding/json"
	"strings"
	"testing"
)

func TestPhpPoolGoldenTemplate(t *testing.T) {
	data := PhpPoolTemplateData{
		Username:    "alice",
		Version:     "8.3",
		PmMode:      "ondemand",
		MaxChildren: 10,
		Settings: PhpIniSettings{
			MemoryLimit:       "512M",
			UploadMaxFilesize: "64M",
			PostMaxSize:       "64M",
			MaxExecutionTime:  120,
			DisplayErrors:     "off",
		},
	}

	cfg, err := RenderPhpPoolConfig(data)
	if err != nil {
		t.Fatalf("failed rendering pool config: %v", err)
	}

	expectedClauses := []string{
		"[alice]",
		"user = alice",
		"group = alice",
		"listen = /run/php/php8.3-fpm-alice.sock",
		"pm = ondemand",
		"pm.max_children = 10",
		"open_basedir] = /home/alice/:/tmp/:/var/tmp/",
		"memory_limit] = 512M",
		"upload_max_filesize] = 64M",
		"max_execution_time] = 120",
		"display_errors] = off",
	}

	for _, exp := range expectedClauses {
		if !strings.Contains(cfg, exp) {
			t.Errorf("expected pool config to contain '%s', got:\n%s", exp, cfg)
		}
	}
}

func TestPhpValidation(t *testing.T) {
	// Valid version
	if err := ValidateSupportedPHPVersion("8.3"); err != nil {
		t.Errorf("expected 8.3 valid, got: %v", err)
	}
	// Invalid version
	if err := ValidateSupportedPHPVersion("7.4"); err == nil {
		t.Errorf("expected error for PHP 7.4, got nil")
	}

	// Valid INI
	validIni := PhpIniSettings{
		MemoryLimit:       "256M",
		UploadMaxFilesize: "32M",
		MaxExecutionTime:  60,
		DisplayErrors:     "on",
	}
	if err := ValidatePhpIniSettings(validIni); err != nil {
		t.Errorf("expected valid ini, got: %v", err)
	}

	// Invalid memory
	invalidIni := PhpIniSettings{
		MemoryLimit: "unlimited",
	}
	if err := ValidatePhpIniSettings(invalidIni); err == nil {
		t.Errorf("expected error for invalid memory_limit 'unlimited', got nil")
	}

	// Invalid max execution time
	invalidTimeout := PhpIniSettings{
		MaxExecutionTime: 99999,
	}
	if err := ValidatePhpIniSettings(invalidTimeout); err == nil {
		t.Errorf("expected error for execution time > 600s, got nil")
	}
}

func TestPhpPoolWriteActionDryRun(t *testing.T) {
	action := NewPhpPoolWriteAction()

	payload, _ := json.Marshal(PhpPoolWritePayload{
		Username:    "charlie",
		Version:     "8.4",
		PmMode:      "dynamic",
		MaxChildren: 8,
		MaxMemoryMB: 512,
		Settings: PhpIniSettings{
			MemoryLimit: "256M",
		},
	})

	if err := action.Validate(payload); err != nil {
		t.Fatalf("validation failed: %v", err)
	}

	res, err := action.Execute(context.Background(), payload, true)
	if err != nil || !res.Success {
		t.Fatalf("execute failed: %v (%v)", err, res.Error)
	}

	if res.Data["socket"] != "/run/php/php8.4-fpm-charlie.sock" {
		t.Errorf("expected socket /run/php/php8.4-fpm-charlie.sock, got %v", res.Data["socket"])
	}
}

func TestPhpPoolMemoryQuotaEnforced(t *testing.T) {
	action := NewPhpPoolWriteAction()

	payload, _ := json.Marshal(PhpPoolWritePayload{
		Username:    "charlie",
		Version:     "8.3",
		MaxMemoryMB: 256,
		Settings: PhpIniSettings{
			MemoryLimit: "512M", // Exceeds package limit 256M!
		},
	})

	err := action.Validate(payload)
	if err == nil {
		t.Fatalf("expected validation failure when memory_limit exceeds package limit, got nil")
	}
	if !strings.Contains(err.Error(), "exceeds package limit") {
		t.Errorf("expected error message to mention package limit, got: %v", err)
	}
}

func TestPhpListAndStatusActionsDryRun(t *testing.T) {
	listAction := NewPhpListVersionsAction()
	listRes, err := listAction.Execute(context.Background(), nil, true)
	if err != nil || !listRes.Success {
		t.Fatalf("list versions failed: %v (%v)", err, listRes.Error)
	}

	statusAction := NewPhpFpmStatusAction()
	statusPayload, _ := json.Marshal(PhpFpmStatusPayload{Username: "alice", Version: "8.3"})
	statusRes, err := statusAction.Execute(context.Background(), statusPayload, true)
	if err != nil || !statusRes.Success {
		t.Fatalf("fpm status failed: %v (%v)", err, statusRes.Error)
	}
}

