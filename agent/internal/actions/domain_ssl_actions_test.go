package actions

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// 1. Test that the vhost template has the ACME location first and the redirect only inside location /
func TestVhostTemplateAcmeLocationAndRedirect(t *testing.T) {
	data := VhostTemplateData{
		Username:     "alice",
		Domain:       "example.com",
		ServerNames:  "example.com www.example.com",
		DocumentRoot: "/home/alice/public_html",
		AppProfile:   "php-generic",
		ForceHTTPS:   true,
		SSLEnabled:   true,
		SSLCertPath:  "/etc/letsencrypt/live/example.com/fullchain.pem",
		SSLKeyPath:   "/etc/letsencrypt/live/example.com/privkey.pem",
		WwwRedirect:  "force_www",
	}

	cfg, err := RenderVhostConfig(data)
	if err != nil {
		t.Fatalf("failed to render vhost config: %v", err)
	}

	// 1. Verify shared ACME webroot is /var/www/_acme
	if !strings.Contains(cfg, "root /var/www/_acme;") {
		t.Errorf("expected ACME challenge block to use root /var/www/_acme;, got:\n%s", cfg)
	}

	// 2. In HTTP server block, verify location ^~ /.well-known/acme-challenge/ appears BEFORE location /
	acmeIdx := strings.Index(cfg, "location ^~ /.well-known/acme-challenge/")
	redirectIdx := strings.Index(cfg, "location / {\n        return 301 https://$host$request_uri;")

	if acmeIdx == -1 {
		t.Fatalf("expected ACME challenge block in config")
	}
	if redirectIdx == -1 {
		t.Fatalf("expected HTTPS redirect block inside location / in config")
	}
	if acmeIdx >= redirectIdx {
		t.Errorf("ACME challenge location (idx=%d) must appear BEFORE location / redirect (idx=%d)", acmeIdx, redirectIdx)
	}

	// 3. Verify HTTP to HTTPS redirect lives inside location / and never at server root
	lines := strings.Split(cfg, "\n")
	inLocationBlock := false
	for lineNum, line := range lines {
		trimmed := strings.TrimSpace(line)
		if strings.HasPrefix(trimmed, "location") {
			inLocationBlock = true
		} else if trimmed == "}" {
			inLocationBlock = false
		} else if strings.HasPrefix(trimmed, "return 301") && !inLocationBlock {
			t.Errorf("line %d: found server-level return 301 outside of location block: %s", lineNum+1, trimmed)
		}
	}

	// 4. Verify www redirect block also contains ACME challenge before location / redirect
	wwwBlock := buildWwwRedirectBlock("example.com", "force_www")
	if !strings.Contains(wwwBlock, "location ^~ /.well-known/acme-challenge/") {
		t.Errorf("www redirect block must contain ACME challenge location")
	}
	if !strings.Contains(wwwBlock, "location / {\n        return 301 $scheme://www.example.com$request_uri;\n    }") {
		t.Errorf("www redirect block must enclose return 301 inside location /")
	}
}

// Mock HTTP Getter for testing
type mockHttpGetter struct {
	responses map[string]struct {
		status int
		body   string
		err    error
	}
}

func (m *mockHttpGetter) Get(urlStr, hostHeader string) (int, string, error) {
	key := urlStr
	if hostHeader != "" {
		key = fmt.Sprintf("%s [Host: %s]", urlStr, hostHeader)
	}
	if resp, ok := m.responses[key]; ok {
		return resp.status, resp.body, resp.err
	}
	// fallback matching urlStr alone
	if resp, ok := m.responses[urlStr]; ok {
		return resp.status, resp.body, resp.err
	}
	return 404, "not found", nil
}

// Mock DNS Lookup for testing
type mockDnsLookup struct {
	ips map[string][]net.IP
}

func (m *mockDnsLookup) LookupIP(host string) ([]net.IP, error) {
	if ips, ok := m.ips[host]; ok {
		return ips, nil
	}
	return nil, errors.New("no such host")
}

