package actions

import (
	"errors"
	"fmt"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"unicode/utf8"
)

const (
	MaxCustomBlockBytes = 32 * 1024
	MaxCustomBlockLines = 400
	MaxFastcgiTimeout   = 300
	DefaultMaxBodyMB    = 128
)

type nginxTokKind int

const (
	tokWord nginxTokKind = iota
	tokSemi
	tokLBrace
	tokRBrace
	tokLParen
	tokRParen
)

type nginxToken struct {
	kind nginxTokKind
	val  string
	line int
}

var (
	simpleFilenameRegex = regexp.MustCompile(`^[a-zA-Z0-9._-]+$`)
	sizeLiteralRegex    = regexp.MustCompile(`^([0-9]+)([kKmMgG]?)$`)
	timeoutLiteralRegex = regexp.MustCompile(`^([0-9]+)([sS]?)$`)
	statusCodeRegex     = regexp.MustCompile(`^[345][0-9]{2}$`)
)

var allowedHeaders = map[string]bool{
	"cache-control":               true,
	"x-frame-options":             true,
	"x-content-type-options":      true,
	"referrer-policy":             true,
	"content-security-policy":     true,
	"permissions-policy":          true,
	"access-control-allow-origin": true,
}

var allowedIndexFiles = map[string]bool{
	"index.php":    true,
	"index.html":   true,
	"index.htm":    true,
	"default.html": true,
	"default.htm":  true,
}

var allowedIfVars = map[string]bool{
	"$request_uri":     true,
	"$http_user_agent": true,
	"$host":            true,
}

var allowedIfOps = map[string]bool{
	"=":   true,
	"!=":  true,
	"~":   true,
	"~*":  true,
	"!~":  true,
	"!~*": true,
}

var allowedPanelZones = map[string]bool{
	"one":        true,
	"req_limit":  true,
	"conn_limit": true,
}

var lockedPrefixes = []string{"lua_", "perl_", "js_", "worker_"}

var lockedDirectives = map[string]bool{
	"server":              true,
	"listen":              true,
	"server_name":         true,
	"root":                true,
	"ssl_certificate":     true,
	"ssl_certificate_key": true,
	"access_log":          true,
	"error_log":           true,
	"fastcgi_pass":        true,
	"include":             true,
	"load_module":         true,
	"alias":               true,
	"user":                true,
	"events":              true,
	"http":                true,
	"upstream":            true,
	"resolver":            true,
	"set_real_ip_from":    true,
}

// TokenizeNginxConfig lexes an Nginx configuration snippet into typed tokens.
func TokenizeNginxConfig(input string) ([]nginxToken, error) {
	if !utf8.ValidString(input) {
		return nil, errors.New("configuration must be valid UTF-8")
	}
	if len(input) > MaxCustomBlockBytes {
		return nil, fmt.Errorf("custom block exceeds maximum size of %d bytes", MaxCustomBlockBytes)
	}
	if input != "" {
		lineCount := strings.Count(input, "\n") + 1
		if lineCount > MaxCustomBlockLines {
			return nil, fmt.Errorf("custom block exceeds maximum of %d lines", MaxCustomBlockLines)
		}
	}
	if strings.ContainsRune(input, '\r') {
		return nil, errors.New("carriage return characters are not permitted")
	}
	if strings.ContainsRune(input, '`') {
		return nil, errors.New("backticks are not permitted in configuration")
	}
	if strings.Contains(input, "${") {
		return nil, errors.New("shell-style ${...} interpolation is not permitted")
	}

	var tokens []nginxToken
	line := 1
	i := 0
	n := len(input)

	for i < n {
		c := input[i]

		// Whitespace
		if c == ' ' || c == '\t' {
			i++
			continue
		}
		if c == '\n' {
			line++
			i++
			continue
		}

		// Comment
		if c == '#' {
			for i < n && input[i] != '\n' {
				i++
			}
			continue
		}

		// Punctuation
		switch c {
		case ';':
			tokens = append(tokens, nginxToken{kind: tokSemi, val: ";", line: line})
			i++
			continue
		case '{':
			tokens = append(tokens, nginxToken{kind: tokLBrace, val: "{", line: line})
			i++
			continue
		case '}':
			tokens = append(tokens, nginxToken{kind: tokRBrace, val: "}", line: line})
			i++
			continue
		case '(':
			tokens = append(tokens, nginxToken{kind: tokLParen, val: "(", line: line})
			i++
			continue
		case ')':
			tokens = append(tokens, nginxToken{kind: tokRParen, val: ")", line: line})
			i++
			continue
		}

		// Quoted string
		if c == '"' || c == '\'' {
			quote := c
			startLine := line
			i++
			var sb strings.Builder
			closed := false
			for i < n {
				ch := input[i]
				if ch == '\n' {
					return nil, fmt.Errorf("line %d: unclosed quoted string (newlines not allowed inside quotes)", startLine)
				}
				if ch == '\\' {
					if i+1 >= n {
						return nil, fmt.Errorf("line %d: trailing escape in quoted string", startLine)
					}
					next := input[i+1]
					if next == '\n' {
						return nil, fmt.Errorf("line %d: escaped newline not allowed inside quoted string", startLine)
					}
					sb.WriteByte(next)
					i += 2
					continue
				}
				if ch == quote {
					closed = true
					i++
					break
				}
				sb.WriteByte(ch)
				i++
			}
			if !closed {
				return nil, fmt.Errorf("line %d: unclosed quoted string", startLine)
			}
			tokens = append(tokens, nginxToken{kind: tokWord, val: sb.String(), line: startLine})
			continue
		}

		// Unquoted word
		startLine := line
		var sb strings.Builder
		for i < n {
			ch := input[i]
			if ch == ' ' || ch == '\t' || ch == '\n' || ch == ';' || ch == '{' || ch == '}' || ch == '(' || ch == ')' || ch == '#' {
				break
			}
			if ch == '"' || ch == '\'' {
				return nil, fmt.Errorf("line %d: unexpected quote inside unquoted token", startLine)
			}
			sb.WriteByte(ch)
			i++
		}
		tokens = append(tokens, nginxToken{kind: tokWord, val: sb.String(), line: startLine})
	}

	return tokens, nil
}

