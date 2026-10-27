package actions

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strconv"
	"strings"
	"time"
)

const (
	maxHtaccessSizeBytes = 256 * 1024 // 256 KB
	maxHtaccessLines     = 2000
)

var (
	// Allow-list for HTTP response headers
	allowedResponseHeaders = map[string]bool{
		"x-frame-options":              true,
		"x-content-type-options":        true,
		"x-xss-protection":             true,
		"referrer-policy":              true,
		"strict-transport-security":    true,
		"content-security-policy":      true,
		"permissions-policy":           true,
		"access-control-allow-origin":  true,
		"access-control-allow-methods": true,
		"access-control-allow-headers": true,
		"access-control-expose-headers": true,
		"access-control-max-age":       true,
	}

	// Allow-list for DirectoryIndex
	allowedDirectoryIndexes = map[string]bool{
		"index.php":  true,
		"index.html": true,
		"index.htm":  true,
	}
)

type HtaccessRuleReport struct {
	Line        int    `json:"line"`
	Directive   string `json:"directive"`
	Status      string `json:"status"` // "converted", "unsupported", "warning"
	Message     string `json:"message"`
	FeatureLink string `json:"feature_link,omitempty"` // "redirects", "protected-dirs", "php", "web-server"
}

type HtaccessConversionResult struct {
	Exists           bool                 `json:"exists"`
	ConvertedCount   int                  `json:"converted_count"`
	UnsupportedCount int                  `json:"unsupported_count"`
	WarningCount     int                  `json:"warning_count"`
	Rules            []HtaccessRuleReport `json:"rules"`
	NginxConfig      string               `json:"nginx_config"`
}

type rawLine struct {
	startLine int
	text      string
}

type pendingRewriteCond struct {
	line    int
	testStr string
	pattern string
	flags   string
}

