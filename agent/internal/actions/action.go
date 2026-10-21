package actions

import (
	"context"
	"errors"
	"fmt"
	"sync"
)

var (
	ErrUnknownAction = errors.New("actions: unknown action requested")
)

// ActionResult encapsulates the structured output, data, and log lines of an executed action.
type ActionResult struct {
	Success bool           `json:"success"`
	Data    map[string]any `json:"data,omitempty"`
	Error   string         `json:"error,omitempty"`
	Logs    []string       `json:"logs,omitempty"`
}

// Action represents a typed, validated operation executable by the agent.
type Action interface {
	Name() string
	Validate(payload []byte) error
	Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error)
}

// Registry maintains the allowable set of typed actions.
type Registry struct {
	mu      sync.RWMutex
	actions map[string]Action
}

func NewRegistry() *Registry {
	return &Registry{
		actions: make(map[string]Action),
	}
}

func (r *Registry) Register(action Action) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.actions[action.Name()] = action
}

func (r *Registry) Get(name string) (Action, bool) {
	r.mu.RLock()
	defer r.mu.RUnlock()
	act, exists := r.actions[name]
	return act, exists
}

func (r *Registry) Execute(ctx context.Context, name string, payload []byte, dryRun bool) (*ActionResult, error) {
	act, exists := r.Get(name)
	if !exists {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("unknown action: %s", name),
			Logs:    []string{fmt.Sprintf("rejected unregistered action '%s'", name)},
		}, ErrUnknownAction
	}

	if err := act.Validate(payload); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("validation failed: %v", err),
			Logs:    []string{fmt.Sprintf("payload validation error on '%s': %v", name, err)},
		}, err
	}

	return act.Execute(ctx, payload, dryRun)
}
