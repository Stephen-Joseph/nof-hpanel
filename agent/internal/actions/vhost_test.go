package actions

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestVhostProfileGoldenTemplates(t *testing.T) {
	profiles := []struct {
		name        string
		profile     string
		contains    []string
		notContains []string
	}{
		{
			name:    "PHP Generic",
			profile: "php-generic",
			contains: []string{
				"root /home/john/public_html;",
				"try_files $uri $uri/ $uri.php?$query_string;",
				"fastcgi_pass unix:/run/php/php8.3-fpm-john.sock;",
				"deny all;",
			},
			notContains: []string{
				"wp-config.php",
				"index.html;",
			},
		},
		{
			name:    "Laravel",
			profile: "laravel",
			contains: []string{
				"root /home/john/mysite/public;",
				"try_files $uri $uri/ /index.php?$query_string;",
				"location = /favicon.ico { access_log off; log_not_found off; }",
				"fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;",
				"location ~ /\\.(?!well-known).* {",
			},
			notContains: []string{
				"wp-config.php",
			},
		},
		{
			name:    "WordPress",
			profile: "wordpress",
			contains: []string{
				"try_files $uri $uri/ /index.php?$args;",
				"location = /wp-config.php { deny all; }",
				"location = /xmlrpc.php { deny all; }",
				"location ~* /wp-content/uploads/.*\\.php$ {",
			},
			notContains: []string{
				"$realpath_root",
			},
		},
		{
			name:    "Static SPA",
			profile: "static-spa",
			contains: []string{
				"try_files $uri $uri/ /index.html;",
				"expires 30d;",
			},
			notContains: []string{
				"fastcgi_pass",
				"index.php",
			},
		},
	}

	for _, tt := range profiles {
		t.Run(tt.name, func(t *testing.T) {
			docRoot := "/home/john/public_html"
			if tt.profile == "laravel" {
				docRoot = "/home/john/mysite/public"
			}

			cfg, err := RenderVhostConfig(VhostTemplateData{
				Username:     "john",
				Domain:       "john.com",
				ServerNames:  "john.com www.john.com",
				DocumentRoot: docRoot,
				AppProfile:   tt.profile,
				PHPVersion:   "8.3",
			})
			if err != nil {
				t.Fatalf("failed to render vhost for %s: %v", tt.profile, err)
			}

			for _, c := range tt.contains {
				if !strings.Contains(cfg, c) {
					t.Errorf("[%s] expected config to contain:\n%s\nGot:\n%s", tt.profile, c, cfg)
				}
			}

			for _, nc := range tt.notContains {
				if strings.Contains(cfg, nc) {
					t.Errorf("[%s] expected config NOT to contain:\n%s", tt.profile, nc)
				}
			}
		})
	}
}

func TestVhostRedirectsAndHTTPS(t *testing.T) {
	cfg, err := RenderVhostConfig(VhostTemplateData{
		Username:     "alice",
		Domain:       "alice.org",
		ServerNames:  "alice.org",
		DocumentRoot: "/home/alice/public_html",
		AppProfile:   "php-generic",
		ForceHTTPS:   true,
		WwwRedirect:  "force_www",
		Redirects: []RedirectRule{
			{Source: "/old-blog", Target: "/new-blog", Code: 301},
			{Source: "/promo", Target: "https://partner.com", Code: 302},
		},
	})
	if err != nil {
		t.Fatalf("failed to render config: %v", err)
	}

	if !strings.Contains(cfg, "return 301 https://$host$request_uri;") {
		t.Error("expected HTTPS redirect block")
	}
	if !strings.Contains(cfg, "return 301 $scheme://www.alice.org$request_uri;") {
		t.Error("expected force_www redirect block")
	}
	if !strings.Contains(cfg, "rewrite ^/old-blog$ /new-blog permanent;") {
		t.Error("expected 301 permanent rewrite")
	}
	if !strings.Contains(cfg, "rewrite ^/promo$ https://partner.com redirect;") {
		t.Error("expected 302 temporary rewrite")
	}
}