// ParseAndConvertHtaccess parses an Apache .htaccess file and converts supported rules to Nginx.
func ParseAndConvertHtaccess(domain, content string) (*HtaccessConversionResult, error) {
	if len(content) > maxHtaccessSizeBytes {
		return nil, fmt.Errorf(".htaccess exceeds maximum allowed size of 256 KB (%d bytes)", len(content))
	}

	lines := splitAndJoinContinuationLines(content)
	if len(lines) > maxHtaccessLines {
		return nil, fmt.Errorf(".htaccess exceeds maximum limit of 2000 lines (%d lines)", len(lines))
	}

	var rules []HtaccessRuleReport
	var nginxDirectives []string
	var pendingConds []pendingRewriteCond

	// Track which unique blocks we've added to avoid duplicates
	hasTryFilesFrontController := false
	hasHttpsRedirect := false
	hasExpiresBlock := false
	authRealm := ""
	authUserFile := ""

	for _, rl := range lines {
		trimmed := strings.TrimSpace(rl.text)
		if trimmed == "" || strings.HasPrefix(trimmed, "#") {
			continue
		}

		fields := tokenizeApacheDirective(trimmed)
		if len(fields) == 0 {
			continue
		}

		verb := strings.ToLower(fields[0])

		switch verb {
		case "<ifmodule", "</ifmodule>", "<if", "</if>", "<files", "</files>", "<filesmatch", "</filesmatch>", "<directory", "</directory>":
			// Container tags are acknowledged
			rules = append(rules, HtaccessRuleReport{
				Line:      rl.startLine,
				Directive: trimmed,
				Status:    "converted",
				Message:   "Container directive processed.",
			})

		case "rewriteengine":
			val := ""
			if len(fields) > 1 {
				val = strings.ToLower(fields[1])
			}
			rules = append(rules, HtaccessRuleReport{
				Line:      rl.startLine,
				Directive: trimmed,
				Status:    "converted",
				Message:   fmt.Sprintf("RewriteEngine %s recognized.", val),
			})

		case "rewritebase":
			// In Nginx, location blocks are root-relative, so RewriteBase is acknowledged
			rules = append(rules, HtaccessRuleReport{
				Line:      rl.startLine,
				Directive: trimmed,
				Status:    "converted",
				Message:   "RewriteBase recognized.",
			})

		case "rewritecond":
			if len(fields) < 3 {
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "warning",
					Message:   "Malformed RewriteCond directive.",
				})
				continue
			}
			flags := ""
			if len(fields) > 3 {
				flags = fields[3]
			}
			pendingConds = append(pendingConds, pendingRewriteCond{
				line:    rl.startLine,
				testStr: fields[1],
				pattern: fields[2],
				flags:   flags,
			})

		case "rewriterule":
			if len(fields) < 3 {
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "warning",
					Message:   "Malformed RewriteRule directive.",
				})
				pendingConds = nil
				continue
			}

			pattern := fields[1]
			substitution := fields[2]
			flags := ""
			if len(fields) > 3 {
				flags = fields[3]
			}

			// 1. Detect Front Controller pattern: RewriteCond !-f, RewriteCond !-d, RewriteRule .* index.php
			if isFrontControllerPattern(pendingConds, pattern, substitution) {
				if !hasTryFilesFrontController {
					nginxDirectives = append(nginxDirectives, "try_files $uri $uri/ /index.php?$query_string;")
					hasTryFilesFrontController = true
				}
				for _, c := range pendingConds {
					rules = append(rules, HtaccessRuleReport{
						Line:      c.line,
						Directive: fmt.Sprintf("RewriteCond %s %s", c.testStr, c.pattern),
						Status:    "converted",
						Message:   "Converted to Nginx try_files front controller condition.",
					})
				}
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "converted",
					Message:   "Converted to try_files $uri $uri/ /index.php?$query_string;",
				})
				pendingConds = nil
				continue
			}

			// 2. Detect HTTPS enforcement redirect
			if isHttpsEnforcement(pendingConds, substitution) {
				if !hasHttpsRedirect {
					nginxDirectives = append(nginxDirectives, "if ($scheme != \"https\") {\n    return 301 https://$host$request_uri;\n}")
					hasHttpsRedirect = true
				}
				for _, c := range pendingConds {
					rules = append(rules, HtaccessRuleReport{
						Line:      c.line,
						Directive: fmt.Sprintf("RewriteCond %s %s", c.testStr, c.pattern),
						Status:    "converted",
						Message:   "Converted to Nginx HTTPS scheme check.",
					})
				}
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "converted",
					Message:   "Converted to 301 HTTPS redirect.",
				})
				pendingConds = nil
				continue
			}

			// 3. Detect canonical hostname redirect (e.g. www to non-www or vice-versa)
			if hostDirective, ok := isHostRedirect(pendingConds, substitution); ok {
				nginxDirectives = append(nginxDirectives, hostDirective)
				for _, c := range pendingConds {
					rules = append(rules, HtaccessRuleReport{
						Line:      c.line,
						Directive: fmt.Sprintf("RewriteCond %s %s", c.testStr, c.pattern),
						Status:    "converted",
						Message:   "Converted to Nginx host redirect condition.",
					})
				}
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "converted",
					Message:   "Converted canonical host redirect.",
				})
				pendingConds = nil
				continue
			}

			// 4. Standalone or custom RewriteRule
			// Check for Rewrite Loop Protection
			if isRewriteLoop(pattern, substitution) {
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "warning",
					Message:   "Potential rewrite loop detected; rule skipped for safety.",
					FeatureLink: "redirects",
				})
				pendingConds = nil
				continue
			}

			// Convert rule flags and pattern
			convertedRule, reportMsg := convertRewriteRule(pattern, substitution, flags, pendingConds)
			if convertedRule != "" {
				nginxDirectives = append(nginxDirectives, convertedRule)
				for _, c := range pendingConds {
					rules = append(rules, HtaccessRuleReport{
						Line:      c.line,
						Directive: fmt.Sprintf("RewriteCond %s %s", c.testStr, c.pattern),
						Status:    "converted",
						Message:   "Evaluated with associated RewriteRule.",
					})
				}
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "converted",
					Message:   reportMsg,
				})
			} else {
				rules = append(rules, HtaccessRuleReport{
					Line:        rl.startLine,
					Directive:   trimmed,
					Status:      "unsupported",
					Message:     "Complex rewrite rule could not be safely mapped to Nginx without shell/mod_rewrite evaluation.",
					FeatureLink: "redirects",
				})
			}
			pendingConds = nil

		case "redirect":
			// Syntax: Redirect [status] /source target
			status := 302
			source := ""
			target := ""
			if len(fields) == 3 {
				if strings.EqualFold(fields[1], "gone") || fields[1] == "410" {
					status = 410
					source = fields[2]
				} else {
					source = fields[1]
					target = fields[2]
				}
			} else if len(fields) >= 4 {
				status = parseRedirectStatus(fields[1])
				source = fields[2]
				target = fields[3]
			}

			if source == "" || (status != 410 && target == "") {
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "warning",
					Message:   "Malformed Redirect directive.",
				})
				continue
			}

			if status == 410 {
				nginxDirectives = append(nginxDirectives, fmt.Sprintf("location = %s {\n    return 410;\n}", source))
			} else {
				flag := "redirect"
				if status == 301 {
					flag = "permanent"
				}
				cleanSource := strings.TrimRight(source, "/")
				cleanRegex := "^" + regexp.QuoteMeta(cleanSource) + "(.*)$"
				nginxDirectives = append(nginxDirectives, fmt.Sprintf("rewrite %s %s$1 %s;", cleanRegex, target, flag))
			}

			rules = append(rules, HtaccessRuleReport{
				Line:      rl.startLine,
				Directive: trimmed,
				Status:    "converted",
				Message:   fmt.Sprintf("Converted to HTTP %d redirect for %s.", status, source),
			})

		case "redirectmatch":
			// Syntax: RedirectMatch [status] regex target
			status := 302
			pattern := ""
			target := ""
			if len(fields) == 3 {
				if strings.EqualFold(fields[1], "gone") || fields[1] == "410" {
					status = 410
					pattern = fields[2]
				} else {
					pattern = fields[1]
					target = fields[2]
				}
			} else if len(fields) >= 4 {
				status = parseRedirectStatus(fields[1])
				pattern = fields[2]
				target = fields[3]
			}

			if pattern == "" || (status != 410 && target == "") {
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "warning",
					Message:   "Malformed RedirectMatch directive.",
				})
				continue
			}

			if status == 410 {
				nginxDirectives = append(nginxDirectives, fmt.Sprintf("location ~ %s {\n    return 410;\n}", pattern))
			} else {
				flag := "redirect"
				if status == 301 {
					flag = "permanent"
				}
				nginxDirectives = append(nginxDirectives, fmt.Sprintf("rewrite %s %s %s;", pattern, target, flag))
			}

			rules = append(rules, HtaccessRuleReport{
				Line:      rl.startLine,
				Directive: trimmed,
				Status:    "converted",
				Message:   fmt.Sprintf("Converted regex redirect to HTTP %d.", status),
			})

		case "errordocument":
			// Syntax: ErrorDocument <code> <path|url|message>
			if len(fields) < 3 {
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "warning",
					Message:   "Malformed ErrorDocument directive.",
				})
				continue
			}

			code, err := strconv.Atoi(fields[1])
			if err != nil || code < 400 || code > 504 {
				rules = append(rules, HtaccessRuleReport{
					Line:        rl.startLine,
					Directive:   trimmed,
					Status:      "warning",
					Message:     fmt.Sprintf("Invalid HTTP error code '%s': must be between 400 and 504.", fields[1]),
					FeatureLink: "web-server",
				})
				continue
			}

			target := fields[2]
			if strings.HasPrefix(target, "/") {
				nginxDirectives = append(nginxDirectives, fmt.Sprintf("error_page %d %s;", code, target))
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "converted",
					Message:   fmt.Sprintf("Mapped HTTP %d to custom document %s.", code, target),
				})
			} else if strings.HasPrefix(target, "http://") || strings.HasPrefix(target, "https://") {
				nginxDirectives = append(nginxDirectives, fmt.Sprintf("error_page %d =302 %s;", code, target))
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "converted",
					Message:   fmt.Sprintf("Mapped HTTP %d redirect to %s.", code, target),
				})
			} else {
				rules = append(rules, HtaccessRuleReport{
					Line:        rl.startLine,
					Directive:   trimmed,
					Status:      "unsupported",
					Message:     "Text messages in ErrorDocument are not supported in Nginx; use a file path (e.g. /404.html) instead.",
					FeatureLink: "web-server",
				})
			}

		case "directoryindex":
			// Syntax: DirectoryIndex file1 file2 ...
			var allowed []string
			for _, f := range fields[1:] {
				cleanFile := strings.ToLower(strings.Trim(f, "\"'"))
				if allowedDirectoryIndexes[cleanFile] {
					allowed = append(allowed, cleanFile)
				}
			}

			if len(allowed) > 0 {
				nginxDirectives = append(nginxDirectives, fmt.Sprintf("index %s;", strings.Join(allowed, " ")))
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "converted",
					Message:   fmt.Sprintf("Configured directory index to %s.", strings.Join(allowed, " ")),
				})
			} else {
				rules = append(rules, HtaccessRuleReport{
					Line:        rl.startLine,
					Directive:   trimmed,
					Status:      "warning",
					Message:     "No allowed index files found (only index.php, index.html, index.htm supported).",
					FeatureLink: "web-server",
				})
			}

		case "header":
			// Syntax: Header [always] set|unset <name> "<value>"
			actionIdx := 1
			isAlways := false
			if len(fields) > 1 && strings.EqualFold(fields[1], "always") {
				isAlways = true
				actionIdx = 2
			}

			if len(fields) <= actionIdx {
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "warning",
					Message:   "Malformed Header directive.",
				})
				continue
			}

			subCmd := strings.ToLower(fields[actionIdx])
			headerName := ""
			if len(fields) > actionIdx+1 {
				headerName = fields[actionIdx+1]
			}
			cleanHeaderName := strings.ToLower(headerName)

			if cleanHeaderName == "set-cookie" {
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "unsupported",
					Message:   "Set-Cookie headers in .htaccess are prohibited for security.",
				})
				continue
			}

			if !allowedResponseHeaders[cleanHeaderName] {
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "unsupported",
					Message:   fmt.Sprintf("Header '%s' is not in the allowed security headers list.", headerName),
				})
				continue
			}

			if subCmd == "unset" {
				// Nginx doesn't unset headers without headers-more module; record as noted
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "converted",
					Message:   fmt.Sprintf("Header unset '%s' noted (default headers are not emitted unless configured).", headerName),
				})
				continue
			}

			headerValue := ""
			if len(fields) > actionIdx+2 {
				headerValue = strings.Join(fields[actionIdx+2:], " ")
				headerValue = strings.Trim(headerValue, "\"'")
			}

			// Reject newline injection in header values
			if strings.ContainsAny(headerValue, "\r\n\"`$") || strings.Contains(headerValue, "\\r") || strings.Contains(headerValue, "\\n") {
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "unsupported",
					Message:   "Header value contains forbidden characters or newlines (header injection protection).",
				})
				continue
			}

			alwaysFlag := ""
			if isAlways {
				alwaysFlag = " always"
			}
			nginxDirectives = append(nginxDirectives, fmt.Sprintf("add_header %s \"%s\"%s;", headerName, headerValue, alwaysFlag))
			rules = append(rules, HtaccessRuleReport{
				Line:      rl.startLine,
				Directive: trimmed,
				Status:    "converted",
				Message:   fmt.Sprintf("Added response header %s.", headerName),
			})

		case "expiresactive":
			rules = append(rules, HtaccessRuleReport{
				Line:      rl.startLine,
				Directive: trimmed,
				Status:    "converted",
				Message:   "Expires module enabled.",
			})

		case "expiresbytype":
			// Syntax: ExpiresByType image/jpeg "access plus 1 month"
			if len(fields) < 3 {
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "warning",
					Message:   "Malformed ExpiresByType directive.",
				})
				continue
			}

			timeSpec := strings.Join(fields[2:], " ")
			duration := parseApacheExpiresDuration(timeSpec)
			if duration != "" && !hasExpiresBlock {
				nginxDirectives = append(nginxDirectives, fmt.Sprintf("location ~* \\.(?:css|js|png|jpg|jpeg|gif|svg|ico|webp|woff|woff2)$ {\n    expires %s;\n    add_header Cache-Control \"public, no-transform\";\n}", duration))
				hasExpiresBlock = true
			}

			rules = append(rules, HtaccessRuleReport{
				Line:      rl.startLine,
				Directive: trimmed,
				Status:    "converted",
				Message:   fmt.Sprintf("Mapped static asset expires to %s.", duration),
			})

		case "options":
			for _, opt := range fields[1:] {
				lowerOpt := strings.ToLower(opt)
				if lowerOpt == "-indexes" {
					nginxDirectives = append(nginxDirectives, "autoindex off;")
					rules = append(rules, HtaccessRuleReport{
						Line:      rl.startLine,
						Directive: trimmed,
						Status:    "converted",
						Message:   "Directory indexing disabled (autoindex off).",
					})
				} else if lowerOpt == "+indexes" || lowerOpt == "indexes" {
					nginxDirectives = append(nginxDirectives, "autoindex on;")
					rules = append(rules, HtaccessRuleReport{
						Line:      rl.startLine,
						Directive: trimmed,
						Status:    "converted",
						Message:   "Directory indexing enabled (autoindex on).",
					})
				} else if lowerOpt == "+followsymlinks" || lowerOpt == "followsymlinks" {
					rules = append(rules, HtaccessRuleReport{
						Line:      rl.startLine,
						Directive: trimmed,
						Status:    "converted",
						Message:   "FollowSymLinks is default in Nginx.",
					})
				} else {
					rules = append(rules, HtaccessRuleReport{
						Line:      rl.startLine,
						Directive: trimmed,
						Status:    "unsupported",
						Message:   fmt.Sprintf("Option '%s' is not supported in Nginx.", opt),
					})
				}
			}

		case "require":
			// Require all denied / Require all granted
			joined := strings.ToLower(strings.Join(fields[1:], " "))
			if joined == "all denied" {
				nginxDirectives = append(nginxDirectives, "deny all;")
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "converted",
					Message:   "Access blocked (deny all).",
				})
			} else if joined == "all granted" {
				nginxDirectives = append(nginxDirectives, "allow all;")
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "converted",
					Message:   "Access permitted (allow all).",
				})
			} else if joined == "valid-user" {
				rules = append(rules, HtaccessRuleReport{
					Line:        rl.startLine,
					Directive:   trimmed,
					Status:      "converted",
					Message:     "Basic authentication user validation requirement acknowledged.",
					FeatureLink: "protected-dirs",
				})
			} else {
				rules = append(rules, HtaccessRuleReport{
					Line:        rl.startLine,
					Directive:   trimmed,
					Status:      "unsupported",
					Message:     fmt.Sprintf("Require directive '%s' is not supported.", joined),
					FeatureLink: "protected-dirs",
				})
			}

		case "deny":
			// Deny from all / Deny from 1.2.3.4
			if len(fields) >= 3 && strings.EqualFold(fields[1], "from") {
				target := fields[2]
				if strings.EqualFold(target, "all") {
					nginxDirectives = append(nginxDirectives, "deny all;")
				} else {
					nginxDirectives = append(nginxDirectives, fmt.Sprintf("deny %s;", target))
				}
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "converted",
					Message:   fmt.Sprintf("Converted Deny rule to deny %s;", target),
				})
			} else {
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "unsupported",
					Message:   "Complex Deny syntax not supported.",
				})
			}

		case "allow":
			// Allow from all / Allow from 1.2.3.4
			if len(fields) >= 3 && strings.EqualFold(fields[1], "from") {
				target := fields[2]
				if strings.EqualFold(target, "all") {
					nginxDirectives = append(nginxDirectives, "allow all;")
				} else {
					nginxDirectives = append(nginxDirectives, fmt.Sprintf("allow %s;", target))
				}
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "converted",
					Message:   fmt.Sprintf("Converted Allow rule to allow %s;", target),
				})
			} else {
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "unsupported",
					Message:   "Complex Allow syntax not supported.",
				})
			}

		case "authtype":
			if len(fields) > 1 && strings.EqualFold(fields[1], "basic") {
				rules = append(rules, HtaccessRuleReport{
					Line:        rl.startLine,
					Directive:   trimmed,
					Status:      "converted",
					Message:     "AuthType Basic recognized for HTTP basic authentication.",
					FeatureLink: "protected-dirs",
				})
			} else {
				rules = append(rules, HtaccessRuleReport{
					Line:        rl.startLine,
					Directive:   trimmed,
					Status:      "unsupported",
					Message:     "Only AuthType Basic is supported.",
					FeatureLink: "protected-dirs",
				})
			}

		case "authname":
			if len(fields) > 1 {
				authRealm = strings.Trim(strings.Join(fields[1:], " "), "\"'")
				rules = append(rules, HtaccessRuleReport{
					Line:        rl.startLine,
					Directive:   trimmed,
					Status:      "converted",
					Message:     fmt.Sprintf("Auth realm configured as '%s'.", authRealm),
					FeatureLink: "protected-dirs",
				})
			}

		case "authuserfile":
			if len(fields) > 1 {
				authUserFile = strings.Trim(fields[1], "\"'")
				rules = append(rules, HtaccessRuleReport{
					Line:        rl.startLine,
					Directive:   trimmed,
					Status:      "converted",
					Message:     fmt.Sprintf("Auth htpasswd path configured as %s.", authUserFile),
					FeatureLink: "protected-dirs",
				})
			}

		// Explicitly Unsupported Directives with Helpful Context
		case "php_value", "php_flag", "php_admin_value", "php_admin_flag":
			rules = append(rules, HtaccessRuleReport{
				Line:        rl.startLine,
				Directive:   trimmed,
				Status:      "unsupported",
				Message:     fmt.Sprintf("PHP directive '%s' cannot be configured via .htaccess in Nginx. Configure PHP settings in the panel's PHP Manager instead.", fields[0]),
				FeatureLink: "php",
			})

		case "setenv":
			rules = append(rules, HtaccessRuleReport{
				Line:        rl.startLine,
				Directive:   trimmed,
				Status:      "unsupported",
				Message:     "Environment variables (SetEnv) are not supported via .htaccess. Configure environment variables in your application's .env file.",
				FeatureLink: "php",
			})

		case "addhandler", "addtype":
			rules = append(rules, HtaccessRuleReport{
				Line:        rl.startLine,
				Directive:   trimmed,
				Status:      "unsupported",
				Message:     fmt.Sprintf("Handler and MIME type directives (%s) are managed globally by Nginx.", fields[0]),
				FeatureLink: "web-server",
			})

		case "order":
			rules = append(rules, HtaccessRuleReport{
				Line:        rl.startLine,
				Directive:   trimmed,
				Status:      "unsupported",
				Message:     "Apache Order directives are not directly supported. Use simple Allow/Deny IP rules or the Protected Directories feature.",
				FeatureLink: "protected-dirs",
			})

		default:
			if strings.HasPrefix(verb, "sec") || strings.Contains(verb, "mod_security") {
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "unsupported",
					Message:   "ModSecurity directives must be configured at the global server security level.",
				})
			} else {
				rules = append(rules, HtaccessRuleReport{
					Line:      rl.startLine,
					Directive: trimmed,
					Status:    "unsupported",
					Message:   fmt.Sprintf("Directive '%s' is not supported by the Nginx .htaccess compatibility layer.", fields[0]),
				})
			}
		}
	}

	// If basic auth was configured with both name and file, render Nginx directives
	if authRealm != "" && authUserFile != "" {
		safeRealm := strings.ReplaceAll(authRealm, "\"", "\\\"")
		nginxDirectives = append(nginxDirectives, fmt.Sprintf("auth_basic \"%s\";\nauth_basic_user_file %s;", safeRealm, authUserFile))
	} else if authUserFile != "" && authRealm == "" {
		nginxDirectives = append(nginxDirectives, fmt.Sprintf("auth_basic \"Restricted Area\";\nauth_basic_user_file %s;", authUserFile))
	}

	var convertedCount, unsupportedCount, warningCount int
	for _, r := range rules {
		switch r.Status {
		case "converted":
			convertedCount++
		case "unsupported":
			unsupportedCount++
		case "warning":
			warningCount++
		}
	}

	var sb strings.Builder
	sb.WriteString(fmt.Sprintf("# HPanel Converted .htaccess Rules for %s\n", domain))
	sb.WriteString("# Automatically generated by panel-agent from .htaccess. Do not edit directly.\n\n")

	for _, d := range nginxDirectives {
		sb.WriteString(d)
		sb.WriteString("\n\n")
	}

	return &HtaccessConversionResult{
		Exists:           true,
		ConvertedCount:   convertedCount,
		UnsupportedCount: unsupportedCount,
		WarningCount:     warningCount,
		Rules:            rules,
		NginxConfig:      strings.TrimSpace(sb.String()) + "\n",
	}, nil
}