// ParseAndValidateCustomBlock parses and validates the customer-editable Nginx block.
func ParseAndValidateCustomBlock(input string, username string, maxBodyMB int) error {
	if maxBodyMB <= 0 {
		maxBodyMB = DefaultMaxBodyMB
	}

	tokens, err := TokenizeNginxConfig(input)
	if err != nil {
		return err
	}

	p := &nginxParser{
		tokens:    tokens,
		pos:       0,
		username:  username,
		maxBodyMB: maxBodyMB,
	}

	return p.parseBlock(ctxServer, false)
}

type parseContext int

const (
	ctxServer parseContext = iota
	ctxLocation
	ctxIf
)

type nginxParser struct {
	tokens    []nginxToken
	pos       int
	username  string
	maxBodyMB int
}

func (p *nginxParser) parseBlock(ctx parseContext, expectRBrace bool) error {
	for p.pos < len(p.tokens) {
		tok := p.tokens[p.pos]
		if tok.kind == tokRBrace {
			if !expectRBrace {
				return fmt.Errorf("line %d: unexpected '}'", tok.line)
			}
			p.pos++
			return nil
		}
		if tok.kind != tokWord {
			return fmt.Errorf("line %d: expected directive name, got '%s'", tok.line, tok.val)
		}

		directive := strings.ToLower(tok.val)
		dirLine := tok.line
		p.pos++

		if isLockedDirective(directive) {
			return fmt.Errorf("line %d: directive '%s' is locked and cannot be used in custom config", dirLine, tok.val)
		}

		// Collect arguments until ; or {
		var args []nginxToken
		foundTerm := false
		var termKind nginxTokKind

		for p.pos < len(p.tokens) {
			t := p.tokens[p.pos]
			if t.kind == tokSemi || t.kind == tokLBrace {
				termKind = t.kind
				foundTerm = true
				p.pos++
				break
			}
			if t.kind == tokRBrace {
				return fmt.Errorf("line %d: unexpected '}' before ';' in directive '%s'", t.line, directive)
			}
			args = append(args, t)
			p.pos++
		}

		if !foundTerm {
			return fmt.Errorf("line %d: directive '%s' is missing terminating ';' or '{'", dirLine, directive)
		}

		if termKind == tokLBrace {
			if err := p.handleBlockDirective(ctx, directive, dirLine, args); err != nil {
				return err
			}
		} else {
			if err := p.handleSimpleDirective(ctx, directive, dirLine, args); err != nil {
				return err
			}
		}
	}

	if expectRBrace {
		return errors.New("unexpected end of config: missing closing '}'")
	}
	return nil
}

func isLockedDirective(d string) bool {
	if lockedDirectives[d] {
		return true
	}
	for _, prefix := range lockedPrefixes {
		if strings.HasPrefix(d, prefix) {
			return true
		}
	}
	return false
}

