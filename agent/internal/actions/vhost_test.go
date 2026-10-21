package actions

import (
	"context"
	"encoding/json"
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
				"try_files $uri $uri/ /index.php?$query_string;",
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