// splitAndJoinContinuationLines splits content into logical lines, joining lines that end with '\'.
func splitAndJoinContinuationLines(content string) []rawLine {
	scanner := bufio.NewScanner(strings.NewReader(content))
	var result []rawLine

	currentLineNum := 0
	currentLogicalLineNum := 1
	var currentBuilder strings.Builder

	for scanner.Scan() {
		currentLineNum++
		line := scanner.Text()

		trimmed := strings.TrimRight(line, " \t\r")
		if strings.HasSuffix(trimmed, "\\") {
			if currentBuilder.Len() == 0 {
				currentLogicalLineNum = currentLineNum
			}
			currentBuilder.WriteString(strings.TrimSuffix(trimmed, "\\"))
			currentBuilder.WriteString(" ")
			continue
		}

		if currentBuilder.Len() > 0 {
			currentBuilder.WriteString(line)
			result = append(result, rawLine{
				startLine: currentLogicalLineNum,
				text:      currentBuilder.String(),
			})
			currentBuilder.Reset()
		} else {
			result = append(result, rawLine{
				startLine: currentLineNum,
				text:      line,
			})
		}
	}

	if currentBuilder.Len() > 0 {
		result = append(result, rawLine{
			startLine: currentLogicalLineNum,
			text:      currentBuilder.String(),
		})
	}

	return result
}

