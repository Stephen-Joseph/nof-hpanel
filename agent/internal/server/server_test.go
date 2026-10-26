package server

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/hpanel/agent/internal/actions"
	"github.com/hpanel/agent/internal/config"
	"github.com/hpanel/agent/internal/security"
)

type failingTestAction struct{}

func (f failingTestAction) Name() string                  { return "test.fail" }
func (f failingTestAction) Validate(payload []byte) error { return nil }
func (f failingTestAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*actions.ActionResult, error) {
	return &actions.ActionResult{
		Success: false,
		Error:   "step 'php_pool' failed: php-fpm syntax test failed",
	}, errors.New("php-fpm syntax test failed")
}

func setupTestServer(secret string) (*Server, *security.RequestValidator, *actions.Registry) {
	cfg := config.DefaultConfig()
	cfg.Auth.HMACSecret = secret
	cfg.Auth.MaxTimestampSkewSeconds = 60

	tracker, _ := security.NewNonceTracker("", 60*time.Second)
	validator := security.NewRequestValidator([]byte(secret), tracker, 60)

	registry := actions.NewRegistry()
	registry.Register(actions.NewSystemPingAction("1.0.0-test"))
	registry.Register(actions.NewSystemHealthAction())
	registry.Register(actions.NewAccountCreateAction())

	srv := NewServer(cfg, validator, registry, nil)
	return srv, validator, registry
}

func TestServerHealthz(t *testing.T) {
	srv, _, _ := setupTestServer("secret")
	req := httptest.NewRequest(http.MethodGet, "/healthz", nil)
	rec := httptest.NewRecorder()

	srv.Routes().ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200 OK, got %d", rec.Code)
	}
}

func TestServerHealth(t *testing.T) {
	srv, _, _ := setupTestServer("secret")
	req := httptest.NewRequest(http.MethodGet, "/health", nil)
	rec := httptest.NewRecorder()

	srv.Routes().ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200 OK, got %d", rec.Code)
	}

	var health map[string]any
	if err := json.Unmarshal(rec.Body.Bytes(), &health); err != nil {
		t.Fatalf("failed to decode health JSON: %v", err)
	}
	if health["status"] != "ok" {
		t.Errorf("expected status 'ok', got %v", health["status"])
	}
	if health["version"] == nil || health["version"] == "" {
		t.Errorf("expected version to be present, got %v", health["version"])
	}
	if health["quotas"] == nil {
		t.Errorf("expected quotas to be present in health response")
	}
	if health["services"] == nil {
		t.Errorf("expected services to be present in health response")
	}
}

func TestServerActionPingSuccess(t *testing.T) {
	secret := "secret-key-1234"
	srv, _, _ := setupTestServer(secret)

	now := time.Now().Unix()
	payload := []byte(`{}`)
	sig := security.Sign([]byte(secret), "system.ping", "req-test-1", now, "nonce-ping-1", false, payload)

	reqBody, _ := json.Marshal(ActionRequest{
		Action:    "system.ping",
		RequestID: "req-test-1",
		Timestamp: now,
		Nonce:     "nonce-ping-1",
		DryRun:    false,
		Payload:   payload,
		Signature: sig,
	})

	req := httptest.NewRequest(http.MethodPost, "/action", bytes.NewReader(reqBody))
	req.Header.Set("Content-Type", "application/json")
	rec := httptest.NewRecorder()

	srv.Routes().ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d body: %s", rec.Code, rec.Body.String())
	}

	var resp ActionResponse
	if err := json.Unmarshal(rec.Body.Bytes(), &resp); err != nil {
		t.Fatalf("failed to decode response: %v", err)
	}

	if !resp.Success {
		t.Fatal("expected response success to be true")
	}
	if resp.RequestID != "req-test-1" {
		t.Fatalf("expected req-test-1, got %s", resp.RequestID)
	}
}

func TestServerActionInvalidSignature(t *testing.T) {
	srv, _, _ := setupTestServer("secret-key-1234")

	now := time.Now().Unix()
	reqBody, _ := json.Marshal(ActionRequest{
		Action:    "system.ping",
		RequestID: "req-test-2",
		Timestamp: now,
		Nonce:     "nonce-ping-2",
		DryRun:    false,
		Payload:   []byte(`{}`),
		Signature: "bad-signature",
	})

	req := httptest.NewRequest(http.MethodPost, "/action", bytes.NewReader(reqBody))
	rec := httptest.NewRecorder()

	srv.Routes().ServeHTTP(rec, req)

	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("expected 401 Unauthorized, got %d", rec.Code)
	}
}

func TestServerActionReplayedNonce(t *testing.T) {
	secret := "secret-key-1234"
	srv, _, _ := setupTestServer(secret)

	now := time.Now().Unix()
	payload := []byte(`{}`)
	sig := security.Sign([]byte(secret), "system.ping", "req-test-3", now, "nonce-reuse", false, payload)

	reqBody, _ := json.Marshal(ActionRequest{
		Action:    "system.ping",
		RequestID: "req-test-3",
		Timestamp: now,
		Nonce:     "nonce-reuse",
		DryRun:    false,
		Payload:   payload,
		Signature: sig,
	})

	// First request succeeds
	req1 := httptest.NewRequest(http.MethodPost, "/action", bytes.NewReader(reqBody))
	rec1 := httptest.NewRecorder()
	srv.Routes().ServeHTTP(rec1, req1)
	if rec1.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", rec1.Code)
	}

	// Second request with same nonce fails
	req2 := httptest.NewRequest(http.MethodPost, "/action", bytes.NewReader(reqBody))
	rec2 := httptest.NewRecorder()
	srv.Routes().ServeHTTP(rec2, req2)
	if rec2.Code != http.StatusUnauthorized {
		t.Fatalf("expected 401 for replayed nonce, got %d", rec2.Code)
	}
}

