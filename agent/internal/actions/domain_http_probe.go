package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
)

var tokenRegex = regexp.MustCompile(`^[a-zA-Z0-9_\-]{16,64}$`)

type DomainHttpProbePayload struct {
	Domain string `json:"domain"`
	Token  string `json:"token"`
}

type DomainHttpProbeAction struct {
	acmeBaseDir string
}

func NewDomainHttpProbeAction() *DomainHttpProbeAction {
	return &DomainHttpProbeAction{
		acmeBaseDir: "/var/www/_acme",
	}
}

func NewDomainHttpProbeActionWithDir(baseDir string) *DomainHttpProbeAction {
	return &DomainHttpProbeAction{
		acmeBaseDir: baseDir,
	}
}

func (a *DomainHttpProbeAction) Name() string {
	return "domain.http_probe"
}

func (a *DomainHttpProbeAction) Validate(payload []byte) error {
	var p DomainHttpProbePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}

	if err := ValidateDomain(p.Domain); err != nil {
		return fmt.Errorf("invalid domain: %w", err)
	}

	if !tokenRegex.MatchString(p.Token) {
		return fmt.Errorf("invalid verification token: must be 16-64 alphanumeric characters")
	}

	return nil
}

func (a *DomainHttpProbeAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p DomainHttpProbePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	verifyDir := filepath.Join(a.acmeBaseDir, ".well-known", "hpanel-verify")
	targetFile := filepath.Join(verifyDir, p.Token)

	logs := []string{
		fmt.Sprintf("preparing domain http verification probe for %s", p.Domain),
	}

	if dryRun {
		logs = append(logs, fmt.Sprintf("[dry-run] would write probe file %s", targetFile))
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"domain":  p.Domain,
				"token":   p.Token,
				"path":    targetFile,
				"dry_run": true,
			},
			Logs: logs,
		}, nil
	}

	if err := os.MkdirAll(verifyDir, 0755); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed to create verification directory: %v", err),
			Logs:    logs,
		}, err
	}
	_ = os.Chmod(verifyDir, 0755)

	// Write token directly with 0644 mode
	if err := os.WriteFile(targetFile, []byte(p.Token), 0644); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed to write probe file: %v", err),
			Logs:    logs,
		}, err
	}

	logs = append(logs, fmt.Sprintf("wrote verification probe to %s", targetFile))

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"domain": p.Domain,
			"token":  p.Token,
			"path":   targetFile,
		},
		Logs: logs,
	}, nil
}
