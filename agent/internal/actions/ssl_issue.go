package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"time"
)

type SslIssuePayload struct {
	Domain   string   `json:"domain"`
	Email    string   `json:"email"`
	AltNames []string `json:"alt_names,omitempty"`
	AgreeTOS bool     `json:"agree_tos,omitempty"`
}

type SslIssueAction struct{}

func NewSslIssueAction() *SslIssueAction {
	return &SslIssueAction{}
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

	// Ensure webroot directory exists
	webrootDir := "/var/www/letsencrypt"
	if err := os.MkdirAll(filepath.Join(webrootDir, ".well-known", "acme-challenge"), 0755); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed to create acme challenge directory: %s", err),
			Logs:    logs,
		}, err
	}

	// Construct certbot command strictly with argument array
	args := []string{
		"certonly",
		"--webroot",
		"-w", webrootDir,
		"--non-interactive",
		"--agree-tos",
		"-m", p.Email,
		"-d", p.Domain,
	}

	for _, alt := range p.AltNames {
		args = append(args, "-d", alt)
	}

	args = append(args, "--keep-until-expiring")

	cmd := exec.CommandContext(ctx, "certbot", args...)
	output, err := cmd.CombinedOutput()
	logs = append(logs, string(output))

	if err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("certbot failed: %s (%s)", err, string(output)),
			Logs:    logs,
		}, err
	}

	// Read and parse the generated certificate
	certBytes, err := os.ReadFile(certPath)
	if err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed to read issued certificate at %s: %s", certPath, err),
			Logs:    logs,
		}, err
	}

	parsed, err := ParseCertificateBytes(certBytes)
	if err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed to parse issued certificate: %s", err),
			Logs:    logs,
		}, err
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
			"days_remaining": parsed.DaysRemaining,
			"type":           "lets_encrypt",
		},
		Logs: logs,
	}, nil
}

