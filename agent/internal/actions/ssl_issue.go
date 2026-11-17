package actions

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"syscall"
	"time"
)

type SslIssuePayload struct {
	Domain      string   `json:"domain"`
	Email       string   `json:"email"`
	AltNames    []string `json:"alt_names,omitempty"`
	AgreeTOS    bool     `json:"agree_tos,omitempty"`
	Username    string   `json:"username,omitempty"`
	AppProfile  string   `json:"app_profile,omitempty"`
	PHPVersion  string   `json:"php_version,omitempty"`
	ForceHTTPS  bool     `json:"force_https,omitempty"`
	WwwRedirect string   `json:"www_redirect,omitempty"`
}

type HttpGetter interface {
	Get(urlStr, hostHeader string) (statusCode int, body string, err error)
}

type DnsLookup interface {
	LookupIP(host string) ([]net.IP, error)
}

type DefaultHttpGetter struct{}

func (g *DefaultHttpGetter) Get(urlStr, hostHeader string) (int, string, error) {
	req, err := http.NewRequest("GET", urlStr, nil)
	if err != nil {
		return 0, "", err
	}
	if hostHeader != "" {
		req.Host = hostHeader
	}
	client := &http.Client{
		Timeout: 5 * time.Second,
		CheckRedirect: func(req *http.Request, via []*http.Request) error {
			return http.ErrUseLastResponse
		},
	}
	resp, err := client.Do(req)
	if err != nil {
		return 0, "", err
	}
	defer resp.Body.Close()
	bodyBytes, _ := io.ReadAll(io.LimitReader(resp.Body, 4096))
	return resp.StatusCode, string(bodyBytes), nil
}

type DefaultDnsLookup struct{}

func (d *DefaultDnsLookup) LookupIP(host string) ([]net.IP, error) {
	return net.LookupIP(host)
}

type SslIssueAction struct {
	httpGetter        HttpGetter
	dnsLookup         DnsLookup
	vhostAvailableDir string
	vhostEnabledDir   string
	acmeBaseDir       string
}

func NewSslIssueAction() *SslIssueAction {
	return &SslIssueAction{
		httpGetter:        &DefaultHttpGetter{},
		dnsLookup:         &DefaultDnsLookup{},
		vhostAvailableDir: "/etc/nginx/sites-available",
		vhostEnabledDir:   "/etc/nginx/sites-enabled",
		acmeBaseDir:       "/var/www/_acme",
	}
}

func NewSslIssueActionForTest(httpGetter HttpGetter, dnsLookup DnsLookup, availDir, enabledDir, acmeDir string) *SslIssueAction {
	return &SslIssueAction{
		httpGetter:        httpGetter,
		dnsLookup:         dnsLookup,
		vhostAvailableDir: availDir,
		vhostEnabledDir:   enabledDir,
		acmeBaseDir:       acmeDir,
	}
}

func (a *SslIssueAction) Name() string {
	return "ssl.issue"
}

func (a *SslIssueAction) Validate(payload []byte) error {
	var p SslIssuePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}

	if err := ValidateDomain(p.Domain); err != nil {
		return err
	}
	for _, alt := range p.AltNames {
		if err := ValidateDomain(alt); err != nil {
			return fmt.Errorf("invalid alt_name '%s': %w", alt, err)
		}
	}
	if err := ValidateEmail(p.Email); err != nil {
		return err
	}

	return nil
}

type CertbotErrorInfo struct {
	Domain       string `json:"domain"`
	Type         string `json:"type"`
	Detail       string `json:"detail"`
	ShortMessage string `json:"short_message"`
}