// tokenizeApacheDirective splits a directive string into arguments, respecting double and single quotes.
func tokenizeApacheDirective(line string) []string {
	var tokens []string
	var current strings.Builder
	inDoubleQuotes := false
	inSingleQuotes := false

	for i := 0; i < len(line); i++ {
		ch := line[i]

		if ch == '"' && !inSingleQuotes {
			inDoubleQuotes = !inDoubleQuotes
			continue
		}
		if ch == '\'' && !inDoubleQuotes {
			inSingleQuotes = !inSingleQuotes
			continue
		}

		if (ch == ' ' || ch == '\t') && !inDoubleQuotes && !inSingleQuotes {
			if current.Len() > 0 {
				tokens = append(tokens, current.String())
				current.Reset()
			}
			continue
		}

		current.WriteByte(ch)
	}

	if current.Len() > 0 {
		tokens = append(tokens, current.String())
	}

	return tokens
}

func isFrontControllerPattern(conds []pendingRewriteCond, pattern, substitution string) bool {
	if len(conds) < 2 {
		return false
	}

	hasNotFile := false
	hasNotDir := false

	for _, c := range conds {
		if strings.Contains(c.testStr, "REQUEST_FILENAME") {
			if c.pattern == "!-f" {
				hasNotFile = true
			} else if c.pattern == "!-d" {
				hasNotDir = true
			}
		}
	}

	if !hasNotFile || !hasNotDir {
		return false
	}

	subLower := strings.ToLower(substitution)
	return strings.HasSuffix(subLower, "index.php") || subLower == "/index.php" || subLower == "index.php"
}

