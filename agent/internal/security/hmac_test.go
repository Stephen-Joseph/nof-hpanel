package security

import (
	"testing"
)

func TestSignAndVerifyValid(t *testing.T) {
	secret := []byte("super-secret-key-12345")
	action := "system.ping"
	requestID := "req-001"
	timestamp := int64(1774000000)
	nonce := "abc123nonce"
	dryRun := false
	payload := []byte(`{"hello":"world"}`)

	sig := Sign(secret, action, requestID, timestamp, nonce, dryRun, payload)
	if sig == "" {
		t.Fatal("expected non-empty signature")
	}

	valid := VerifySignature(secret, action, requestID, timestamp, nonce, dryRun, payload, sig)
	if !valid {
		t.Fatal("expected signature verification to succeed")
	}
}

func TestVerifySignatureTampering(t *testing.T) {
	secret := []byte("super-secret-key-12345")
	action := "system.ping"
	requestID := "req-001"
	timestamp := int64(1774000000)
	nonce := "abc123nonce"
	dryRun := false
	payload := []byte(`{"hello":"world"}`)

	sig := Sign(secret, action, requestID, timestamp, nonce, dryRun, payload)

	// Tamper payload
	if VerifySignature(secret, action, requestID, timestamp, nonce, dryRun, []byte(`{"hello":"tampered"}`), sig) {
		t.Fatal("expected verification to fail on tampered payload")
	}

	// Tamper action
	if VerifySignature(secret, "system.health", requestID, timestamp, nonce, dryRun, payload, sig) {
		t.Fatal("expected verification to fail on tampered action")
	}

	// Tamper timestamp
	if VerifySignature(secret, action, requestID, timestamp+1, nonce, dryRun, payload, sig) {
		t.Fatal("expected verification to fail on tampered timestamp")
	}

	// Tamper dryRun
	if VerifySignature(secret, action, requestID, timestamp, nonce, true, payload, sig) {
		t.Fatal("expected verification to fail on tampered dry_run")
	}

	// Tamper secret
	if VerifySignature([]byte("wrong-secret"), action, requestID, timestamp, nonce, dryRun, payload, sig) {
		t.Fatal("expected verification to fail on wrong secret")
	}

	// Malformed hex
	if VerifySignature(secret, action, requestID, timestamp, nonce, dryRun, payload, "not-a-hex-string") {
		t.Fatal("expected verification to fail on malformed hex")
	}
}
