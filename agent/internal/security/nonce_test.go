package security

import (
	"path/filepath"
	"testing"
	"time"
)

func TestNonceTrackerReplayDetection(t *testing.T) {
	tempDir := t.TempDir()
	ringFile := filepath.Join(tempDir, "nonces.dat")

	tracker, err := NewNonceTracker(ringFile, 60*time.Second)
	if err != nil {
		t.Fatalf("unexpected error creating nonce tracker: %v", err)
	}

	now := time.Now().Unix()
	nonce := "unique-nonce-1"

	// First use should succeed
	if err := tracker.CheckAndRecord(nonce, now); err != nil {
		t.Fatalf("expected first use to succeed, got %v", err)
	}

	// Immediate reuse of the same nonce should fail with ErrReplayedNonce
	if err := tracker.CheckAndRecord(nonce, now); err != ErrReplayedNonce {
		t.Fatalf("expected ErrReplayedNonce, got %v", err)
	}

	// Empty nonce should fail
	if err := tracker.CheckAndRecord("", now); err != ErrEmptyNonce {
		t.Fatalf("expected ErrEmptyNonce, got %v", err)
	}
}

func TestNonceTrackerPersistence(t *testing.T) {
	tempDir := t.TempDir()
	ringFile := filepath.Join(tempDir, "nonces.dat")

	tracker1, err := NewNonceTracker(ringFile, 60*time.Second)
	if err != nil {
		t.Fatalf("failed to create tracker 1: %v", err)
	}

	now := time.Now().Unix()
	nonce := "persistent-nonce-xyz"

	if err := tracker1.CheckAndRecord(nonce, now); err != nil {
		t.Fatalf("failed to record nonce: %v", err)
	}

	// Recreate tracker from disk file
	tracker2, err := NewNonceTracker(ringFile, 60*time.Second)
	if err != nil {
		t.Fatalf("failed to recreate tracker 2 from disk: %v", err)
	}

	// Should still detect replay from persisted file
	if err := tracker2.CheckAndRecord(nonce, now); err != ErrReplayedNonce {
		t.Fatalf("expected replayed nonce after restart, got %v", err)
	}
}

func TestNonceTrackerPrunesExpired(t *testing.T) {
	tracker, err := NewNonceTracker("", 100*time.Millisecond)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	past := time.Now().Add(-1 * time.Second).Unix()
	nonce := "expired-nonce"

	if err := tracker.CheckAndRecord(nonce, past); err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	// Sleep longer than sliding window
	time.Sleep(150 * time.Millisecond)

	// A new record with fresh timestamp should prune the expired one
	freshNow := time.Now().Unix()
	if err := tracker.CheckAndRecord("other-nonce", freshNow); err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	// Now check if expired nonce was purged
	tracker.mu.RLock()
	_, exists := tracker.nonces[nonce]
	tracker.mu.RUnlock()

	if exists {
		t.Fatal("expected expired nonce to be pruned from in-memory map")
	}
}