func (p *nginxParser) handleBlockDirective(ctx parseContext, directive string, line int, args []nginxToken) error {
	switch directive {
	case "location":
		if ctx != ctxServer {
			return fmt.Errorf("line %d: 'location' blocks are only allowed at the top level of the custom block", line)
		}
		if err := validateLocationArgs(line, args); err != nil {
			return err
		}
		return p.parseBlock(ctxLocation, true)

	case "if":
		if ctx == ctxIf {
			return fmt.Errorf("line %d: nested 'if' blocks are not allowed", line)
		}
		if err := validateIfArgs(line, args); err != nil {
			return err
		}
		return p.parseBlock(ctxIf, true)

	default:
		return fmt.Errorf("line %d: block directive '%s' is not allowed", line, directive)
	}
}

func validateLocationArgs(line int, args []nginxToken) error {
	if len(args) == 1 {
		if args[0].kind != tokWord || strings.TrimSpace(args[0].val) == "" {
			return fmt.Errorf("line %d: invalid location pattern", line)
		}
		return validateSafeValue(line, args[0].val)
	}
	if len(args) == 2 {
		mod := args[0].val
		if mod != "=" && mod != "~" && mod != "~*" && mod != "^~" {
			return fmt.Errorf("line %d: invalid location modifier '%s'", line, mod)
		}
		if args[1].kind != tokWord || strings.TrimSpace(args[1].val) == "" {
			return fmt.Errorf("line %d: invalid location pattern", line)
		}
		if mod == "~" || mod == "~*" {
			if _, err := regexp.Compile(args[1].val); err != nil {
				return fmt.Errorf("line %d: invalid location regex '%s': %v", line, args[1].val, err)
			}
		}
		return validateSafeValue(line, args[1].val)
	}
	return fmt.Errorf("line %d: invalid number of arguments for 'location'", line)
}

func validateIfArgs(line int, args []nginxToken) error {
	if len(args) < 3 || args[0].kind != tokLParen || args[len(args)-1].kind != tokRParen {
		return fmt.Errorf("line %d: 'if' condition must be enclosed in parentheses", line)
	}
	inner := args[1 : len(args)-1]
	if len(inner) != 3 {
		return fmt.Errorf("line %d: 'if' condition must have form ($var op value)", line)
	}
	if !allowedIfVars[inner[0].val] {
		return fmt.Errorf("line %d: 'if' variable '%s' is not allowed (allowed: $request_uri, $http_user_agent, $host)", line, inner[0].val)
	}
	if !allowedIfOps[inner[1].val] {
		return fmt.Errorf("line %d: 'if' operator '%s' is not allowed", line, inner[1].val)
	}
	return validateSafeValue(line, inner[2].val)
}