func isHttpsEnforcement(conds []pendingRewriteCond, substitution string) bool {
	hasHttpsOff := false
	for _, c := range conds {
		if strings.Contains(c.testStr, "HTTPS") && (c.pattern == "off" || c.pattern == "!=on" || c.pattern == "!on") {
			hasHttpsOff = true
		} else if strings.Contains(c.testStr, "SERVER_PORT") && c.pattern == "^80$" {
			hasHttpsOff = true
		}
	}

	return hasHttpsOff && strings.HasPrefix(strings.ToLower(substitution), "https://")
}

func isHostRedirect(conds []pendingRewriteCond, substitution string) (string, bool) {
	for _, c := range conds {
		if strings.Contains(c.testStr, "HTTP_HOST") {
			if strings.Contains(c.pattern, "www\\.") || strings.Contains(c.pattern, "^www\\.") {
				// Strip www
				return "if ($host ~* ^www\\.(.*)$) {\n    return 301 $scheme://$1$request_uri;\n}", true
			}
		}
	}
	return "", false
}

func isRewriteLoop(pattern, substitution string) bool {
	cleanPat := strings.Trim(pattern, "^$")
	cleanSub := strings.TrimLeft(substitution, "/")

	if cleanPat == cleanSub && cleanPat != "" {
		return true
	}
	if pattern == "^(.*)$" && (substitution == "$1" || substitution == "/$1") {
		return true
	}
	return false
}

