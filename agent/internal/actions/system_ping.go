package actions

import (
	"context"
	"time"
)

type SystemPingAction struct {
	agentVersion string
}

func NewSystemPingAction(version string) *SystemPingAction {
	return &SystemPingAction{
		agentVersion: version,
	}
}

func (a *SystemPingAction) Name() string {
	return "system.ping"
}

func (a *SystemPingAction) Validate(payload []byte) error {
	// Ping accepts empty or any optional payload
	return nil
}

func (a *SystemPingAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	now := time.Now()

	logs := []string{"system.ping executed successfully"}
	if dryRun {
		logs = append(logs, "dry-run mode active")
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"pong":          true,
			"timestamp":     now.Unix(),
			"iso_time":      now.UTC().Format(time.RFC3339),
			"agent_version": a.agentVersion,
			"dry_run":       dryRun,
		},
		Logs: logs,
	}, nil
}
