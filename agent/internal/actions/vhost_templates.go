package actions

import (
	"bytes"
	"fmt"
	"html/template"
	"strings"
)

type RedirectRule struct {
	Source string `json:"source"`
	Target string `json:"target"`
	Code   int    `json:"code"`
}

type ProtectedDirectoryRule struct {
	Path         string `json:"path"`
	Realm        string `json:"realm"`
	HtpasswdPath string `json:"htpasswd_path"`
}

type VhostTemplateData struct {
	Username           string
	Domain             string
	ServerNames        string
	DocumentRoot       string
	AppProfile         string
	PHPVersion         string
	ForceHTTPS         bool
	SSLEnabled         bool
	SSLCertPath        string
	SSLKeyPath         string
	HstsEnabled        bool
	WwwRedirect        string // "none", "force_www", "strip_www"
	Redirects          []RedirectRule
	ProtectedDirs      []ProtectedDirectoryRule
	WwwRedirectBlock   string
	RedirectsBlock     string
	ProtectedDirsBlock string
}

const nginxVhostTemplate = `# HPanel Virtual Host Configuration
# Domain: {{.Domain}}
# App Profile: {{.AppProfile}}
# Managed atomically by panel-agent. Do not edit manually.

{{if .WwwRedirectBlock}}
{{.WwwRedirectBlock}}
{{end}}

{{if .ForceHTTPS}}
server {
    listen 80;
    listen [::]:80;
    server_name {{.ServerNames}};

    # Shared ACME challenge handler for Let's Encrypt / Certbot
    location ^~ /.well-known/acme-challenge/ {
        default_type "text/plain";
        root {{.DocumentRoot}};
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name {{.ServerNames}};

    ssl_certificate {{.SSLCertPath}};
    ssl_certificate_key {{.SSLKeyPath}};
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers off;
{{if .HstsEnabled}}
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
{{end}}
{{else if .SSLEnabled}}
server {
    listen 80;
    listen [::]:80;
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name {{.ServerNames}};

    ssl_certificate {{.SSLCertPath}};
    ssl_certificate_key {{.SSLKeyPath}};
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers off;
{{if .HstsEnabled}}
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
{{end}}

    # Shared ACME challenge handler for Let's Encrypt / Certbot
    location ^~ /.well-known/acme-challenge/ {
        default_type "text/plain";
        root {{.DocumentRoot}};
    }
{{else}}
server {
    listen 80;
    listen [::]:80;
    server_name {{.ServerNames}};

    # Shared ACME challenge handler for Let's Encrypt / Certbot
    location ^~ /.well-known/acme-challenge/ {
        default_type "text/plain";
        root {{.DocumentRoot}};
    }
{{end}}

    root {{.DocumentRoot}};
    index {{if eq .AppProfile "static-spa"}}index.html index.htm{{else}}index.php index.html index.htm{{end}};

    # Per-account log isolation
    access_log /home/{{.Username}}/logs/{{.Domain}}_access.log;
    error_log /home/{{.Username}}/logs/{{.Domain}}_error.log error;

    charset utf-8;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

{{if .RedirectsBlock}}
    # Custom URL Redirects
{{.RedirectsBlock}}
{{end}}

{{if .ProtectedDirsBlock}}
    # Password-Protected Directories
{{.ProtectedDirsBlock}}
{{end}}

{{if eq .AppProfile "laravel"}}
    # Profile: Laravel
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php{{.PHPVersion}}-fpm-{{.Username}}.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
        fastcgi_index index.php;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
{{else if eq .AppProfile "wordpress"}}
    # Profile: WordPress
    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    # Block access to sensitive WordPress files
    location = /wp-config.php { deny all; }
    location = /xmlrpc.php { deny all; }

    # Disable PHP script execution inside uploads directory
    location ~* /wp-content/uploads/.*\.php$ {
        deny all;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php{{.PHPVersion}}-fpm-{{.Username}}.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_index index.php;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

    location ~ /\. {
        deny all;
    }
{{else if eq .AppProfile "static-spa"}}
    # Profile: Static / SPA (Single Page Application)
    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~* \.(?:css|js|jpg|jpeg|gif|png|ico|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, no-transform";
    }

    location ~ /\. {
        deny all;
    }
{{else}}
    # Profile: PHP Generic
    location / {
        try_files $uri $uri/ $uri.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php{{.PHPVersion}}-fpm-{{.Username}}.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_index index.php;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

    location ~ /\. {
        deny all;
    }
{{end}}
}
`

func buildWwwRedirectBlock(domain, mode string) string {
	switch mode {
	case "force_www":
		// Redirect bare domain to www
		return fmt.Sprintf(`server {
    listen 80;
    listen [::]:80;
    server_name %s;
    return 301 $scheme://www.%s$request_uri;
}`, domain, domain)
	case "strip_www":
		// Redirect www to bare domain
		return fmt.Sprintf(`server {
    listen 80;
    listen [::]:80;
    server_name www.%s;
    return 301 $scheme://%s$request_uri;
}`, domain, domain)
	default:
		return ""
	}
}