func TestValidateDocumentRootSecurity(t *testing.T) {
	user := "bob"

	// Valid roots
	validRoots := []string{
		"/home/bob",
		"/home/bob/public_html",
		"/home/bob/subdomains/api",
		"/home/bob/laravel/public",
	}
	for _, r := range validRoots {
		if err := ValidateDocumentRoot(user, r); err != nil {
			t.Errorf("expected '%s' to be valid for %s, got: %v", r, user, err)
		}
	}

	// Traversal & Escape attempts
	invalidRoots := []string{
		"",
		"/",
		"/etc",
		"/etc/nginx",
		"/home/otheruser/public_html",
		"/home/bob/../otheruser/public_html",
		"/home/bob/public_html/../../etc/shadow",
		"/var/www/html",
		"/home/bob\x00/public_html",
	}
	for _, r := range invalidRoots {
		if err := ValidateDocumentRoot(user, r); err == nil {
			t.Errorf("expected root escape '%s' to be rejected, but it passed", r)
		}
	}
}

func TestVhostWriteQuotaCheck(t *testing.T) {
	action := NewVhostWriteAction()

	// Quota exceeded: max 2, current 3
	payloadExceeded, _ := json.Marshal(VhostWritePayload{
		Username:            "carol",
		Domain:              "thirdsite.com",
		DocumentRoot:        "/home/carol/public_html",
		MaxWebsites:         2,
		CurrentWebsiteCount: 3,
	})

	if err := action.Validate(payloadExceeded); err == nil {
		t.Fatal("expected validation to fail due to quota exceeded")
	}

	// Quota within limits: max 5, current 2
	payloadOk, _ := json.Marshal(VhostWritePayload{
		Username:            "carol",
		Domain:              "secondsite.com",
		DocumentRoot:        "/home/carol/public_html",
		MaxWebsites:         5,
		CurrentWebsiteCount: 2,
	})

	if err := action.Validate(payloadOk); err != nil {
		t.Fatalf("expected validation to pass within quota limits, got: %v", err)
	}

	// Test Dry-run
	res, err := action.Execute(context.Background(), payloadOk, true)
	if err != nil || !res.Success {
		t.Fatalf("expected dry-run to succeed, got %v", err)
	}
	if dry, ok := res.Data["dry_run"].(bool); !ok || !dry {
		t.Fatal("expected dry_run=true")
	}
}

func TestVhostRemoveAndReload(t *testing.T) {
	removeAction := NewVhostRemoveAction()
	reloadAction := NewVhostReloadAction()

	payload, _ := json.Marshal(VhostRemovePayload{
		Username: "dan",
		Domain:   "dan.org",
	})

	if err := removeAction.Validate(payload); err != nil {
		t.Fatalf("remove validate failed: %v", err)
	}

	resRemove, err := removeAction.Execute(context.Background(), payload, true)
	if err != nil || !resRemove.Success {
		t.Fatalf("remove dry-run failed: %v", err)
	}

	resReload, err := reloadAction.Execute(context.Background(), nil, true)
	if err != nil || !resReload.Success {
		t.Fatalf("reload dry-run failed: %v", err)
	}
}

func extractDirective(config, directive string) string {
	lines := strings.Split(config, "\n")
	prefix := directive + " "
	for _, line := range lines {
		trimmed := strings.TrimSpace(line)
		if strings.HasPrefix(trimmed, prefix) {
			return trimmed
		}
	}
	return ""
}

func extractDocumentRoot(config string) string {
	lines := strings.Split(config, "\n")
	for _, line := range lines {
		trimmed := strings.TrimSpace(line)
		if strings.HasPrefix(trimmed, "root ") && !strings.Contains(trimmed, "letsencrypt") {
			return trimmed
		}
	}
	return ""
}

