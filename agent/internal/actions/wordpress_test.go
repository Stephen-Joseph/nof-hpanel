package actions

import (
	"context"
	"encoding/json"
	"testing"
)

func TestWordPressValidation(t *testing.T) {
	if err := ValidateWordPressAdminUser("wpadmin_123"); err != nil {
		t.Errorf("expected valid admin user, got: %v", err)
	}
	if err := ValidateWordPressAdminUser("wp admin!"); err == nil {
		t.Error("expected error on special char admin user")
	}

	if err := ValidateWordPressEmail("admin@example.com"); err != nil {
		t.Errorf("expected valid email, got: %v", err)
	}
	if err := ValidateWordPressEmail("invalid-email"); err == nil {
		t.Error("expected error on invalid email")
	}

	if err := ValidateWordPressTablePrefix("wp_"); err != nil {
		t.Errorf("expected valid prefix, got: %v", err)
	}
	if err := ValidateWordPressTablePrefix("wp_custom_"); err != nil {
		t.Errorf("expected valid prefix, got: %v", err)
	}
	if err := ValidateWordPressTablePrefix("wp-prefix-dash!"); err == nil {
		t.Error("expected error on invalid table prefix")
	}

	if _, err := ValidateWordPressInstallPath("alicecorp", "/home/alicecorp/public_html"); err != nil {
		t.Errorf("expected valid path, got: %v", err)
	}
	if _, err := ValidateWordPressInstallPath("alicecorp", "/home/alicecorp"); err == nil {
		t.Error("expected error on home root path")
	}
	if _, err := ValidateWordPressInstallPath("alicecorp", "/var/www/html"); err == nil {
		t.Error("expected error on outside home path")
	}
}

func TestWordPressInstallAndUninstallDryRun(t *testing.T) {
	ctx := context.Background()

	installAction := NewWordpressInstallAction()
	payload, _ := json.Marshal(WordpressInstallPayload{
		Username:      "alicecorp",
		Domain:        "alicecorp.com",
		InstallPath:   "/home/alicecorp/public_html",
		AdminUser:     "admin_alice",
		AdminPassword: "SuperSecurePassword123!",
		AdminEmail:    "admin@alicecorp.com",
		SiteTitle:     "Alice's Blog",
		DbName:        "alicecorp_wp",
		DbUser:        "alicecorp_wpu",
		DbPassword:    "SecretDBPass123",
	})

	if err := installAction.Validate(payload); err != nil {
		t.Fatalf("validation failed: %v", err)
	}

	res, err := installAction.Execute(ctx, payload, true)
	if err != nil || !res.Success {
		t.Fatalf("execute failed: %v, res: %+v", err, res)
	}

	uninstallAction := NewWordpressUninstallAction()
	unPayload, _ := json.Marshal(WordpressUninstallPayload{
		Username:    "alicecorp",
		InstallPath: "/home/alicecorp/public_html",
		RemoveDb:    true,
		DbName:      "alicecorp_wp",
		DbUser:      "alicecorp_wpu",
	})

	if err := uninstallAction.Validate(unPayload); err != nil {
		t.Fatalf("uninstall validation failed: %v", err)
	}

	unRes, err := uninstallAction.Execute(ctx, unPayload, true)
	if err != nil || !unRes.Success {
		t.Fatalf("uninstall execute failed: %v, res: %+v", err, unRes)
	}
}

