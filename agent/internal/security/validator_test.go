package security

import (
	"testing"
	"time"
)

func TestRequestValidator(t *testing.T) {
	secret := []byte("secret-key-abc")
	tracker, err := NewNonceTracker("", 60*time.Second)
	if err != nil {
		t.Fatalf("failed to create nonce tracker: %v", err)
	}

	validator := NewRequestValidator(secret, tracker, 60)

	action := "system.ping"
	reqID := "req-test-1"
	now := time.Now().Unix()
	nonce := "random-nonce-1"
	dryRun := false
	payload := []byte(`{}`)

	sig := Sign(secret, action, reqID, now, nonce, dryRun, payload)

	// 1. Valid request
	if err := validator.ValidateRequest(action, reqID, now, nonce, dryRun, payload, sig); err != nil {
		t.Fatalf("expected valid request to succeed, got %v", err)
	}

	// 2. Replay of same nonce should fail
	if err := validator.ValidateRequest(action, reqID, now, nonce, dryRun, payload, sig); err != ErrReplayedNonce {
		t.Fatalf("expected replay error, got %v", err)
	}

	// 3. Expired timestamp (> 60s)
	expiredTS := now - 65
	expiredNonce := "expired-nonce-1"
	expiredSig := Sign(secret, action, reqID, expiredTS, expiredNonce, dryRun, payload)
	if err := validator.ValidateRequest(action, reqID, expiredTS, expiredNonce, dryRun, payload, expiredSig); err != ErrTimestampExpired {
		t.Fatalf("expected ErrTimestampExpired, got %v", err)
	}

	// 4. Future timestamp (> 60s)
	futureTS := now + 70
	futureNonce := "future-nonce-1"
	futureSig := Sign(secret, action, reqID, futureTS, futureNonce, dryRun, payload)
	if err := validator.ValidateRequest(action, reqID, futureTS, futureNonce, dryRun, payload, futureSig); err != ErrTimestampExpired {
		t.Fatalf("expected ErrTimestampExpired for future time, got %v", err)
	}

	// 5. Invalid signature
	freshNonce := "fresh-nonce-1"
	if err := validator.ValidateRequest(action, reqID, now, freshNonce, dryRun, payload, "invalid-sig-hex"); err != ErrInvalidSignature {
		t.Fatalf("expected ErrInvalidSignature, got %v", err)
	}
}
