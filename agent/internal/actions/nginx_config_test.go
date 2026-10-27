package actions

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

type mockNginxExecutor struct {
	lookPathFunc       func(file string) (string, error)
	combinedOutputFunc func(ctx context.Context, name string, args ...string) ([]byte, error)
	runFunc            func(ctx context.Context, name string, args ...string) error
}

func (m mockNginxExecutor) LookPath(file string) (string, error) {
	if m.lookPathFunc != nil {
		return m.lookPathFunc(file)
	}
	return "/usr/sbin/" + file, nil
}

func (m mockNginxExecutor) CombinedOutput(ctx context.Context, name string, args ...string) ([]byte, error) {
	if m.combinedOutputFunc != nil {
		return m.combinedOutputFunc(ctx, name, args...)
	}
	return []byte("syntax is ok"), nil
}

func (m mockNginxExecutor) Run(ctx context.Context, name string, args ...string) error {
	if m.runFunc != nil {
		return m.runFunc(ctx, name, args...)
	}
	return nil
}

func TestValidateNginxConfigName(t *testing.T) {
	badNames := []string{
		"",
		"   ",
		"nginx.conf",
		"../test.conf",
		"/etc/nginx/sites-available/foo.conf",
		"foo/bar.conf",
		"foo\\bar.conf",
		"foo.conf/..",
		"evil;rm.conf",
		"foo$bar.conf",
		"foo.txt",
		"foo.CONF",
	}

	for _, name := range badNames {
		if err := ValidateNginxConfigName(name); err == nil {
			t.Errorf("expected error for name '%s', got nil", name)
		}
	}

	goodNames := []string{
		"example.com.conf",
		"sub.domain.co.uk.conf",
		"00-default-deny.conf",
		"test123.conf",
	}

	for _, name := range goodNames {
		if err := ValidateNginxConfigName(name); err != nil {
			t.Errorf("unexpected error for valid name '%s': %v", name, err)
		}
	}
}

func TestNginxConfigWriteAndRollback(t *testing.T) {
	tmpDir := t.TempDir()
	availDir := filepath.Join(tmpDir, "sites-available")
	enabledDir := filepath.Join(tmpDir, "sites-enabled")
	backupDir := filepath.Join(tmpDir, "backups")
	_ = os.MkdirAll(availDir, 0755)
	_ = os.MkdirAll(enabledDir, 0755)
	_ = os.MkdirAll(backupDir, 0700)

	initialFile := filepath.Join(availDir, "example.com.conf")
	originalContent := "server { listen 80; server_name example.com; # original\n}"
	if err := os.WriteFile(initialFile, []byte(originalContent), 0640); err != nil {
		t.Fatalf("writing initial file: %v", err)
	}

	failExecutor := mockNginxExecutor{
		combinedOutputFunc: func(ctx context.Context, name string, args ...string) ([]byte, error) {
			if name == "nginx" && len(args) > 0 && args[0] == "-t" {
				return []byte("nginx: [emerg] unknown directive 'broken_directive' in /etc/nginx/sites-available/example.com.conf:2"), errors.New("exit status 1")
			}
			return []byte(""), nil
		},
	}

	action := NewNginxConfigWriteActionForTest(failExecutor, availDir, enabledDir, backupDir)

	payload, _ := json.Marshal(NginxConfigWritePayload{
		Name:    "example.com.conf",
		Content: "server { broken_directive; }",
	})

	result, err := action.Execute(context.Background(), payload, false)
	if err == nil {
		t.Fatalf("expected error from failed nginx -t, got nil")
	}
	if result.Success {
		t.Fatalf("expected result.Success to be false")
	}
	if !strings.Contains(result.Error, "unknown directive 'broken_directive'") {
		t.Errorf("expected error message to contain broken_directive, got: %s", result.Error)
	}

	// Assert rollback restored original content!
	restored, err := os.ReadFile(initialFile)
	if err != nil {
		t.Fatalf("reading file after rollback: %v", err)
	}
	if string(restored) != originalContent {
		t.Errorf("expected content to be rolled back to original.\nExpected: %s\nGot: %s", originalContent, string(restored))
	}
}

func TestNginxConfigEnableAndDisableRollback(t *testing.T) {
	tmpDir := t.TempDir()
	availDir := filepath.Join(tmpDir, "sites-available")
	enabledDir := filepath.Join(tmpDir, "sites-enabled")
	_ = os.MkdirAll(availDir, 0755)
	_ = os.MkdirAll(enabledDir, 0755)

	availFile := filepath.Join(availDir, "test.com.conf")
	_ = os.WriteFile(availFile, []byte("server { listen 80; }"), 0640)
	enabledSymlink := filepath.Join(enabledDir, "test.com.conf")

	failExecutor := mockNginxExecutor{
		combinedOutputFunc: func(ctx context.Context, name string, args ...string) ([]byte, error) {
			return []byte("nginx: [emerg] syntax test failed"), errors.New("exit status 1")
		},
	}

	// Test Enable Rollback
	enableAction := NewNginxConfigEnableActionForTest(failExecutor, availDir, enabledDir)
	enablePayload, _ := json.Marshal(NginxConfigTogglePayload{Name: "test.com.conf"})
	_, err := enableAction.Execute(context.Background(), enablePayload, false)
	if err == nil {
		t.Fatalf("expected enable to fail when nginx -t fails")
	}
	if _, err := os.Lstat(enabledSymlink); !os.IsNotExist(err) {
		t.Errorf("expected symlink %s to be removed on rollback, but it exists", enabledSymlink)
	}

	// Test Disable Rollback
	_ = os.Symlink(availFile, enabledSymlink)
	disableAction := NewNginxConfigDisableActionForTest(failExecutor, availDir, enabledDir)
	disablePayload, _ := json.Marshal(NginxConfigTogglePayload{Name: "test.com.conf"})
	_, err = disableAction.Execute(context.Background(), disablePayload, false)
	if err == nil {
		t.Fatalf("expected disable to fail when nginx -t fails")
	}
	if _, err := os.Lstat(enabledSymlink); err != nil {
		t.Errorf("expected symlink %s to be restored on rollback, but got error: %v", enabledSymlink, err)
	}
}