func TestGenerateTwoVhostsDistinctServerNameAndRootAndDefaultCatchAll(t *testing.T) {
	// Account 1
	data1 := VhostTemplateData{
		Username:     "cust01",
		Domain:       "siteone.com",
		DocumentRoot: "/home/cust01/public_html",
		AppProfile:   "php-generic",
		PHPVersion:   "8.3",
	}

	// Account 2
	data2 := VhostTemplateData{
		Username:     "cust02",
		Domain:       "sitetwo.org",
		DocumentRoot: "/home/cust02/mysite/public",
		AppProfile:   "laravel",
		PHPVersion:   "8.4",
	}

	cfg1, err := RenderVhostConfig(data1)
	if err != nil {
		t.Fatalf("failed to render vhost 1: %v", err)
	}

	cfg2, err := RenderVhostConfig(data2)
	if err != nil {
		t.Fatalf("failed to render vhost 2: %v", err)
	}

	// Assert vhost 1 includes server_name for primary domain and www
	if !strings.Contains(cfg1, "server_name siteone.com www.siteone.com;") {
		t.Errorf("vhost 1 missing expected server_name for primary domain and www:\n%s", cfg1)
	}
	// Assert vhost 1 has root under account home
	if !strings.Contains(cfg1, "root /home/cust01/public_html;") {
		t.Errorf("vhost 1 missing expected root under account home:\n%s", cfg1)
	}

	// Assert vhost 2 includes server_name for primary domain and www
	if !strings.Contains(cfg2, "server_name sitetwo.org www.sitetwo.org;") {
		t.Errorf("vhost 2 missing expected server_name for primary domain and www:\n%s", cfg2)
	}
	// Assert vhost 2 has root under account home
	if !strings.Contains(cfg2, "root /home/cust02/mysite/public;") {
		t.Errorf("vhost 2 missing expected root under account home:\n%s", cfg2)
	}

	// Assert each has a distinct server_name and root
	serverName1 := extractDirective(cfg1, "server_name")
	serverName2 := extractDirective(cfg2, "server_name")
	if serverName1 == "" || serverName2 == "" {
		t.Fatalf("failed to extract server_name directives from generated vhosts")
	}
	if serverName1 == serverName2 {
		t.Errorf("expected distinct server_name directives, got identical: %s", serverName1)
	}

	root1 := extractDocumentRoot(cfg1)
	root2 := extractDocumentRoot(cfg2)
	if root1 == "" || root2 == "" {
		t.Fatalf("failed to extract root directives from generated vhosts")
	}
	if root1 == root2 {
		t.Errorf("expected distinct root directives, got identical: %s", root1)
	}

	// Assert default_server catch-all block exists that returns 444 on ports 80 and 443 (ssl_reject_handshake on for 443)
	defaultBlock := RenderDefaultVhostConfig()
	if !strings.Contains(defaultBlock, "listen 80 default_server;") {
		t.Errorf("default block missing 'listen 80 default_server;':\n%s", defaultBlock)
	}
	if !strings.Contains(defaultBlock, "listen [::]:80 default_server;") {
		t.Errorf("default block missing 'listen [::]:80 default_server;':\n%s", defaultBlock)
	}
	if !strings.Contains(defaultBlock, "listen 443 ssl default_server;") {
		t.Errorf("default block missing 'listen 443 ssl default_server;':\n%s", defaultBlock)
	}
	if !strings.Contains(defaultBlock, "listen [::]:443 ssl default_server;") {
		t.Errorf("default block missing 'listen [::]:443 ssl default_server;':\n%s", defaultBlock)
	}
	if !strings.Contains(defaultBlock, "ssl_reject_handshake on;") {
		t.Errorf("default block missing 'ssl_reject_handshake on;':\n%s", defaultBlock)
	}
	if !strings.Contains(defaultBlock, "return 444;") {
		t.Errorf("default block missing 'return 444;':\n%s", defaultBlock)
	}
	if !strings.Contains(defaultBlock, "server_name _;") {
		t.Errorf("default block missing 'server_name _;':\n%s", defaultBlock)
	}
}