func convertRewriteRule(pattern, substitution, flags string, conds []pendingRewriteCond) (string, string) {
	cleanFlags := strings.ToUpper(strings.Trim(flags, "[]"))
	flagTokens := strings.Split(cleanFlags, ",")
	hasFlag := func(f string) bool {
		for _, ft := range flagTokens {
			if strings.TrimSpace(ft) == f || strings.HasPrefix(strings.TrimSpace(ft), f+"=") {
				return true
			}
		}
		return false
	}

	isCaseInsensitive := hasFlag("NC")
	isForbidden := hasFlag("F")
	isGone := hasFlag("G")
	isRedirect301 := false
	isRedirect302 := false

	for _, ft := range flagTokens {
		t := strings.TrimSpace(ft)
		if t == "R=301" {
			isRedirect301 = true
		} else if t == "R=302" || t == "R" {
			isRedirect302 = true
		}
	}

	// Normalize regex pattern for Nginx:
	// Nginx rewrite matches against $uri, which always starts with '/'
	nginxPattern := pattern
	if !strings.HasPrefix(nginxPattern, "^/") && !strings.HasPrefix(nginxPattern, "/") {
		if strings.HasPrefix(nginxPattern, "^") {
			nginxPattern = "^/" + strings.TrimPrefix(nginxPattern, "^")
		} else {
			nginxPattern = "/" + nginxPattern
		}
	}

	if isCaseInsensitive && !strings.HasPrefix(nginxPattern, "(?i)") {
		nginxPattern = "(?i)" + nginxPattern
	}

	if isForbidden {
		return fmt.Sprintf("location ~ %s {\n    return 403;\n}", nginxPattern), "Converted to 403 Forbidden rule."
	}
	if isGone {
		return fmt.Sprintf("location ~ %s {\n    return 410;\n}", nginxPattern), "Converted to 410 Gone rule."
	}

	// Format substitution backreferences (%1 -> $1 in Nginx if coming from condition)
	nginxSub := substitution
	nginxSub = strings.ReplaceAll(nginxSub, "%1", "$1")
	nginxSub = strings.ReplaceAll(nginxSub, "%2", "$2")

	flagWord := "last"
	desc := "rewrite rule"
	if isRedirect301 {
		flagWord = "permanent"
		desc = "301 permanent redirect"
	} else if isRedirect302 {
		flagWord = "redirect"
		desc = "302 temporary redirect"
	}

	return fmt.Sprintf("rewrite %s %s %s;", nginxPattern, nginxSub, flagWord), fmt.Sprintf("Converted to %s.", desc)
}

