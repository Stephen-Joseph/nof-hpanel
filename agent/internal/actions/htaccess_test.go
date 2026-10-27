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

func TestHtaccessContinuationLines(t *testing.T) {
	input := `# Test continuation lines
RewriteRule ^old-path/(.*)$ \
    /new-path/$1 \
    [L,R=301]

DirectoryIndex index.php
`
	res, err := ParseAndConvertHtaccess("example.com", input)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	foundRewrite := false
	for _, r := range res.Rules {
		if strings.Contains(r.Directive, "RewriteRule") {
			foundRewrite = true
			if r.Line != 2 {
				t.Errorf("expected continuation line starting line to be 2, got %d", r.Line)
			}
			if r.Status != "converted" {
				t.Errorf("expected rewrite rule to be converted, got status: %s", r.Status)
			}
		}
	}

	if !foundRewrite {
		t.Errorf("did not find merged continuation rewrite rule in report")
	}

	if !strings.Contains(res.NginxConfig, "rewrite ^/old-path/(.*)$ /new-path/$1 permanent;") {
		t.Errorf("expected Nginx config to contain rewrite, got:\n%s", res.NginxConfig)
	}
}

func TestHtaccessGoldenDirectivesConversion(t *testing.T) {
	input := `# WordPress front controller
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]

# HTTPS redirect
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Redirects
Redirect 301 /old-url https://example.com/new-url
Redirect gone /dead-link
RedirectMatch 302 ^/temp/(.*)$ /staging/$1

# Error Documents
ErrorDocument 404 /404.html
ErrorDocument 500 https://status.example.com/500

# Directory Index
DirectoryIndex index.php index.html

# Headers
Header set X-Frame-Options "SAMEORIGIN"
Header always set X-Content-Type-Options "nosniff"

# Caching
ExpiresActive On
ExpiresByType image/jpeg "access plus 1 month"
ExpiresByType text/css "access plus 7 days"

# Directory Indexing
Options -Indexes

# Access Control
Deny from 192.168.1.100
Allow from all

# Basic Auth
AuthType Basic
AuthName "Restricted Admin"
AuthUserFile /home/john/.htpasswds/admin.htpasswd
Require valid-user
`

	res, err := ParseAndConvertHtaccess("mysite.com", input)
	if err != nil {
		t.Fatalf("conversion failed: %v", err)
	}

	expectedNginxSubstrings := []string{
		"try_files $uri $uri/ /index.php?$query_string;",
		"if ($scheme != \"https\") {",
		"return 301 https://$host$request_uri;",
		"rewrite ^/old-url(.*)$ https://example.com/new-url$1 permanent;",
		"location = /dead-link {",
		"return 410;",
		"rewrite ^/temp/(.*)$ /staging/$1 redirect;",
		"error_page 404 /404.html;",
		"error_page 500 =302 https://status.example.com/500;",
		"index index.php index.html;",
		"add_header X-Frame-Options \"SAMEORIGIN\";",
		"add_header X-Content-Type-Options \"nosniff\" always;",
		"expires 30d;",
		"autoindex off;",
		"deny 192.168.1.100;",
		"allow all;",
		"auth_basic \"Restricted Admin\";",
		"auth_basic_user_file /home/john/.htpasswds/admin.htpasswd;",
	}

	for _, exp := range expectedNginxSubstrings {
		if !strings.Contains(res.NginxConfig, exp) {
			t.Errorf("missing expected Nginx snippet:\n%s\n\nFull output:\n%s", exp, res.NginxConfig)
		}
	}

	if res.ConvertedCount < 10 {
		t.Errorf("expected at least 10 converted rules, got %d", res.ConvertedCount)
	}
}

func TestHtaccessUnsupportedDirectives(t *testing.T) {
	input := `# Some unsupported directives
php_value upload_max_filesize 64M
php_flag display_errors Off
SetEnv APP_ENV production
AddHandler cgi-script .cgi
Order allow,deny
SecRuleEngine On
UnknownCustomDirective foo bar
`

	res, err := ParseAndConvertHtaccess("example.com", input)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if res.UnsupportedCount < 6 {
		t.Errorf("expected at least 6 unsupported directives, got %d", res.UnsupportedCount)
	}

	// Verify line numbers and helpful messages
	expectedReports := map[int]string{
		2: "PHP directive 'php_value' cannot be configured via .htaccess in Nginx",
		3: "PHP directive 'php_flag' cannot be configured via .htaccess in Nginx",
		4: "Environment variables (SetEnv) are not supported via .htaccess",
		5: "Handler and MIME type directives (AddHandler) are managed globally by Nginx",
		6: "Apache Order directives are not directly supported",
		7: "ModSecurity directives must be configured at the global server security level",
		8: "Directive 'UnknownCustomDirective' is not supported",
	}

	for line, msgSubstring := range expectedReports {
		found := false
		for _, r := range res.Rules {
			if r.Line == line && strings.Contains(r.Message, msgSubstring) {
				found = true
				if r.Status != "unsupported" {
					t.Errorf("line %d: expected status unsupported, got %s", line, r.Status)
				}
				break
			}
		}
		if !found {
			t.Errorf("did not find expected unsupported report for line %d with substring '%s'", line, msgSubstring)
		}
	}
}