type mockVhostExecutor struct {
	onCombinedOutput func(name string, args ...string) ([]byte, error)
	onRun            func(name string, args ...string) error
	lookups          map[string]bool
}

func (m *mockVhostExecutor) CombinedOutput(_ context.Context, name string, args ...string) ([]byte, error) {
	if m.onCombinedOutput != nil {
		return m.onCombinedOutput(name, args...)
	}
	return []byte("ok"), nil
}

func (m *mockVhostExecutor) Run(_ context.Context, name string, args ...string) error {
	if m.onRun != nil {
		return m.onRun(name, args...)
	}
	return nil
}

func (m *mockVhostExecutor) LookPath(file string) (string, error) {
	if m.lookups != nil && !m.lookups[file] {
		return "", errors.New("not found")
	}
	return "/usr/bin/" + file, nil
}

func TestVhostWriteActionDeploysTwoVhostsAndDefaultCatchAllWithLinkingOrder(t *testing.T) {
	tmp := t.TempDir()
	availDir := filepath.Join(tmp, "sites-available")
	enabledDir := filepath.Join(tmp, "sites-enabled")

	var callSequence []string
	var symlinkExistedDuringNginxTest []bool

	exec := &mockVhostExecutor{
		lookups: map[string]bool{
			"nginx":     true,
			"systemctl": true,
		},
		onCombinedOutput: func(name string, args ...string) ([]byte, error) {
			cmdStr := name + " " + strings.Join(args, " ")
			callSequence = append(callSequence, cmdStr)
			if name == "nginx" && len(args) > 0 && args[0] == "-t" {
				entries, err := os.ReadDir(enabledDir)
				symlinkExistedDuringNginxTest = append(symlinkExistedDuringNginxTest, err == nil && len(entries) > 0)
			}
			return []byte("nginx: syntax is ok"), nil
		},
		onRun: func(name string, args ...string) error {
			cmdStr := name + " " + strings.Join(args, " ")
			callSequence = append(callSequence, cmdStr)
			return nil
		},
	}

	action := NewVhostWriteActionForTest(exec, availDir, enabledDir)

	// Deploy Account 1 vhost
	p1, _ := json.Marshal(VhostWritePayload{
		Username:     "alice",
		Domain:       "alice-app.com",
		DocumentRoot: "/home/alice/public_html",
	})
	res1, err := action.Execute(context.Background(), p1, false)
	if err != nil || !res1.Success {
		t.Fatalf("vhost 1 execute failed: %v", err)
	}

	// Deploy Account 2 vhost
	p2, _ := json.Marshal(VhostWritePayload{
		Username:     "bob",
		Domain:       "bob-portal.net",
		DocumentRoot: "/home/bob/public_html",
	})
	res2, err := action.Execute(context.Background(), p2, false)
	if err != nil || !res2.Success {
		t.Fatalf("vhost 2 execute failed: %v", err)
	}

	// Assert default catch-all block exists in sites-available and is symlinked in sites-enabled
	defaultAvail := filepath.Join(availDir, "00-default-deny.conf")
	defaultEnabled := filepath.Join(enabledDir, "00-default-deny.conf")
	defaultBytes, err := os.ReadFile(defaultAvail)
	if err != nil {
		t.Fatalf("expected 00-default-deny.conf in sites-available: %v", err)
	}
	if !strings.Contains(string(defaultBytes), "listen 80 default_server;") {
		t.Errorf("default block missing 'listen 80 default_server;'")
	}
	if !strings.Contains(string(defaultBytes), "listen 443 ssl default_server;") {
		t.Errorf("default block missing 'listen 443 ssl default_server;'")
	}
	if !strings.Contains(string(defaultBytes), "ssl_reject_handshake on;") {
		t.Errorf("default block missing 'ssl_reject_handshake on;'")
	}
	if !strings.Contains(string(defaultBytes), "return 444;") {
		t.Errorf("default block missing 'return 444;'")
	}
	if _, err := os.Lstat(defaultEnabled); err != nil {
		t.Fatalf("expected 00-default-deny.conf symlink in sites-enabled: %v", err)
	}

	// Assert vhost 1 exists on disk with primary domain and www, and root under account home
	vhost1Avail := filepath.Join(availDir, "alice-app.com.conf")
	vhost1Enabled := filepath.Join(enabledDir, "alice-app.com.conf")
	vhost1Bytes, err := os.ReadFile(vhost1Avail)
	if err != nil {
		t.Fatalf("vhost 1 file missing: %v", err)
	}
	if !strings.Contains(string(vhost1Bytes), "server_name alice-app.com www.alice-app.com;") {
		t.Errorf("vhost 1 missing expected server_name with primary domain and www")
	}
	if !strings.Contains(string(vhost1Bytes), "root /home/alice/public_html;") {
		t.Errorf("vhost 1 missing root under account home")
	}
	if _, err := os.Lstat(vhost1Enabled); err != nil {
		t.Fatalf("vhost 1 symlink missing: %v", err)
	}

	// Assert vhost 2 exists on disk with primary domain and www, and root under account home
	vhost2Avail := filepath.Join(availDir, "bob-portal.net.conf")
	vhost2Enabled := filepath.Join(enabledDir, "bob-portal.net.conf")
	vhost2Bytes, err := os.ReadFile(vhost2Avail)
	if err != nil {
		t.Fatalf("vhost 2 file missing: %v", err)
	}
	if !strings.Contains(string(vhost2Bytes), "server_name bob-portal.net www.bob-portal.net;") {
		t.Errorf("vhost 2 missing expected server_name with primary domain and www")
	}
	if !strings.Contains(string(vhost2Bytes), "root /home/bob/public_html;") {
		t.Errorf("vhost 2 missing root under account home")
	}
	if _, err := os.Lstat(vhost2Enabled); err != nil {
		t.Fatalf("vhost 2 symlink missing: %v", err)
	}

	// Assert each vhost has distinct server_name and root
	serverName1 := extractDirective(string(vhost1Bytes), "server_name")
	serverName2 := extractDirective(string(vhost2Bytes), "server_name")
	if serverName1 == "" || serverName2 == "" || serverName1 == serverName2 {
		t.Errorf("expected distinct server_name directives on disk: v1=%q, v2=%q", serverName1, serverName2)
	}

	root1 := extractDocumentRoot(string(vhost1Bytes))
	root2 := extractDocumentRoot(string(vhost2Bytes))
	if root1 == "" || root2 == "" || root1 == root2 {
		t.Errorf("expected distinct root directives on disk: v1=%q, v2=%q", root1, root2)
	}

	// Assert nginx -t was run ONLY AFTER the symlink was in sites-enabled
	if len(symlinkExistedDuringNginxTest) != 2 {
		t.Fatalf("expected 2 nginx -t calls, got %d", len(symlinkExistedDuringNginxTest))
	}
	for i, existed := range symlinkExistedDuringNginxTest {
		if !existed {
			t.Errorf("nginx -t call #%d occurred before symlink was linked into sites-enabled", i+1)
		}
	}

	// Assert reload was called only after nginx -t
	// Sequence should be: nginx -t, systemctl reload nginx, nginx -t, systemctl reload nginx
	expectedSequence := []string{
		"nginx -t",
		"systemctl reload nginx",
		"nginx -t",
		"systemctl reload nginx",
	}
	if len(callSequence) != len(expectedSequence) {
		t.Fatalf("expected command sequence %v, got %v", expectedSequence, callSequence)
	}
	for i, cmd := range expectedSequence {
		if callSequence[i] != cmd {
			t.Errorf("command #%d: expected %q, got %q", i, cmd, callSequence[i])
		}
	}
}