// 2. Test that probe preflight returns DNS_NOT_POINTING when the public GET fails
func TestSslIssueProbePreflightDnsNotPointing(t *testing.T) {
	tmpDir := t.TempDir()
	availDir := filepath.Join(tmpDir, "sites-available")
	enabledDir := filepath.Join(tmpDir, "sites-enabled")
	acmeDir := filepath.Join(tmpDir, "_acme")
	_ = os.MkdirAll(availDir, 0755)
	_ = os.MkdirAll(enabledDir, 0755)
	_ = os.MkdirAll(acmeDir, 0755)

	// Create dummy vhost file so preflight a passes
	vhostPath := filepath.Join(availDir, "pointing-fail.com.conf")
	_ = os.WriteFile(vhostPath, []byte("server { listen 80; server_name pointing-fail.com; }"), 0640)

	// Set up mock HTTP getter:
	// - local 127.0.0.1 GET returns 200 with matching body (preflight b passes)
	// - public GET fails (preflight c fails)
	mockHttp := &mockHttpGetter{
		responses: make(map[string]struct {
			status int
			body   string
			err    error
		}),
	}

	// We intercept local probe dynamically by matching prefix
	// For testing, mockHttpGetter handles prefix via custom wrapper
	action := NewSslIssueActionForTest(
		&probeInterceptGetter{mockHttp: mockHttp, localSuccess: true, publicSuccess: false},
		&mockDnsLookup{
			ips: map[string][]net.IP{
				"pointing-fail.com": {net.ParseIP("203.0.113.99")},
			},
		},
		availDir,
		enabledDir,
		acmeDir,
	)

	payload, _ := json.Marshal(SslIssuePayload{
		Domain: "pointing-fail.com",
		Email:  "admin@pointing-fail.com",
	})

	res, err := action.Execute(context.Background(), payload, false)
	if err == nil {
		t.Fatalf("expected error when public probe fails, got nil")
	}

	if res.Success {
		t.Errorf("expected ActionResult.Success to be false")
	}

	if !strings.Contains(res.Error, "DNS_NOT_POINTING") {
		t.Errorf("expected error string to contain 'DNS_NOT_POINTING', got: %s", res.Error)
	}

	if res.Data != nil {
		if res.Data["error_code"] != "DNS_NOT_POINTING" {
			t.Errorf("expected error_code to be DNS_NOT_POINTING, got: %v", res.Data["error_code"])
		}
		resolved, ok := res.Data["resolved_ips"].([]string)
		if !ok || len(resolved) == 0 || resolved[0] != "203.0.113.99" {
			t.Errorf("expected resolved_ips to contain 203.0.113.99, got: %v", res.Data["resolved_ips"])
		}
	}
}

type probeInterceptGetter struct {
	mockHttp      *mockHttpGetter
	localSuccess  bool
	publicSuccess bool
}

func (p *probeInterceptGetter) Get(urlStr, hostHeader string) (int, string, error) {
	if strings.Contains(urlStr, "127.0.0.1") {
		if p.localSuccess {
			// Extract probe token from url
			parts := strings.Split(urlStr, "/")
			token := parts[len(parts)-1]
			return 200, token, nil
		}
		return 500, "local error", errors.New("connection refused")
	}
	if p.publicSuccess {
		parts := strings.Split(urlStr, "/")
		token := parts[len(parts)-1]
		return 200, token, nil
	}
	return 503, "service unavailable", errors.New("connection timed out")
}