func buildRedirectsBlock(redirects []RedirectRule) string {
	if len(redirects) == 0 {
		return ""
	}

	var sb strings.Builder
	for _, r := range redirects {
		code := r.Code
		if code != 301 && code != 302 {
			code = 301
		}
		// Strict escaping to prevent Nginx config injection
		source := strings.TrimSpace(r.Source)
		target := strings.TrimSpace(r.Target)
		if source != "" && target != "" && !strings.ContainsAny(source, ";{}\n\r") && !strings.ContainsAny(target, ";{}\n\r") {
			sb.WriteString(fmt.Sprintf("    rewrite ^%s$ %s %s;\n", regexpEscape(source), target, redirectFlag(code)))
		}
	}
	return sb.String()
}

func redirectFlag(code int) string {
	if code == 301 {
		return "permanent"
	}
	return "redirect"
}

func regexpEscape(s string) string {
	// Escape dot and special chars if not already regex
	return strings.ReplaceAll(s, ".", "\\.")
}

func buildProtectedDirsBlock(dirs []ProtectedDirectoryRule, username, phpVersion string) string {
	if len(dirs) == 0 {
		return ""
	}
	var sb strings.Builder
	for _, d := range dirs {
		p := strings.TrimSpace(d.Path)
		if !strings.HasPrefix(p, "/") || strings.ContainsAny(p, ";{}\n\r") {
			continue
		}
		realm := strings.TrimSpace(d.Realm)
		if realm == "" {
			realm = "Restricted Area"
		}
		realm = strings.ReplaceAll(realm, "\"", "\\\"")
		htPath := strings.TrimSpace(d.HtpasswdPath)
		if htPath == "" || strings.ContainsAny(htPath, ";{}\n\r") {
			continue
		}

		sb.WriteString(fmt.Sprintf(`    location %s {
        auth_basic "%s";
        auth_basic_user_file %s;
        try_files $uri $uri/ /index.php?$query_string;

        location ~ \.php$ {
            include fastcgi_params;
            fastcgi_pass unix:/run/php/php%s-fpm-%s.sock;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            fastcgi_index index.php;
        }
    }
`, p, realm, htPath, phpVersion, username))
	}
	return sb.String()
}

// DefaultCatchAllVhostTemplate drops all undefined host requests on ports 80 and 443.
const DefaultCatchAllVhostTemplate = `# HPanel Default Catch-All Configuration
# Drops all undefined host requests on ports 80 and 443
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    return 444;
}

server {
    listen 443 ssl default_server;
    listen [::]:443 ssl default_server;
    server_name _;
    ssl_reject_handshake on;
    return 444;
}
`

// RenderDefaultVhostConfig returns the default_server catch-all configuration block.
func RenderDefaultVhostConfig() string {
	return DefaultCatchAllVhostTemplate
}

// RenderVhostConfig renders the full Nginx server block configuration.
func RenderVhostConfig(data VhostTemplateData) (string, error) {
	if data.PHPVersion == "" {
		data.PHPVersion = "8.3"
	}
	if data.AppProfile == "" {
		data.AppProfile = "php-generic"
	}
	if data.DocumentRoot == "" && data.Username != "" {
		data.DocumentRoot = fmt.Sprintf("/home/%s/public_html", data.Username)
	}

	bareDomain := strings.TrimPrefix(data.Domain, "www.")
	wwwDomain := "www." + bareDomain

	if data.ServerNames == "" {
		switch data.WwwRedirect {
		case "force_www":
			data.ServerNames = wwwDomain
		case "strip_www":
			data.ServerNames = bareDomain
		default:
			data.ServerNames = fmt.Sprintf("%s %s", bareDomain, wwwDomain)
		}
	} else if data.WwwRedirect != "force_www" && data.WwwRedirect != "strip_www" {
		names := strings.Fields(data.ServerNames)
		hasBare := false
		hasWww := false
		for _, n := range names {
			if n == bareDomain {
				hasBare = true
			}
			if n == wwwDomain {
				hasWww = true
			}
		}
		if !hasBare {
			names = append([]string{bareDomain}, names...)
		}
		if !hasWww {
			names = append(names, wwwDomain)
		}
		data.ServerNames = strings.Join(names, " ")
	}

	data.WwwRedirectBlock = buildWwwRedirectBlock(data.Domain, data.WwwRedirect)
	data.RedirectsBlock = buildRedirectsBlock(data.Redirects)
	data.ProtectedDirsBlock = buildProtectedDirsBlock(data.ProtectedDirs, data.Username, data.PHPVersion)

	tmpl, err := template.New("vhost").Parse(nginxVhostTemplate)
	if err != nil {
		return "", fmt.Errorf("parsing vhost template: %w", err)
	}

	var buf bytes.Buffer
	if err := tmpl.Execute(&buf, data); err != nil {
		return "", fmt.Errorf("executing vhost template: %w", err)
	}

	return buf.String(), nil
}
