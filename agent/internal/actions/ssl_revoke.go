package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os/exec"
)

type SslRevokePayload struct {
	Domain string `json:"domain"`
	Reason string `json:"reason,omitempty"`
}

type SslRevokeAction struct{}

func NewSslRevokeAction() *SslRevokeAction {
	return &SslRevokeAction{}
}

func (a *SslRevokeAction) Name() string {
	return "ssl.revoke"
}

func (a *SslRevokeAction) Validate(payload []byte) error {
	var p SslRevokePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	return ValidateDomain(p.Domain)
}

func (a *SslRevokeAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p SslRevokePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{
		fmt.Sprintf("revoking SSL certificate for domain '%s'", p.Domain),
	}

	if dryRun {
		logs = append(logs, fmt.Sprintf("[dry-run] simulated certbot revoke for %s", p.Domain))
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"domain":  p.Domain,
				"revoked": true,
				"dry_run": true,
			},
			Logs: logs,
		}, nil
	}

	args := []string{"revoke", "--cert-name", p.Domain, "--delete-after-revoke", "--non-interactive"}
	if p.Reason != "" {
		args = append(args, "--reason", p.Reason)
	}

	cmd := exec.CommandContext(ctx, "certbot", args...)
	output, err := cmd.CombinedOutput()
	logs = append(logs, string(output))

	if err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("revocation failed: %s (%s)", err, string(output)),
			Logs:    logs,
		}, err
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"domain":  p.Domain,
			"revoked": true,
		},
		Logs: logs,
	}, nil
}

