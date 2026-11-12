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

type fakeNginxExecutor struct {
	testErr    error
	testOutput string
	reloadErr  error
	reloaded   int
}

func (f *fakeNginxExecutor) LookPath(file string) (string, error) {
	return "/usr/sbin/" + file, nil
}

func (f *fakeNginxExecutor) Run(ctx context.Context, name string, args ...string) error {
	if name == "systemctl" && len(args) >= 2 && args[0] == "reload" {
		f.reloaded++
		return f.reloadErr
	}
	return nil
}

func (f *fakeNginxExecutor) CombinedOutput(ctx context.Context, name string, args ...string) ([]byte, error) {
	if name == "nginx" && len(args) >= 1 && args[0] == "-t" {
		return []byte(f.testOutput), f.testErr
	}
	return nil, nil
}

func TestNginxTokenizerRejectsLockedDirectives(t *testing.T) {
	lockedCases := []string{
		"server { listen 80; }",
		"listen 8080;",
		"server_name evil.com;",
		"root /var/www/html;",
		"ssl_certificate /etc/ssl/cert.pem;",
		"ssl_certificate_key /etc/ssl/key.pem;",
		"access_log /tmp/access.log;",
		"error_log /tmp/error.log;",
		"fastcgi_pass unix:/run/php/other.sock;",
		"include /etc/nginx/mime.types;",
		"load_module modules/ngx_http_perl_module.so;",
		"lua_code_cache off;",
		"perl_modules /tmp;",
		"js_import /tmp/a.js;",
		"proxy_pass http://10.0.0.5:8080;",
		"alias /etc/passwd;",
		"user root;",
		"worker_processes 4;",
		"events { worker_connections 1024; }",
		"http { sendfile on; }",
		"upstream backend { server 127.0.0.1:8080; }",
		"resolver 8.8.8.8;",
		"set_real_ip_from 0.0.0.0/0;",
	}

	for _, snippet := range lockedCases {
		if err := ParseAndValidateCustomBlock(snippet, "alice", 64); err == nil {
			t.Errorf("expected locked directive snippet to be rejected: %s", snippet)
		}
	}
}

func TestNginxTokenizerRejectsInjectionsOversizeUnknownAndOutsidePaths(t *testing.T) {
	cases := []struct {
		name    string
		snippet string
	}{
		{"unknown directive", "dav_methods PUT;"},
		{"outside home path", "auth_basic \"Restricted\";\nauth_basic_user_file /etc/nginx/.htpasswd;"},
		{"other user home path", "auth_basic \"Restricted\";\nauth_basic_user_file /home/bob/.htpasswd;"},
		{"traversal path", "auth_basic_user_file /home/alice/../bob/.htpasswd;"},
		{"injected semicolon in value", "add_header Cache-Control \"public; root /etc\";"},
		{"injected newline in quote", "add_header Cache-Control \"public\nroot /etc\";"},
		{"backtick", "add_header Cache-Control \"`id`\";"},
		{"dollar brace", "add_header Cache-Control \"${HOME}\";"},
		{"non-utf8", string([]byte{0xff, 0xfe, 0xfd})},
		{"oversize bytes", strings.Repeat("# comment\n", 4000)},
		{"oversize lines", strings.Repeat("autoindex off;\n", 405)},
		{"over package body size", "client_max_body_size 256M;"},
		{"over fastcgi timeout", "fastcgi_read_timeout 600s;"},
		{"disallowed header", "add_header X-Evil-Header \"1\";"},
	}

	for _, tc := range cases {
		if err := ParseAndValidateCustomBlock(tc.snippet, "alice", 64); err == nil {
			t.Errorf("case %q: expected error, got nil", tc.name)
		}
	}
}