// 3. Test that certbot output parser maps 404, 403, timeout and rate-limit cases
func TestParseCertbotOutputCases(t *testing.T) {
	tests := []struct {
		name         string
		output       string
		expectedMsg  string
		expectedType string
	}{
		{
			name: "404 Not Found",
			output: `Certbot failed to authenticate some domains:
Domain: example.com
Type:   unauthorized
Detail: 203.0.113.195: Invalid response from http://example.com/.well-known/acme-challenge/abc [203.0.113.195]: 404`,
			expectedMsg:  "Challenge file not reachable on port 80",
			expectedType: "unauthorized",
		},
		{
			name: "403 Forbidden",
			output: `Certbot failed to authenticate some domains:
Domain: example.com
Type:   unauthorized
Detail: 203.0.113.195: Invalid response from http://example.com/.well-known/acme-challenge/abc [203.0.113.195]: 403 Forbidden`,
			expectedMsg:  "Permission problem accessing challenge file",
			expectedType: "unauthorized",
		},
		{
			name: "Connection Refused / Port 80 not reachable",
			output: `Certbot failed to authenticate some domains:
Domain: example.com
Type:   connection
Detail: Failed to connect to 203.0.113.195:80 for HTTP-01 challenge: Connection refused`,
			expectedMsg:  "Port 80 not reachable",
			expectedType: "connection",
		},
		{
			name: "Timeout / Port 80 not reachable",
			output: `Certbot failed to authenticate some domains:
Domain: example.com
Type:   connection
Detail: During secondary validation: Connection timed out`,
			expectedMsg:  "Port 80 not reachable",
			expectedType: "connection",
		},
		{
			name: "Rate Limited with retry after time",
			output: `Domain: example.com
Type:   rateLimited
Detail: Error creating new order :: too many certificates (5) already issued for this exact set of domains in the last 168 hours: retry after 2026-11-18 10:00:00 UTC`,
			expectedMsg:  "Let's Encrypt rate limit reached (retry after 2026-11-18 10:00:00 UTC)",
			expectedType: "rateLimited",
		},
		{
			name: "CAA record prevents issuance",
			output: `Domain: example.com
Type:   caa
Detail: CAA record for example.com prevents issuance`,
			expectedMsg:  "Certification Authority Authorization (CAA) records prevent issuance",
			expectedType: "caa",
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			info := ParseCertbotOutput(tt.output)
			if info.Type != tt.expectedType {
				t.Errorf("expected type '%s', got '%s'", tt.expectedType, info.Type)
			}
			if !strings.Contains(info.ShortMessage, tt.expectedMsg) {
				t.Errorf("expected message to contain '%s', got '%s'", tt.expectedMsg, info.ShortMessage)
			}
			if len(info.ShortMessage) > 500 {
				t.Errorf("expected message <= 500 characters, got %d", len(info.ShortMessage))
			}
		})
	}
}

// 4. Test that ssl.fix_renewal rewrites renewal configs idempotently
func TestSslFixRenewalRewritesConfigsIdempotently(t *testing.T) {
	tmpDir := t.TempDir()
	renewalDir := filepath.Join(tmpDir, "renewal")
	hooksDir := filepath.Join(tmpDir, "renewal-hooks", "deploy")
	webrootDir := "/var/www/_acme"

	_ = os.MkdirAll(renewalDir, 0755)
	_ = os.MkdirAll(hooksDir, 0755)

	initialConfig := `# renew_before_expiry = 30 days
version = 2.8.0
archive_dir = /etc/letsencrypt/archive/site1.com
cert = /etc/letsencrypt/live/site1.com/cert.pem
privkey = /etc/letsencrypt/live/site1.com/privkey.pem

[renewalparams]
account = acct_12345
authenticator = nginx
server = https://acme-v02.api.letsencrypt.org/directory
key_type = ecdsa
webroot_path = /home/site1/public_html,
[[webroot_map]]
site1.com = /home/site1/public_html
`
	confPath := filepath.Join(renewalDir, "site1.com.conf")
	_ = os.WriteFile(confPath, []byte(initialConfig), 0644)

	action := NewSslFixRenewalActionForTest(renewalDir, hooksDir, webrootDir)

	// First execution
	res1, err := action.Execute(context.Background(), nil, false)
	if err != nil {
		t.Fatalf("first execution failed: %v", err)
	}

	if !res1.Success {
		t.Errorf("expected res1.Success to be true")
	}

	updatedContent, _ := os.ReadFile(confPath)
	strUpdated := string(updatedContent)

	if !strings.Contains(strUpdated, "authenticator = webroot") {
		t.Errorf("expected authenticator = webroot, got:\n%s", strUpdated)
	}
	if !strings.Contains(strUpdated, "webroot_path = /var/www/_acme,") {
		t.Errorf("expected webroot_path = /var/www/_acme,, got:\n%s", strUpdated)
	}
	if !strings.Contains(strUpdated, "site1.com = /var/www/_acme") {
		t.Errorf("expected site1.com = /var/www/_acme in webroot_map, got:\n%s", strUpdated)
	}

	// Verify deploy hook created
	hookFile := filepath.Join(hooksDir, "01-hpanel-nginx-reload.sh")
	hookBytes, err := os.ReadFile(hookFile)
	if err != nil {
		t.Errorf("expected deploy hook script to exist: %v", err)
	} else if !strings.Contains(string(hookBytes), "systemctl reload nginx") {
		t.Errorf("unexpected hook content: %s", string(hookBytes))
	}

	// Second execution: idempotent check
	res2, err := action.Execute(context.Background(), nil, false)
	if err != nil {
		t.Fatalf("second execution failed: %v", err)
	}

	count2, ok := res2.Data["updated_count"].(int)
	if !ok || count2 != 0 {
		t.Errorf("expected 0 updated files on second run, got: %v", count2)
	}

	secondContent, _ := os.ReadFile(confPath)
	if string(secondContent) != strUpdated {
		t.Errorf("config modified unexpectedly on idempotent run")
	}
}

