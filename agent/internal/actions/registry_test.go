package actions

import (
	"context"
	"errors"
	"testing"
)

type dummyAction struct {
	failValidation bool
}

func (d *dummyAction) Name() string {
	return "test.dummy"
}

func (d *dummyAction) Validate(payload []byte) error {
	if d.failValidation {
		return errors.New("dummy validation failed")
	}
	return nil
}

func (d *dummyAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"echo":    string(payload),
			"dry_run": dryRun,
		},
		Logs: []string{"dummy executed"},
	}, nil
}

func TestRegistry(t *testing.T) {
	reg := NewRegistry()
	reg.Register(&dummyAction{failValidation: false})
	reg.Register(NewSystemPingAction("1.0.0"))
	reg.Register(NewSystemHealthAction())

	// Test registered action execution
	res, err := reg.Execute(context.Background(), "test.dummy", []byte(`{"ping":true}`), false)
	if err != nil {
		t.Fatalf("expected dummy action to succeed, got %v", err)
	}
	if !res.Success {
		t.Fatalf("expected action success")
	}

	// Test unknown action
	resUnknown, err := reg.Execute(context.Background(), "unknown.action", []byte(`{}`), false)
	if !errors.Is(err, ErrUnknownAction) {
		t.Fatalf("expected ErrUnknownAction, got %v", err)
	}
	if resUnknown.Success {
		t.Fatal("expected failure on unknown action")
	}

	// Test validation failure
	reg.Register(&dummyAction{failValidation: true})
	resInvalid, err := reg.Execute(context.Background(), "test.dummy", []byte(`{}`), false)
	if err == nil {
		t.Fatal("expected validation failure")
	}
	if resInvalid.Success {
		t.Fatal("expected failure on invalid payload")
	}
}

func TestSystemPing(t *testing.T) {
	ping := NewSystemPingAction("0.2.0")
	if ping.Name() != "system.ping" {
		t.Fatalf("expected system.ping, got %s", ping.Name())
	}

	if err := ping.Validate([]byte(`{}`)); err != nil {
		t.Fatalf("expected ping validation to pass, got %v", err)
	}

	res, err := ping.Execute(context.Background(), []byte(`{}`), true)
	if err != nil {
		t.Fatalf("expected execution to succeed: %v", err)
	}

	if pong, ok := res.Data["pong"].(bool); !ok || !pong {
		t.Fatal("expected pong to be true")
	}
	if ver, ok := res.Data["agent_version"].(string); !ok || ver != "0.2.0" {
		t.Fatalf("expected version 0.2.0, got %v", ver)
	}
	if dry, ok := res.Data["dry_run"].(bool); !ok || !dry {
		t.Fatal("expected dry_run to be true")
	}
}

func TestSystemHealth(t *testing.T) {
	health := NewSystemHealthAction()
	if health.Name() != "system.health" {
		t.Fatalf("expected system.health, got %s", health.Name())
	}

	res, err := health.Execute(context.Background(), []byte(`{}`), true)
	if err != nil {
		t.Fatalf("expected health execution to succeed: %v", err)
	}

	if !res.Success {
		t.Fatal("expected health check to be successful")
	}
	if _, ok := res.Data["os"]; !ok {
		t.Fatal("expected OS key in health data")
	}
	if _, ok := res.Data["memory"]; !ok {
		t.Fatal("expected memory key in health data")
	}
	if services, ok := res.Data["services"].(map[string]string); !ok || len(services) == 0 {
		t.Fatal("expected services in health data")
	}
}