func (p *nginxParser) handleSimpleDirective(ctx parseContext, directive string, line int, args []nginxToken) error {
	if ctx == ctxIf && directive != "return" && directive != "rewrite" {
		return fmt.Errorf("line %d: only 'return' and 'rewrite' are allowed inside 'if' blocks", line)
	}

	for _, a := range args {
		if a.kind != tokWord {
			return fmt.Errorf("line %d: unexpected token '%s' in '%s'", line, a.val, directive)
		}
		if err := validateSafeValue(line, a.val); err != nil {
			return err
		}
	}

	switch directive {
	case "try_files":
		if len(args) < 2 {
			return fmt.Errorf("line %d: 'try_files' requires at least 2 arguments", line)
		}
		return nil

	case "rewrite":
		if len(args) < 2 || len(args) > 3 {
			return fmt.Errorf("line %d: 'rewrite' requires 2 or 3 arguments", line)
		}
		if len(args) == 3 {
			flag := args[2].val
			if flag != "last" && flag != "break" && flag != "redirect" && flag != "permanent" {
				return fmt.Errorf("line %d: invalid rewrite flag '%s'", line, flag)
			}
		}
		return nil

	case "return":
		if len(args) != 2 {
			return fmt.Errorf("line %d: 'return' requires status code and target URL", line)
		}
		code := args[0].val
		if code != "301" && code != "302" && code != "307" && code != "308" {
			return fmt.Errorf("line %d: 'return' only supports status 301, 302, 307, or 308", line)
		}
		target := args[1].val
		if !strings.HasPrefix(target, "http://") && !strings.HasPrefix(target, "https://") {
			return fmt.Errorf("line %d: 'return' target must be an http:// or https:// URL", line)
		}
		return nil

	case "error_page":
		if len(args) < 2 {
			return fmt.Errorf("line %d: 'error_page' requires status code(s) and target", line)
		}
		for _, codeTok := range args[:len(args)-1] {
			if !statusCodeRegex.MatchString(codeTok.val) {
				return fmt.Errorf("line %d: invalid error_page status code '%s'", line, codeTok.val)
			}
		}
		target := args[len(args)-1].val
		if strings.HasPrefix(target, "/home/") {
			return validatePathInsideUserHome(line, p.username, target)
		}
		if !strings.HasPrefix(target, "/") || strings.Contains(target, "..") {
			return fmt.Errorf("line %d: invalid error_page target '%s'", line, target)
		}
		return nil

	case "index":
		if len(args) < 1 {
			return fmt.Errorf("line %d: 'index' requires at least 1 filename", line)
		}
		for _, a := range args {
			if !allowedIndexFiles[a.val] && !simpleFilenameRegex.MatchString(a.val) {
				return fmt.Errorf("line %d: invalid index filename '%s'", line, a.val)
			}
			if strings.Contains(a.val, "/") || strings.Contains(a.val, "..") {
				return fmt.Errorf("line %d: index filename '%s' cannot contain paths", line, a.val)
			}
		}
		return nil

	case "expires":
		if len(args) < 1 || len(args) > 2 {
			return fmt.Errorf("line %d: invalid 'expires' arguments", line)
		}
		return nil

	case "add_header":
		if len(args) < 2 || len(args) > 3 {
			return fmt.Errorf("line %d: 'add_header' requires 2 or 3 arguments", line)
		}
		if !allowedHeaders[strings.ToLower(args[0].val)] {
			return fmt.Errorf("line %d: header '%s' is not on the allow-list", line, args[0].val)
		}
		if len(args) == 3 && args[2].val != "always" {
			return fmt.Errorf("line %d: optional 3rd argument to 'add_header' must be 'always'", line)
		}
		return nil

	case "deny", "allow":
		if len(args) != 1 {
			return fmt.Errorf("line %d: '%s' requires exactly 1 argument", line, directive)
		}
		return nil

	case "autoindex":
		if len(args) != 1 || (args[0].val != "on" && args[0].val != "off") {
			return fmt.Errorf("line %d: 'autoindex' must be 'on' or 'off'", line)
		}
		return nil

	case "gzip":
		if len(args) != 1 || (args[0].val != "on" && args[0].val != "off") {
			return fmt.Errorf("line %d: 'gzip' must be 'on' or 'off'", line)
		}
		return nil

	case "gzip_types", "gzip_min_length", "gzip_comp_level", "gzip_vary", "gzip_proxied":
		if len(args) < 1 {
			return fmt.Errorf("line %d: '%s' requires at least 1 argument", line, directive)
		}
		return nil

	case "client_max_body_size":
		if len(args) != 1 {
			return fmt.Errorf("line %d: 'client_max_body_size' requires 1 argument", line)
		}
		bytesVal, err := parseSizeBytes(args[0].val)
		if err != nil {
			return fmt.Errorf("line %d: %w", line, err)
		}
		maxBytes := int64(p.maxBodyMB) * 1024 * 1024
		if bytesVal > maxBytes {
			return fmt.Errorf("line %d: client_max_body_size exceeds package limit of %dM", line, p.maxBodyMB)
		}
		return nil

	case "fastcgi_read_timeout":
		if len(args) != 1 {
			return fmt.Errorf("line %d: 'fastcgi_read_timeout' requires 1 argument", line)
		}
		m := timeoutLiteralRegex.FindStringSubmatch(args[0].val)
		if m == nil {
			return fmt.Errorf("line %d: invalid fastcgi_read_timeout '%s'", line, args[0].val)
		}
		sec, err := strconv.Atoi(m[1])
		if err != nil || sec < 1 || sec > MaxFastcgiTimeout {
			return fmt.Errorf("line %d: fastcgi_read_timeout must be between 1 and %ds", line, MaxFastcgiTimeout)
		}
		return nil

	case "auth_basic":
		if len(args) != 1 {
			return fmt.Errorf("line %d: 'auth_basic' requires 1 argument", line)
		}
		return nil

	case "auth_basic_user_file":
		if len(args) != 1 {
			return fmt.Errorf("line %d: 'auth_basic_user_file' requires 1 path argument", line)
		}
		return validatePathInsideUserHome(line, p.username, args[0].val)

	case "limit_req", "limit_conn":
		if len(args) < 1 {
			return fmt.Errorf("line %d: '%s' requires zone argument", line, directive)
		}
		zoneArg := args[0].val
		zoneName := zoneArg
		if strings.HasPrefix(zoneArg, "zone=") {
			zoneName = strings.TrimPrefix(zoneArg, "zone=")
		} else if directive == "limit_req" {
			return fmt.Errorf("line %d: 'limit_req' first argument must be zone=<name>", line)
		}
		if !allowedPanelZones[zoneName] && !strings.HasPrefix(zoneName, "hpanel_") {
			return fmt.Errorf("line %d: zone '%s' is not a panel-defined rate limit zone", line, zoneName)
		}
		return nil

	case "proxy_pass":
		if len(args) != 1 {
			return fmt.Errorf("line %d: 'proxy_pass' requires 1 URL argument", line)
		}
		u, err := url.Parse(args[0].val)
		if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Hostname() != "127.0.0.1" {
			return fmt.Errorf("line %d: 'proxy_pass' is only permitted to 127.0.0.1", line)
		}
		return nil

	default:
		return fmt.Errorf("line %d: unknown or disallowed directive '%s'", line, directive)
	}
}