func TestVhostWriteActionRollsBackWhenNginxTestFailsAfterLinking(t *testing.T) {
	tmp := t.TempDir()
	availDir := filepath.Join(tmp, "sites-available")
	enabledDir := filepath.Join(tmp, "sites-enabled")

	reloadCalled := false
	exec := &mockVhostExecutor{
		lookups: map[string]bool{
			"nginx":     true,
			"systemctl": true,
		},
		onCombinedOutput: func(name string, args ...string) ([]byte, error) {
			if name == "nginx" && len(args) > 0 && args[0] == "-t" {
				return []byte("nginx: [emerg] syntax error"), errors.New("exit status 1")
			}
			return []byte("ok"), nil
		},
		onRun: func(name string, args ...string) error {
			if name == "systemctl" && len(args) > 0 && args[0] == "reload" {
				reloadCalled = true
			}
			return nil
		},
	}

	action := NewVhostWriteActionForTest(exec, availDir, enabledDir)

	p, _ := json.Marshal(VhostWritePayload{
		Username:     "charlie",
		Domain:       "badconfig.com",
		DocumentRoot: "/home/charlie/public_html",
	})

	res, err := action.Execute(context.Background(), p, false)
	if err == nil || res.Success {
		t.Fatal("expected Execute to fail when nginx -t fails")
	}

	if reloadCalled {
		t.Error("reload was called despite nginx -t failure")
	}

	// Assert symlink was removed (rolled back)
	enabledFile := filepath.Join(enabledDir, "badconfig.com.conf")
	if _, err := os.Lstat(enabledFile); !os.IsNotExist(err) {
		t.Errorf("expected symlink to be rolled back and removed on nginx -t failure")
	}

	// Assert availFile was removed (as this was a new config with no backup)
	availFile := filepath.Join(availDir, "badconfig.com.conf")
	if _, err := os.Stat(availFile); !os.IsNotExist(err) {
		t.Errorf("expected available config file to be removed on nginx -t failure")
	}
}