func TestNginxTokenizerAcceptsValidCustomBlock(t *testing.T) {
	tmpHome := t.TempDir()
	// Create a valid htpasswd path inside /home/alice? We test non-path directives + 127.0.0.1 proxy_pass
	valid := `
# Custom rules for customer
location /api/ {
    try_files $uri $uri/ /index.php?$query_string;
    proxy_pass http://127.0.0.1:3000;
}

location ~* \.(?:css|js)$ {
    expires 7d;
    add_header Cache-Control "public" always;
}

if ($request_uri ~ "^/old-path") {
    return 301 https://example.com/new-path;
}

rewrite ^/blog/(.*)$ /articles/$1 permanent;
client_max_body_size 32M;
fastcgi_read_timeout 120s;
gzip on;
autoindex off;
index index.php index.html;
`
	_ = tmpHome
	if err := ParseAndValidateCustomBlock(valid, "alice", 64); err != nil {
		t.Fatalf("expected valid custom block to pass, got error: %v", err)
	}
}

func TestNginxDomainWriteRollbackAndIndependence(t *testing.T) {
	availDir := t.TempDir()
	backupDir := t.TempDir()

	initialA, err := RenderVhostConfig(VhostTemplateData{
		Username:     "alice",
		Domain:       "alpha.example.com",
		DocumentRoot: "/home/alice/public_html",
	})
	if err != nil {
		t.Fatalf("render initial A: %v", err)
	}
	initialB, err := RenderVhostConfig(VhostTemplateData{
		Username:     "bob",
		Domain:       "beta.example.com",
		DocumentRoot: "/home/bob/public_html",
	})
	if err != nil {
		t.Fatalf("render initial B: %v", err)
	}

	pathA := filepath.Join(availDir, "alpha.example.com.conf")
	pathB := filepath.Join(availDir, "beta.example.com.conf")
	if err := os.WriteFile(pathA, []byte(initialA), 0640); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(pathB, []byte(initialB), 0640); err != nil {
		t.Fatal(err)
	}

	// 1. Failing nginx -t must roll back alpha.example.com.conf and leave beta.example.com.conf untouched
	failExec := &fakeNginxExecutor{
		testErr:    errors.New("exit status 1"),
		testOutput: "nginx: [emerg] unknown directive \"bad\"",
	}
	writeAct := NewNginxDomainWriteActionForTest(failExec, availDir, backupDir)
	payloadFail, _ := json.Marshal(NginxDomainWritePayload{
		Domain:      "alpha.example.com",
		Username:    "alice",
		CustomBlock: "autoindex off;",
	})

	res, err := writeAct.Execute(context.Background(), payloadFail, false)
	if err == nil || res.Success {
		t.Fatalf("expected write to fail when nginx -t fails")
	}

	afterFailA, _ := os.ReadFile(pathA)
	if string(afterFailA) != initialA {
		t.Fatalf("expected alpha.example.com.conf to be rolled back to initial content")
	}
	afterFailB, _ := os.ReadFile(pathB)
	if string(afterFailB) != initialB {
		t.Fatalf("expected beta.example.com.conf to remain untouched")
	}

	// 2. Successful write updates only alpha.example.com.conf and reloads nginx
	okExec := &fakeNginxExecutor{
		testErr:    nil,
		testOutput: "nginx: configuration file /etc/nginx/nginx.conf test is successful",
	}
	writeOkAct := NewNginxDomainWriteActionForTest(okExec, availDir, backupDir)
	payloadOk, _ := json.Marshal(NginxDomainWritePayload{
		Domain:      "alpha.example.com",
		Username:    "alice",
		CustomBlock: "    gzip on;",
	})

	resOk, err := writeOkAct.Execute(context.Background(), payloadOk, false)
	if err != nil || !resOk.Success {
		t.Fatalf("expected write to succeed, got err=%v", err)
	}
	if okExec.reloaded != 1 {
		t.Fatalf("expected nginx reload to be called once, got %d", okExec.reloaded)
	}

	afterOkA, _ := os.ReadFile(pathA)
	if !strings.Contains(string(afterOkA), "gzip on;") {
		t.Fatalf("expected alpha.example.com.conf to contain new custom block")
	}
	afterOkB, _ := os.ReadFile(pathB)
	if string(afterOkB) != initialB {
		t.Fatalf("expected beta.example.com.conf to remain independent")
	}
}