func TestNginxCustomWriteSanitizationAndInjections(t *testing.T) {
	tmpDir := t.TempDir()
	customDir := filepath.Join(tmpDir, "hpanel.d")
	backupDir := filepath.Join(tmpDir, "backups")
	_ = os.MkdirAll(customDir, 0755)
	_ = os.MkdirAll(backupDir, 0700)

	executor := mockNginxExecutor{}
	action := NewNginxCustomWriteActionForTest(executor, customDir, backupDir)

	injections := []NginxCustomSettingsDTO{
		{
			CustomError404: "/404.html; root /etc/shadow;",
		},
		{
			CustomError404: "/404.html\nreturn 500;",
		},
		{
			CustomError404: "/404.html$query_string",
		},
		{
			Redirects: []CustomRedirectRule{
				{Source: "/old;evil", Target: "https://evil.com", Code: 301},
			},
		},
		{
			Redirects: []CustomRedirectRule{
				{Source: "/old", Target: "https://evil.com/\"`evil", Code: 301},
			},
		},
		{
			Redirects: []CustomRedirectRule{
				{Source: "/loop", Target: "https://example.com/loop", Code: 301},
			},
		},
		{
			MaxUploadBodySizeMB: 500, // exceeds 128 MB limit
		},
		{
			CacheLifetimeStatic: "999d", // invalid lifetime
		},
		{
			DefaultIndex: []string{"../../etc/passwd"},
		},
	}

	for i, badSetting := range injections {
		payload, _ := json.Marshal(NginxCustomWritePayload{
			Domain:   "example.com",
			Settings: badSetting,
		})

		err := action.Validate(payload)
		if err == nil {
			t.Errorf("[%d] expected validation error for injected payload, got nil: %+v", i, badSetting)
		}
	}
}

func TestNginxCustomWriteCleanAndRollback(t *testing.T) {
	tmpDir := t.TempDir()
	customDir := filepath.Join(tmpDir, "hpanel.d")
	backupDir := filepath.Join(tmpDir, "backups")
	_ = os.MkdirAll(customDir, 0755)
	_ = os.MkdirAll(backupDir, 0700)

	succExecutor := mockNginxExecutor{}
	action := NewNginxCustomWriteActionForTest(succExecutor, customDir, backupDir)

	cleanUrlsTrue := true
	forceHttps := true
	payload, _ := json.Marshal(NginxCustomWritePayload{
		Domain: "mysite.com",
		Settings: NginxCustomSettingsDTO{
			Redirects: []CustomRedirectRule{
				{Source: "/old-blog", Target: "https://blog.mysite.com", Code: 301},
			},
			CustomError404:      "/404.html",
			CustomError403:      "/403.html",
			CleanUrls:           &cleanUrlsTrue,
			ForceHTTPS:          &forceHttps,
			MaxUploadBodySizeMB: 64,
			CacheLifetimeStatic: "7d",
			DefaultIndex:        []string{"index.php", "index.html"},
		},
	})

	if err := action.Validate(payload); err != nil {
		t.Fatalf("expected valid payload, got error: %v", err)
	}

	res, err := action.Execute(context.Background(), payload, false)
	if err != nil || !res.Success {
		t.Fatalf("expected successful custom write, got: %v (res: %+v)", err, res)
	}

	customFile := filepath.Join(customDir, "mysite.com.custom.conf")
	content, err := os.ReadFile(customFile)
	if err != nil {
		t.Fatalf("reading custom conf: %v", err)
	}

	s := string(content)
	expectedSubstrings := []string{
		"client_max_body_size 64m;",
		"index index.php index.html;",
		"error_page 404 /404.html;",
		"error_page 403 /403.html;",
		"if ($scheme != \"https\") {",
		"location = /old-blog {",
		"return 301 https://blog.mysite.com;",
		"expires 7d;",
	}
	for _, sub := range expectedSubstrings {
		if !strings.Contains(s, sub) {
			t.Errorf("expected custom config to contain '%s'.\nGot:\n%s", sub, s)
		}
	}
}

func TestPruneBackups(t *testing.T) {
	tmpDir := t.TempDir()
	backupDir := filepath.Join(tmpDir, "backups")
	_ = os.MkdirAll(backupDir, 0700)

	baseName := "test.com.conf"
	for i := 1; i <= 25; i++ {
		file := filepath.Join(backupDir, fmt.Sprintf("%s.%03d", baseName, i))
		_ = os.WriteFile(file, []byte("backup"), 0600)
	}

	pruneBackups(backupDir, baseName)

	entries, _ := os.ReadDir(backupDir)
	var matchingCount int
	for _, e := range entries {
		if strings.HasPrefix(e.Name(), baseName+".") {
			matchingCount++
		}
	}

	if matchingCount != 20 {
		t.Errorf("expected exactly 20 backups to be kept, found %d", matchingCount)
	}
}
