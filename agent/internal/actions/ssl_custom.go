package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"
)

type SslCustomPayload struct {
	Domain      string `json:"domain"`
	Certificate string `json:"certificate"`
	PrivateKey  string `json:"private_key"`
	Chain       string `json:"chain,omitempty"`
}

type SslCustomAction struct{}

func NewSslCustomAction() *SslCustomAction {
	return &SslCustomAction{}
}

func (a *SslCustomAction) Name() string {
	return "ssl.install_custom"
}

func (a *SslCustomAction) Validate(payload []byte) error {
	var p SslCustomPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}

	if err := ValidateDomain(p.Domain); err != nil {
		return err
	}

	// Validate cryptographic match and x509 structure
	_, err := ValidateKeyPair(p.Certificate, p.PrivateKey, p.Chain)
	if err != nil {
		return err
	}

	return nil
}

func (a *SslCustomAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p SslCustomPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	parsed, err := ValidateKeyPair(p.Certificate, p.PrivateKey, p.Chain)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	certDir := filepath.Join("/etc/ssl/panel", p.Domain)
	certPath := filepath.Join(certDir, "certificate.crt")
	keyPath := filepath.Join(certDir, "private.key")

	logs := []string{
		fmt.Sprintf("installing custom SSL certificate for '%s' (issuer: %s)", p.Domain, parsed.Issuer),
	}

	if dryRun {
		logs = append(logs,
			fmt.Sprintf("[dry-run] simulated keypair verification for %s: valid", p.Domain),
			fmt.Sprintf("[dry-run] simulated write to %s and %s", certPath, keyPath),
		)
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"domain":         p.Domain,
				"cert_path":      certPath,
				"key_path":       keyPath,
				"issuer":         parsed.Issuer,
				"subject":        parsed.Subject,
				"sans":           parsed.SANs,
				"valid_from":     parsed.ValidFrom.Format(time.RFC3339),
				"valid_to":       parsed.ValidTo.Format(time.RFC3339),
				"days_remaining": parsed.DaysRemaining,
				"type":           "custom",
				"dry_run":        true,
			},
			Logs: logs,
		}, nil
	}

	if err := os.MkdirAll(certDir, 0750); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed creating certificate directory %s: %s", certDir, err),
			Logs:    logs,
		}, err
	}

	fullCert := strings.TrimSpace(p.Certificate)
	if strings.TrimSpace(p.Chain) != "" {
		fullCert = fullCert + "\n" + strings.TrimSpace(p.Chain)
	}

	if err := os.WriteFile(certPath, []byte(fullCert), 0644); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed writing certificate: %s", err),
			Logs:    logs,
		}, err
	}

	// Restrict private key permissions strictly to 0600
	if err := os.WriteFile(keyPath, []byte(strings.TrimSpace(p.PrivateKey)+"\n"), 0600); err != nil {
		_ = os.Remove(certPath)
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed writing private key: %s", err),
			Logs:    logs,
		}, err
	}

	logs = append(logs, fmt.Sprintf("custom certificate and private key stored at %s", certDir))

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"domain":         p.Domain,
			"cert_path":      certPath,
			"key_path":       keyPath,
			"issuer":         parsed.Issuer,
			"subject":        parsed.Subject,
			"sans":           parsed.SANs,
			"valid_from":     parsed.ValidFrom.Format(time.RFC3339),
			"valid_to":       parsed.ValidTo.Format(time.RFC3339),
			"days_remaining": parsed.DaysRemaining,
			"type":           "custom",
		},
		Logs: logs,
	}, nil
}