func parseRedirectStatus(val string) int {
	v := strings.ToLower(val)
	switch v {
	case "permanent", "301":
		return 301
	case "temp", "302":
		return 302
	case "seeother", "303":
		return 303
	case "gone", "410":
		return 410
	case "307":
		return 307
	case "308":
		return 308
	default:
		if i, err := strconv.Atoi(v); err == nil && i >= 300 && i <= 451 {
			return i
		}
		return 302
	}
}

func parseApacheExpiresDuration(spec string) string {
	s := strings.ToLower(spec)
	s = strings.Trim(s, "\"'")

	if strings.Contains(s, "1 year") || strings.Contains(s, "12 months") {
		return "365d"
	}
	if strings.Contains(s, "1 month") || strings.Contains(s, "30 days") {
		return "30d"
	}
	if strings.Contains(s, "7 days") || strings.Contains(s, "1 week") {
		return "7d"
	}
	if strings.Contains(s, "1 day") || strings.Contains(s, "24 hours") {
		return "1d"
	}
	if strings.Contains(s, "1 hour") {
		return "1h"
	}

	// Format: A2592000 (access plus seconds)
	if strings.HasPrefix(s, "a") {
		if sec, err := strconv.Atoi(strings.TrimPrefix(s, "a")); err == nil {
			days := sec / 86400
			if days > 0 {
				return fmt.Sprintf("%dd", days)
			}
			return fmt.Sprintf("%ds", sec)
		}
	}

	return "30d"
}

// ============================================================================
// Htaccess Sync Action: htaccess.sync
// ============================================================================

type HtaccessSyncPayload struct {
	Username     string `json:"username"`
	Domain       string `json:"domain"`
	DocumentRoot string `json:"document_root"`
}

type HtaccessSyncAction struct {
	executor        commandExecutor
	hpanelCustomDir string
	backupDir       string
	isLinux         bool
}

func NewHtaccessSyncAction() *HtaccessSyncAction {
	return &HtaccessSyncAction{
		executor:        osCommandExecutor{},
		hpanelCustomDir: "/etc/nginx/hpanel.d",
		backupDir:       "/var/lib/panel-agent/config-backups",
		isLinux:         runtime.GOOS == "linux",
	}
}

func NewHtaccessSyncActionForTest(executor commandExecutor, hpanelCustomDir, backupDir string) *HtaccessSyncAction {
	return &HtaccessSyncAction{
		executor:        executor,
		hpanelCustomDir: hpanelCustomDir,
		backupDir:       backupDir,
		isLinux:         true,
	}
}

func (a *HtaccessSyncAction) Name() string {
	return "htaccess.sync"
}

