package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"time"
)

type SslRenewPayload struct {
	Domain string `json:"domain"`
	Force  bool   `json:"force,omitempty"`
}

type SslRenewAction struct{}

func NewSslRenewAction() *SslRenewAction {
	return &SslRenewAction{}
}

func (a *SslRenewAction) Name() string {
	return "ssl.renew"
}

func (a *SslRenewAction) Validate(payload []byte) error {
	var p SslRenewPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	return ValidateDomain(p.Domain)
}

func (a *SslRenewAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p SslRenewPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	certPath := fmt.Sprintf("/etc/letsencrypt/live/%s/fullchain.pem", p.Domain)
	keyPath := fmt.Sprintf("/etc/letsencrypt/live/%s/privkey.pem", p.Domain)

	logs := []string{
		fmt.Sprintf("renewing SSL certificate for domain '%s' (force: %v)", p.Domain, p.Force),
	}

	if dryRun {
		now := time.Now()
		expiry := now.Add(90 * 24 * time.Hour)
		logs = append(logs,
			fmt.Sprintf("[dry-run] simulated certbot renewal for %s", p.Domain),
			fmt.Sprintf("[dry-run] certificate valid until %s", expiry.Format(time.RFC3339)),
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
				"dry_run":        true,
			},
			Logs: logs,
		}, nil
	}

	args := []string{"renew", "--cert-name", p.Domain}
	if p.Force {
		args = append(args, "--force-renewal")
	}

	cmd := exec.CommandContext(ctx, "certbot", args...)
	output, err := cmd.CombinedOutput()
	logs = append(logs, string(output))

	if err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("renewal failed: %s (%s)", err, string(output)),
			Logs:    logs,
		}, err
	}

	certBytes, err := os.ReadFile(certPath)
	if err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed to read renewed certificate: %s", err),
			Logs:    logs,
		}, err
	}

	parsed, err := ParseCertificateBytes(certBytes)
	if err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed to parse renewed certificate: %s", err),
			Logs:    logs,
		}, err
	}

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
		},
		Logs: logs,
	}, nil
}