func TestHtaccessSecurityHeadersAndInjection(t *testing.T) {
	// Header injection with newlines or forbidden Set-Cookie
	badHeaders := `# Injected headers
Header set Set-Cookie "session=123"
Header set X-Frame-Options "DENY\r\nInjected-Header: evil"
Header set Unknown-Custom-Header "test"
`

	res, err := ParseAndConvertHtaccess("example.com", badHeaders)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if strings.Contains(res.NginxConfig, "Set-Cookie") {
		t.Errorf("Set-Cookie should never be emitted in converted Nginx config")
	}
	if strings.Contains(res.NginxConfig, "Injected-Header") {
		t.Errorf("Injected header should not be emitted")
	}

	for _, r := range res.Rules {
		if strings.Contains(r.Directive, "Set-Cookie") && r.Status != "unsupported" {
			t.Errorf("expected Set-Cookie to be flagged as unsupported, got: %s", r.Status)
		}
		if strings.Contains(r.Directive, "Injected-Header") && r.Status != "unsupported" {
			t.Errorf("expected injected newline header to be unsupported, got: %s", r.Status)
		}
	}
}

func TestHtaccessRewriteLoopProtection(t *testing.T) {
	loopInput := `# Dangerous rewrite loop
RewriteRule ^loop$ /loop [L]
RewriteRule ^(.*)$ $1 [L]
RewriteRule ^valid-page$ /target-page [L]
`

	res, err := ParseAndConvertHtaccess("example.com", loopInput)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	if res.WarningCount < 2 {
		t.Errorf("expected at least 2 warnings for rewrite loops, got %d", res.WarningCount)
	}

	// Assert loop rules were NOT emitted into Nginx config
	if strings.Contains(res.NginxConfig, "rewrite ^/loop /loop") {
		t.Errorf("rewrite loop rule should not be emitted into Nginx config")
	}
	// Assert valid rule was emitted
	if !strings.Contains(res.NginxConfig, "rewrite ^/valid-page$ /target-page last;") {
		t.Errorf("expected valid rule to be emitted, got:\n%s", res.NginxConfig)
	}
}

func TestHtaccessOversizeRejection(t *testing.T) {
	// File larger than 256 KB
	largeContent := strings.Repeat("# Comment line\n", 20000)
	_, err := ParseAndConvertHtaccess("example.com", largeContent)
	if err == nil {
		t.Errorf("expected error for oversize file (> 256 KB), got nil")
	}

	// File with more than 2000 lines
	manyLines := strings.Repeat("DirectoryIndex index.php\n", 2050)
	_, err = ParseAndConvertHtaccess("example.com", manyLines)
	if err == nil {
		t.Errorf("expected error for file with > 2000 lines, got nil")
	}
}

func TestHtaccessSyncActionAndRollback(t *testing.T) {
	tmpDir := t.TempDir()
	docRoot := filepath.Join(tmpDir, "home", "alice", "public_html")
	customDir := filepath.Join(tmpDir, "hpanel.d")
	backupDir := filepath.Join(tmpDir, "backups")
	_ = os.MkdirAll(docRoot, 0755)
	_ = os.MkdirAll(customDir, 0755)
	_ = os.MkdirAll(backupDir, 0700)

	htaccessFile := filepath.Join(docRoot, ".htaccess")
	_ = os.WriteFile(htaccessFile, []byte("Redirect 301 /old https://example.com/new\n"), 0644)

	// Test successful sync
	succExecutor := mockNginxExecutor{}
	syncAction := NewHtaccessSyncActionForTest(succExecutor, customDir, backupDir)

	payload, _ := json.Marshal(HtaccessSyncPayload{
		Username:     "alice",
		Domain:       "alice.com",
		DocumentRoot: docRoot,
	})

	res, err := syncAction.Execute(context.Background(), payload, false)
	if err != nil || !res.Success {
		t.Fatalf("expected successful sync, got err: %v, res: %+v", err, res)
	}

	destFile := filepath.Join(customDir, "alice.com.htaccess.conf")
	if _, err := os.Stat(destFile); err != nil {
		t.Fatalf("expected generated include file %s to exist, got: %v", destFile, err)
	}

	// Test rollback on syntax failure
	failExecutor := mockNginxExecutor{
		combinedOutputFunc: func(ctx context.Context, name string, args ...string) ([]byte, error) {
			return []byte("nginx: [emerg] syntax test failed in htaccess include"), errors.New("exit status 1")
		},
	}
	failSyncAction := NewHtaccessSyncActionForTest(failExecutor, customDir, backupDir)

	_ = os.WriteFile(htaccessFile, []byte("Redirect 301 /new-broken https://example.com/broken\n"), 0644)
	res, err = failSyncAction.Execute(context.Background(), payload, false)
	if err == nil {
		t.Fatalf("expected sync to fail when nginx -t fails")
	}

	// Test deletion removes include
	_ = os.Remove(htaccessFile)
	delRes, err := syncAction.Execute(context.Background(), payload, false)
	if err != nil || !delRes.Success {
		t.Fatalf("expected successful deletion sync, got err: %v", err)
	}
	if _, err := os.Stat(destFile); !os.IsNotExist(err) {
		t.Errorf("expected %s to be removed when .htaccess is deleted", destFile)
	}
}