// ParseCertbotOutput extracts structured error information from Certbot output
// and maps it to a user-friendly message of up to 500 characters.
func ParseCertbotOutput(output string) CertbotErrorInfo {
	info := CertbotErrorInfo{
		ShortMessage: "Certificate issuance failed",
	}

	reDomain := regexp.MustCompile(`(?i)Domain:\s*([^\s\n\r]+)`)
	if m := reDomain.FindStringSubmatch(output); len(m) > 1 {
		info.Domain = strings.TrimSpace(m[1])
	}

	reType := regexp.MustCompile(`(?i)Type:\s*([^\s\n\r]+)`)
	if m := reType.FindStringSubmatch(output); len(m) > 1 {
		info.Type = strings.TrimSpace(m[1])
	}

	reDetail := regexp.MustCompile(`(?i)Detail:\s*([^\r\n]+)`)
	if m := reDetail.FindStringSubmatch(output); len(m) > 1 {
		info.Detail = strings.TrimSpace(m[1])
	}

	lowerOut := strings.ToLower(output)

	switch {
	case strings.Contains(lowerOut, "404") || (strings.Contains(lowerOut, "unauthorized") && strings.Contains(lowerOut, "invalid response")):
		info.ShortMessage = "Challenge file not reachable on port 80"
	case strings.Contains(lowerOut, "403") || strings.Contains(lowerOut, "forbidden"):
		info.ShortMessage = "Permission problem accessing challenge file"
	case strings.Contains(lowerOut, "timed out") || strings.Contains(lowerOut, "connection refused") || strings.Contains(lowerOut, "failed to connect") || strings.Contains(lowerOut, "refused"):
		info.ShortMessage = "Port 80 not reachable"
	case strings.Contains(lowerOut, "too many") || strings.Contains(lowerOut, "rate limit") || strings.Contains(lowerOut, "ratelimited"):
		reRetry := regexp.MustCompile(`(?i)(retry after [^\r\n\.]+)`)
		if m := reRetry.FindStringSubmatch(output); len(m) > 1 {
			info.ShortMessage = fmt.Sprintf("Let's Encrypt rate limit reached (%s)", strings.TrimSpace(m[1]))
		} else {
			info.ShortMessage = "Let's Encrypt rate limit reached; please retry later"
		}
	case strings.Contains(lowerOut, "caa") || strings.Contains(lowerOut, "certification authority authorization"):
		info.ShortMessage = "Certification Authority Authorization (CAA) records prevent issuance"
	default:
		if info.Detail != "" {
			info.ShortMessage = info.Detail
		} else {
			lines := strings.Split(output, "\n")
			for _, line := range lines {
				trimmed := strings.TrimSpace(line)
				if strings.Contains(trimmed, "Error:") || strings.Contains(trimmed, "error:") {
					info.ShortMessage = trimmed
					break
				}
			}
		}
	}

	if len(info.ShortMessage) > 500 {
		info.ShortMessage = info.ShortMessage[:497] + "..."
	}

	return info
}