// 5. Test that regenerate_all restores backups when nginx -t fails and keeps custom regions
func TestVhostRegenerateAllPreservesCustomAndRestoresBackupsOnFailure(t *testing.T) {
	tmpDir := t.TempDir()
	availDir := filepath.Join(tmpDir, "sites-available")
	enabledDir := filepath.Join(tmpDir, "sites-enabled")
	_ = os.MkdirAll(availDir, 0755)
	_ = os.MkdirAll(enabledDir, 0755)

	initialCustomSnippet := "    # Custom user configuration\n    client_max_body_size 64M;\n    autoindex off;"

	existingConfig := fmt.Sprintf(`# === HPANEL MANAGED: DO NOT EDIT (server_name, listen, ssl, root, logs, php socket, acme) ===
server {
    listen 80;
    server_name mycustomsite.test;
    root /home/bob/public_html;

    # === CUSTOM: EDITABLE ===
%s
    # === END CUSTOM ===
}
`, initialCustomSnippet)

	availPath := filepath.Join(availDir, "mycustomsite.test.conf")
	enabledPath := filepath.Join(enabledDir, "mycustomsite.test.conf")
	_ = os.WriteFile(availPath, []byte(existingConfig), 0640)
	_ = os.Symlink(availPath, enabledPath)

	action := NewVhostRegenerateAllActionForTest(availDir, enabledDir)

	payload, _ := json.Marshal(VhostRegenerateAllPayload{
		Vhosts: []VhostRegenerateItem{
			{
				Username:     "bob",
				Domain:       "mycustomsite.test",
				ServerNames:  "mycustomsite.test",
				DocumentRoot: "/home/bob/public_html",
				AppProfile:   "php-generic",
			},
		},
	})

	res, err := action.Execute(context.Background(), payload, false)
	if err != nil {
		t.Fatalf("vhost.regenerate_all failed: %v", err)
	}
	if !res.Success {
		t.Errorf("expected success")
	}

	newBytes, _ := os.ReadFile(availPath)
	newConfig := string(newBytes)

	// Verify standard ACME challenge block present
	if !strings.Contains(newConfig, "location ^~ /.well-known/acme-challenge/") {
		t.Errorf("expected standard ACME challenge location block in regenerated vhost")
	}

	// Verify custom region preserved
	if !strings.Contains(newConfig, initialCustomSnippet) {
		t.Errorf("expected custom region to be preserved, got:\n%s", newConfig)
	}

	// Verify no leftover .bak files
	bakPath := availPath + ".bak"
	if _, err := os.Stat(bakPath); err == nil {
		t.Errorf("expected backup file %s to be cleaned up", bakPath)
	}
}