func TestVhostProfilesTryFilesRules(t *testing.T) {
	// 1. Render php-generic profile
	phpGenericData := VhostTemplateData{
		Username:     "myuser",
		Domain:       "genericphp.example.com",
		DocumentRoot: "/home/myuser/public_html",
		AppProfile:   "php-generic",
		PHPVersion:   "8.4",
	}

	cfgPhpGeneric, err := RenderVhostConfig(phpGenericData)
	if err != nil {
		t.Fatalf("failed to render php-generic vhost: %v", err)
	}

	expectedGenericTryFiles := "try_files $uri $uri/ $uri.php?$query_string;"
	if !strings.Contains(cfgPhpGeneric, expectedGenericTryFiles) {
		t.Errorf("php-generic profile missing expected try_files line: %q", expectedGenericTryFiles)
	}
	unexpectedGenericTryFiles := "try_files $uri $uri/ /index.php?$query_string;"
	if strings.Contains(cfgPhpGeneric, unexpectedGenericTryFiles) {
		t.Errorf("php-generic profile should not contain index.php fallback: %q", unexpectedGenericTryFiles)
	}

	// 2. Render laravel profile
	laravelData := VhostTemplateData{
		Username:     "myuser",
		Domain:       "laravelapp.example.com",
		DocumentRoot: "/home/myuser/apps/laravel/public",
		AppProfile:   "laravel",
		PHPVersion:   "8.4",
	}

	cfgLaravel, err := RenderVhostConfig(laravelData)
	if err != nil {
		t.Fatalf("failed to render laravel vhost: %v", err)
	}

	expectedLaravelTryFiles := "try_files $uri $uri/ /index.php?$query_string;"
	if !strings.Contains(cfgLaravel, expectedLaravelTryFiles) {
		t.Errorf("laravel profile missing expected try_files line: %q", expectedLaravelTryFiles)
	}
	unexpectedLaravelTryFiles := "try_files $uri $uri/ $uri.php?$query_string;"
	if strings.Contains(cfgLaravel, unexpectedLaravelTryFiles) {
		t.Errorf("laravel profile should not contain $uri.php fallback: %q", unexpectedLaravelTryFiles)
	}
}