func (a *SslIssueAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p SslIssuePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	certPath := fmt.Sprintf("/etc/letsencrypt/live/%s/fullchain.pem", p.Domain)
	keyPath := fmt.Sprintf("/etc/letsencrypt/live/%s/privkey.pem", p.Domain)

	logs := []string{
		fmt.Sprintf("requesting Let's Encrypt SSL certificate for %s (email: %s)", p.Domain, p.Email),
	}

	if dryRun {
		now := time.Now()
		expiry := now.Add(90 * 24 * time.Hour)
		logs = append(logs,
			fmt.Sprintf("[dry-run] simulated certbot webroot verification for %s", p.Domain),
			fmt.Sprintf("[dry-run] certificate issued: %s", certPath),
		)
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"domain":         p.Domain,
				"cert_path":      certPath,
				"key_path":       keyPath,
				"issuer":         "Let's Encrypt Authority X3",
				"valid_from":     now.Format(time.RFC3339),
				"valid_to":       expiry.Format(time.RFC3339),
				"days_remaining": 90,
				"type":           "lets_encrypt",
				"dry_run":        true,
			},
			Logs: logs,
		}, nil
	}

	// 1. Preflight a: Ensure vhost exists, is enabled and nginx -t passes
	vhostAvail := filepath.Join(a.vhostAvailableDir, fmt.Sprintf("%s.conf", p.Domain))
	if _, err := os.Stat(vhostAvail); os.IsNotExist(err) {
		errStr := fmt.Sprintf("vhost configuration does not exist for %s", p.Domain)
		return &ActionResult{Success: false, Error: errStr, Logs: logs}, fmt.Errorf(errStr)
	}

	if _, err := exec.LookPath("nginx"); err == nil {
		cmdTest := exec.CommandContext(ctx, "nginx", "-t")
		if out, err := cmdTest.CombinedOutput(); err != nil {
			errStr := fmt.Sprintf("nginx -t preflight failed: %s", strings.TrimSpace(string(out)))
			return &ActionResult{Success: false, Error: errStr, Logs: logs}, fmt.Errorf(errStr)
		}
	}

	// Ensure /var/www/_acme/.well-known/acme-challenge exists with 0755
	challengeDir := filepath.Join(a.acmeBaseDir, ".well-known", "acme-challenge")
	if err := os.MkdirAll(challengeDir, 0755); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed to create acme challenge directory: %v", err),
			Logs:    logs,
		}, err
	}
	_ = os.Chmod(challengeDir, 0755)

	// 2. Preflight b: Write a random probe file in /var/www/_acme/.well-known/acme-challenge/
	// and GET it with Host: <domain> on 127.0.0.1:80 (must return 200 with probe body)
	randomBytes := make([]byte, 16)
	_, _ = rand.Read(randomBytes)
	probeToken := hex.EncodeToString(randomBytes)
	probeFile := filepath.Join(challengeDir, probeToken)

	if err := os.WriteFile(probeFile, []byte(probeToken), 0644); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed to write probe file: %v", err),
			Logs:    logs,
		}, err
	}
	defer os.Remove(probeFile)

	// Check on 127.0.0.1:80
	localUrl := fmt.Sprintf("http://127.0.0.1/.well-known/acme-challenge/%s", probeToken)
	status, body, err := a.httpGetter.Get(localUrl, p.Domain)
	if err != nil || status != 200 || strings.TrimSpace(body) != probeToken {
		errStr := fmt.Sprintf("local ACME challenge preflight probe failed on 127.0.0.1:80 (status=%d, err=%v)", status, err)
		logs = append(logs, errStr)
		return &ActionResult{
			Success: false,
			Error:   errStr,
			Logs:    logs,
		}, fmt.Errorf(errStr)
	}

	// 3. Preflight c: GET probe through public name http://<domain>/.well-known/acme-challenge/<probeToken>
	// If not 200, return DNS_NOT_POINTING with resolved IPs
	publicUrl := fmt.Sprintf("http://%s/.well-known/acme-challenge/%s", p.Domain, probeToken)
	pubStatus, pubBody, pubErr := a.httpGetter.Get(publicUrl, "")
	if pubErr != nil || pubStatus != 200 || strings.TrimSpace(pubBody) != probeToken {
		resolvedIPs := []string{}
		if ips, err := a.dnsLookup.LookupIP(p.Domain); err == nil {
			for _, ip := range ips {
				resolvedIPs = append(resolvedIPs, ip.String())
			}
		}
		errStr := fmt.Sprintf("DNS_NOT_POINTING: domain %s did not return ACME probe on port 80 (status=%d, resolved IPs: %v)", p.Domain, pubStatus, resolvedIPs)
		logs = append(logs, errStr)
		return &ActionResult{
			Success: false,
			Error:   errStr,
			Data: map[string]any{
				"error_code":   "DNS_NOT_POINTING",
				"domain":       p.Domain,
				"resolved_ips": resolvedIPs,
			},
			Logs: logs,
		}, fmt.Errorf(errStr)
	}

	// 4. Preflight d: Request only names that pass c. Include www.<domain> only when it also resolves and passes probe.
	namesToRequest := []string{p.Domain}
	candidates := p.AltNames
	wwwDomain := "www." + strings.TrimPrefix(p.Domain, "www.")
	hasWwwCandidate := false
	for _, c := range candidates {
		if c == wwwDomain {
			hasWwwCandidate = true
			break
		}
	}
	if !hasWwwCandidate && !strings.HasPrefix(p.Domain, "www.") {
		candidates = append(candidates, wwwDomain)
	}

	for _, alt := range candidates {
		if alt == p.Domain {
			continue
		}
		altUrl := fmt.Sprintf("http://%s/.well-known/acme-challenge/%s", alt, probeToken)
		altStatus, altBody, altErr := a.httpGetter.Get(altUrl, "")
		if altErr == nil && altStatus == 200 && strings.TrimSpace(altBody) == probeToken {
			namesToRequest = append(namesToRequest, alt)
			logs = append(logs, fmt.Sprintf("alt name %s passed preflight probe and is included", alt))
		} else {
			logs = append(logs, fmt.Sprintf("alt name %s failed preflight probe (status=%d), excluded from cert", alt, altStatus))
		}
	}

	// 5. Run certbot serialized with flock
	lockPath := "/var/lock/hpanel-certbot.lock"
	_ = os.MkdirAll(filepath.Dir(lockPath), 0755)
	lockFile, err := os.OpenFile(lockPath, os.O_CREATE|os.O_RDWR, 0600)
	if err == nil {
		defer lockFile.Close()
		_ = syscall.Flock(int(lockFile.Fd()), syscall.LOCK_EX)
		defer syscall.Flock(int(lockFile.Fd()), syscall.LOCK_UN)
	}

	args := []string{
		"certonly",
		"--webroot",
		"-w", a.acmeBaseDir,
		"--cert-name", p.Domain,
		"--non-interactive",
		"--agree-tos",
		"-m", p.Email,
		"--keep-until-expiring",
	}
	for _, name := range namesToRequest {
		args = append(args, "-d", name)
	}

	var cmd *exec.Cmd
	if _, err := exec.LookPath("flock"); err == nil {
		flockArgs := append([]string{"-x", lockPath, "certbot"}, args...)
		cmd = exec.CommandContext(ctx, "flock", flockArgs...)
	} else {
		cmd = exec.CommandContext(ctx, "certbot", args...)
	}

	output, err := cmd.CombinedOutput()
	logs = append(logs, string(output))

	if err != nil {
		parsedErr := ParseCertbotOutput(string(output))
		parsedErr.Domain = p.Domain
		return &ActionResult{
			Success: false,
			Error:   parsedErr.ShortMessage,
			Data: map[string]any{
				"ssl_last_error": parsedErr.ShortMessage,
				"certbot_error":  parsedErr,
				"full_output":    string(output),
			},
			Logs: logs,
		}, fmt.Errorf("certbot failed: %s", parsedErr.ShortMessage)
	}

	// 6. Read and parse issued certificate
	certBytes, err := os.ReadFile(certPath)
	if err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed to read issued certificate at %s: %v", certPath, err),
			Logs:    logs,
		}, err
	}

	parsed, err := ParseCertificateBytes(certBytes)
	if err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed to parse issued certificate: %v", err),
			Logs:    logs,
		}, err
	}

	// 7. Render HTTPS server block from template, test with nginx -t, reload, and rollback on failure
	existingBytes, readErr := os.ReadFile(vhostAvail)
	if readErr == nil {
		existingCustom := ""
		if extracted, ok := ExtractCustomBlock(string(existingBytes)); ok {
			existingCustom = extracted
		}

		vhostData := VhostTemplateData{
			Username:     p.Username,
			Domain:       p.Domain,
			DocumentRoot: fmt.Sprintf("/home/%s/public_html", p.Username),
			AppProfile:   p.AppProfile,
			PHPVersion:   p.PHPVersion,
			ForceHTTPS:   p.ForceHTTPS,
			SSLEnabled:   true,
			SSLCertPath:  certPath,
			SSLKeyPath:   keyPath,
			WwwRedirect:  p.WwwRedirect,
			CustomBlock:  existingCustom,
		}

		newConfig, renderErr := RenderVhostConfig(vhostData)
		if renderErr == nil {
			backupVhost := vhostAvail + ".bak"
			_ = copyFile(vhostAvail, backupVhost)

			if commitErr := os.WriteFile(vhostAvail, []byte(newConfig), 0640); commitErr == nil {
				testPassed := true
				if _, err := exec.LookPath("nginx"); err == nil {
					cmdTest := exec.CommandContext(ctx, "nginx", "-t")
					if _, err := cmdTest.CombinedOutput(); err != nil {
						testPassed = false
					}
				}

				if testPassed {
					if _, err := exec.LookPath("systemctl"); err == nil {
						_ = exec.CommandContext(ctx, "systemctl", "reload", "nginx").Run()
					}
					_ = os.Remove(backupVhost)
					logs = append(logs, "Nginx HTTPS configuration rendered and reloaded successfully")
				} else {
					// Rollback
					_ = copyFile(backupVhost, vhostAvail)
					_ = os.Remove(backupVhost)
					logs = append(logs, "Nginx syntax test failed after HTTPS configuration, rolled back to HTTP backup")
				}
			}
		}
	}

	logs = append(logs, fmt.Sprintf("certificate successfully issued, expires on %s (%d days remaining)", parsed.ValidTo.Format("2006-01-02"), parsed.DaysRemaining))

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"domain":         p.Domain,
			"cert_path":      certPath,
			"key_path":       keyPath,
			"issuer":         parsed.Issuer,
			"valid_from":     parsed.ValidFrom.Format(time.RFC3339),
			"valid_to":       parsed.ValidTo.Format(time.RFC3339),
			"ssl_expires_at": parsed.ValidTo.Format(time.RFC3339),
			"days_remaining": parsed.DaysRemaining,
			"type":           "lets_encrypt",
		},
		Logs: logs,
	}, nil
}