func validateSafeValue(line int, val string) error {
	if strings.ContainsAny(val, "\r\n`;") {
		return fmt.Errorf("line %d: forbidden character in value '%s'", line, val)
	}
	if strings.Contains(val, "${") {
		return fmt.Errorf("line %d: forbidden '${' interpolation in value", line)
	}
	return nil
}

func parseSizeBytes(val string) (int64, error) {
	m := sizeLiteralRegex.FindStringSubmatch(val)
	if m == nil {
		return 0, fmt.Errorf("invalid size literal '%s'", val)
	}
	num, err := strconv.ParseInt(m[1], 10, 64)
	if err != nil || num <= 0 {
		return 0, fmt.Errorf("invalid size literal '%s'", val)
	}
	switch strings.ToLower(m[2]) {
	case "k":
		return num * 1024, nil
	case "m":
		return num * 1024 * 1024, nil
	case "g":
		return num * 1024 * 1024 * 1024, nil
	default:
		return num, nil
	}
}

func validatePathInsideUserHome(line int, username string, rawPath string) error {
	if username == "" {
		return fmt.Errorf("line %d: account username is required to validate path '%s'", line, rawPath)
	}
	if strings.Contains(rawPath, "..") || !filepath.IsAbs(rawPath) {
		return fmt.Errorf("line %d: path '%s' must be an absolute path without '..'", line, rawPath)
	}

	homeDir := filepath.Clean(filepath.Join("/home", username))
	cleanTarget := filepath.Clean(rawPath)

	rel, err := filepath.Rel(homeDir, cleanTarget)
	if err != nil || rel == "." || strings.HasPrefix(rel, "..") {
		return fmt.Errorf("line %d: path '%s' must be inside %s", line, rawPath, homeDir)
	}

	// Resolve symlinks if target or its parent exists on disk
	if _, err := os.Lstat(cleanTarget); err == nil {
		resolved, err := filepath.EvalSymlinks(cleanTarget)
		if err != nil {
			return fmt.Errorf("line %d: cannot resolve path '%s': %v", line, rawPath, err)
		}
		resolvedHome := homeDir
		if rh, err := filepath.EvalSymlinks(homeDir); err == nil {
			resolvedHome = rh
		}
		relResolved, err := filepath.Rel(resolvedHome, resolved)
		if err != nil || relResolved == "." || strings.HasPrefix(relResolved, "..") {
			return fmt.Errorf("line %d: symlink '%s' escapes %s", line, rawPath, homeDir)
		}
	} else {
		parent := filepath.Dir(cleanTarget)
		if _, err := os.Lstat(parent); err == nil {
			resolvedParent, err := filepath.EvalSymlinks(parent)
			if err != nil {
				return fmt.Errorf("line %d: cannot resolve parent of '%s': %v", line, rawPath, err)
			}
			resolvedHome := homeDir
			if rh, err := filepath.EvalSymlinks(homeDir); err == nil {
				resolvedHome = rh
			}
			relResolved, err := filepath.Rel(resolvedHome, resolvedParent)
			if err != nil || strings.HasPrefix(relResolved, "..") {
				return fmt.Errorf("line %d: parent symlink of '%s' escapes %s", line, rawPath, homeDir)
			}
		}
	}

	return nil
}