func (a *HtaccessSyncAction) Validate(payload []byte) error {
	var p HtaccessSyncPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if err := ValidateDomain(p.Domain); err != nil {
		return err
	}
	if p.DocumentRoot == "" {
		p.DocumentRoot = fmt.Sprintf("/home/%s/public_html", p.Username)
	}
	return ValidateDocumentRoot(p.Username, p.DocumentRoot)
}

func (a *HtaccessSyncAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p HtaccessSyncPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	if p.DocumentRoot == "" {
		p.DocumentRoot = fmt.Sprintf("/home/%s/public_html", p.Username)
	}

	htaccessPath := filepath.Join(p.DocumentRoot, ".htaccess")
	destConfName := fmt.Sprintf("%s.htaccess.conf", p.Domain)
	destPath := filepath.Join(a.hpanelCustomDir, destConfName)

	_ = os.MkdirAll(a.hpanelCustomDir, 0755)
	_ = os.MkdirAll(a.backupDir, 0700)

	// If .htaccess does not exist: remove generated include file if present
	info, err := os.Stat(htaccessPath)
	if os.IsNotExist(err) {
		hadPrevious := false
		if _, err := os.Stat(destPath); err == nil {
			hadPrevious = true
			_ = os.Remove(destPath)
		}

		if hadPrevious && a.isLinux && !dryRun {
			if _, err := a.executor.LookPath("nginx"); err == nil {
				if testOut, testErr := a.executor.CombinedOutput(ctx, "nginx", "-t"); testErr == nil {
					if _, err := a.executor.LookPath("systemctl"); err == nil {
						_ = a.executor.Run(ctx, "systemctl", "reload", "nginx")
					}
				} else {
					return &ActionResult{
						Success: false,
						Error:   fmt.Sprintf("nginx syntax test failed after removing htaccess include: %s", string(testOut)),
					}, testErr
				}
			}
		}

		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"domain":            p.Domain,
				"exists":            false,
				"converted_count":   0,
				"unsupported_count": 0,
				"warning_count":     0,
				"rules":             []any{},
			},
		}, nil
	} else if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("stating .htaccess: %v", err)}, err
	}

	// Size validation
	if info.Size() > maxHtaccessSizeBytes {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf(".htaccess exceeds 256 KB limit (%d bytes)", info.Size()),
		}, errors.New("file too large")
	}

	contentBytes, err := os.ReadFile(htaccessPath)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("reading .htaccess: %v", err)}, err
	}

	// Parse and convert directives
	res, err := ParseAndConvertHtaccess(p.Domain, string(contentBytes))
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"domain":            p.Domain,
				"exists":            true,
				"converted_count":   res.ConvertedCount,
				"unsupported_count": res.UnsupportedCount,
				"warning_count":     res.WarningCount,
				"rules":             res.Rules,
				"nginx_config":      res.NginxConfig,
				"dry_run":           true,
			},
		}, nil
	}

	// Test-and-rollback deployment
	var backupFile string
	hadPrevious := false
	if _, err := os.Stat(destPath); err == nil {
		hadPrevious = true
		backupFile = filepath.Join(a.backupDir, fmt.Sprintf("%s.%d", destConfName, time.Now().UnixNano()))
		_ = copyFile(destPath, backupFile)
		pruneBackups(a.backupDir, destConfName)
	}

	tmpPath, err := CreateConfigTemp(destPath, []byte(res.NginxConfig), 0644)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("writing temp file: %v", err)}, err
	}

	if err := CommitConfigFile(tmpPath, destPath, 0644); err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("committing file: %v", err)}, err
	}

	// Verify with nginx -t
	if a.isLinux {
		if _, err := a.executor.LookPath("nginx"); err == nil {
			testOut, testErr := a.executor.CombinedOutput(ctx, "nginx", "-t")
			if testErr != nil {
				// Rollback
				if hadPrevious && backupFile != "" {
					_ = copyFile(backupFile, destPath)
				} else {
					_ = os.Remove(destPath)
				}
				errText := strings.TrimSpace(string(testOut))
				return &ActionResult{
					Success: false,
					Error:   fmt.Sprintf("nginx syntax test failed: %s", errText),
					Data: map[string]any{
						"nginx_output":      errText,
						"rolled_back":       true,
						"converted_count":   res.ConvertedCount,
						"unsupported_count": res.UnsupportedCount,
						"warning_count":     res.WarningCount,
						"rules":             res.Rules,
					},
				}, fmt.Errorf("nginx syntax test failed: %s", errText)
			}

			// Reload Nginx
			if _, err := a.executor.LookPath("systemctl"); err == nil {
				_ = a.executor.Run(ctx, "systemctl", "reload", "nginx")
			}
		}
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"domain":            p.Domain,
			"exists":            true,
			"converted_count":   res.ConvertedCount,
			"unsupported_count": res.UnsupportedCount,
			"warning_count":     res.WarningCount,
			"rules":             res.Rules,
			"nginx_config":      res.NginxConfig,
		},
	}, nil
}