func TestServerActionExpiredTimestamp(t *testing.T) {
	secret := "secret-key-1234"
	srv, _, _ := setupTestServer(secret)

	past := time.Now().Unix() - 100
	payload := []byte(`{}`)
	sig := security.Sign([]byte(secret), "system.ping", "req-test-4", past, "nonce-expired", false, payload)

	reqBody, _ := json.Marshal(ActionRequest{
		Action:    "system.ping",
		RequestID: "req-test-4",
		Timestamp: past,
		Nonce:     "nonce-expired",
		DryRun:    false,
		Payload:   payload,
		Signature: sig,
	})

	req := httptest.NewRequest(http.MethodPost, "/action", bytes.NewReader(reqBody))
	rec := httptest.NewRecorder()
	srv.Routes().ServeHTTP(rec, req)

	if rec.Code != http.StatusUnauthorized {
		t.Fatalf("expected 401 for expired timestamp, got %d", rec.Code)
	}
}

func TestServerActionUnknownAction(t *testing.T) {
	secret := "secret-key-1234"
	srv, _, _ := setupTestServer(secret)

	now := time.Now().Unix()
	payload := []byte(`{}`)
	sig := security.Sign([]byte(secret), "unknown.action", "req-test-5", now, "nonce-unk", false, payload)

	reqBody, _ := json.Marshal(ActionRequest{
		Action:    "unknown.action",
		RequestID: "req-test-5",
		Timestamp: now,
		Nonce:     "nonce-unk",
		DryRun:    false,
		Payload:   payload,
		Signature: sig,
	})

	req := httptest.NewRequest(http.MethodPost, "/action", bytes.NewReader(reqBody))
	rec := httptest.NewRecorder()
	srv.Routes().ServeHTTP(rec, req)

	if rec.Code != http.StatusNotFound {
		t.Fatalf("expected 404 for unknown action, got %d", rec.Code)
	}
}

func TestServerActionValidationReturns422(t *testing.T) {
	secret := "secret-key-1234"
	srv, _, _ := setupTestServer(secret)

	now := time.Now().Unix()
	payload := []byte(`{"username":"no"}`)
	sig := security.Sign([]byte(secret), "account.create", "req-test-6", now, "nonce-invalid", false, payload)

	reqBody, _ := json.Marshal(ActionRequest{
		Action:    "account.create",
		RequestID: "req-test-6",
		Timestamp: now,
		Nonce:     "nonce-invalid",
		DryRun:    false,
		Payload:   payload,
		Signature: sig,
	})

	req := httptest.NewRequest(http.MethodPost, "/action", bytes.NewReader(reqBody))
	rec := httptest.NewRecorder()
	srv.Routes().ServeHTTP(rec, req)

	if rec.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422 for validation error, got %d body=%s", rec.Code, rec.Body.String())
	}

	var errResp ErrorResponse
	if err := json.Unmarshal(rec.Body.Bytes(), &errResp); err != nil {
		t.Fatalf("failed to decode 422 error response: %v", err)
	}
	if errResp.Error.Code != "VALIDATION_FAILED" {
		t.Errorf("expected error code VALIDATION_FAILED, got %s", errResp.Error.Code)
	}
	if errResp.Error.Message == "" {
		t.Error("expected non-empty error message")
	}
}

func TestServerActionExecutionFailureReturns500(t *testing.T) {
	secret := "secret-key-1234"
	srv, _, registry := setupTestServer(secret)
	registry.Register(failingTestAction{})

	now := time.Now().Unix()
	payload := []byte(`{}`)
	sig := security.Sign([]byte(secret), "test.fail", "req-test-7", now, "nonce-fail", false, payload)

	reqBody, _ := json.Marshal(ActionRequest{
		Action:    "test.fail",
		RequestID: "req-test-7",
		Timestamp: now,
		Nonce:     "nonce-fail",
		DryRun:    false,
		Payload:   payload,
		Signature: sig,
	})

	req := httptest.NewRequest(http.MethodPost, "/action", bytes.NewReader(reqBody))
	rec := httptest.NewRecorder()
	srv.Routes().ServeHTTP(rec, req)

	if rec.Code != http.StatusInternalServerError {
		t.Fatalf("expected 500 for action execution failure, got %d body=%s", rec.Code, rec.Body.String())
	}

	var errResp ErrorResponse
	if err := json.Unmarshal(rec.Body.Bytes(), &errResp); err != nil {
		t.Fatalf("failed to decode 500 error response: %v", err)
	}
	if errResp.Error.Code != "STEP_FAILED" {
		t.Errorf("expected error code STEP_FAILED, got %s", errResp.Error.Code)
	}
	if !strings.Contains(errResp.Error.Message, "php-fpm syntax test failed") {
		t.Errorf("expected error message to contain cause, got %s", errResp.Error.Message)
	}
}
