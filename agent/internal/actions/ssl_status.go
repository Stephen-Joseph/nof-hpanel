package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"time"
)

type SslStatusPayload struct {
	Domain   string `json:"domain"`
	CertPath string `json:"cert_path,omitempty"`
}

type SslStatusAction struct{}

func NewSslStatusAction() *SslStatusAction {
	return &SslStatusAction{}
}

func (a *SslStatusAction) Name() string {
	return "ssl.status"
}

func (a *SslStatusAction) Validate(payload []byte) error {
	var p SslStatusPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	return ValidateDomain(p.Domain)
}

func (a *SslStatusAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p SslStatusPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	certPath := p.CertPath
	if certPath == "" {
		// Try Let's Encrypt first, then custom path
		lePath := fmt.Sprintf("/etc/letsencrypt/live/%s/fullchain.pem", p.Domain)
		customPath := fmt.Sprintf("/etc/ssl/panel/%s/certificate.crt", p.Domain)

		if _, err := os.Stat(lePath); err == nil {
			certPath = lePath
		} else if _, err := os.Stat(customPath); err == nil {
			certPath = customPath
		} else {
			certPath = lePath
		}
	}

	logs := []string{
		fmt.Sprintf("checking SSL certificate status for '%s' (path: %s)", p.Domain, certPath),
	}

	if dryRun {
		now := time.Now()
		expiry := now.Add(60 * 24 * time.Hour)
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"domain":         p.Domain,
				"cert_path":      certPath,
				"has_cert":       true,
				"issuer":         "Let's Encrypt Authority X3",
				"valid_from":     now.Format(time.RFC3339),
				"valid_to":       expiry.Format(time.RFC3339),
				"days_remaining": 60,
				"is_expired":     false,
				"dry_run":        true,
			},
			Logs: logs,
		}, nil
	}

	certBytes, err := os.ReadFile(certPath)
	if err != nil {
		if os.IsNotExist(err) {
			return &ActionResult{
				Success: true,
				Data: map[string]any{
					"domain":   p.Domain,
					"has_cert": false,
					"message":  "No certificate found on disk for this domain",
				},
				Logs: logs,
			}, nil
		}
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed reading certificate: %s", err),
			Logs:    logs,
		}, err
	}

	parsed, err := ParseCertificateBytes(certBytes)
	if err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed parsing certificate: %s", err),
			Logs:    logs,
		}, err
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"domain":         p.Domain,
			"cert_path":      certPath,
			"has_cert":       true,
			"issuer":         parsed.Issuer,
			"subject":        parsed.Subject,
			"sans":           parsed.SANs,
			"valid_from":     parsed.ValidFrom.Format(time.RFC3339),
			"valid_to":       parsed.ValidTo.Format(time.RFC3339),
			"days_remaining": parsed.DaysRemaining,
			"is_expired":     parsed.IsExpired,
		},
		Logs: logs,
	}, nil
}
