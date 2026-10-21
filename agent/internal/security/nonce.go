package security

import (
	"bufio"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"
)

var (
	ErrReplayedNonce = errors.New("security: replay detected, nonce has already been used")
	ErrEmptyNonce    = errors.New("security: nonce cannot be empty")
)

// NonceTracker manages nonce verification using an in-memory map + persistent disk ring buffer.
type NonceTracker struct {
	mu           sync.RWMutex
	nonces       map[string]int64
	ringFilePath string
	window       time.Duration
}

// NewNonceTracker initializes a NonceTracker with the specified window and optional disk path.
func NewNonceTracker(ringFilePath string, window time.Duration) (*NonceTracker, error) {
	if window <= 0 {
		window = 60 * time.Second
	}

	tracker := &NonceTracker{
		nonces:       make(map[string]int64),
		ringFilePath: ringFilePath,
		window:       window,
	}

	if ringFilePath != "" {
		if err := tracker.loadFromDisk(); err != nil && !os.IsNotExist(err) {
			return nil, fmt.Errorf("failed to load nonces from disk: %w", err)
		}
	}

	return tracker, nil
}

// CheckAndRecord checks if a nonce was previously seen. If new, it registers it.
func (nt *NonceTracker) CheckAndRecord(nonce string, timestamp int64) error {
	nonce = strings.TrimSpace(nonce)
	if nonce == "" {
		return ErrEmptyNonce
	}

	nt.mu.Lock()
	defer nt.mu.Unlock()

	// Clean up entries older than the sliding window
	nt.pruneExpiredLocked()

	// Check if nonce already exists
	if _, exists := nt.nonces[nonce]; exists {
		return ErrReplayedNonce
	}

	// Record in memory
	nt.nonces[nonce] = timestamp

	// Persist to disk buffer if configured
	if nt.ringFilePath != "" {
		if err := nt.appendDiskLocked(nonce, timestamp); err != nil {
			// Log error but do not fail operation if memory write succeeded
			_ = err
		}
	}

	return nil
}

func (nt *NonceTracker) pruneExpiredLocked() {
	cutoff := time.Now().Add(-nt.window).Unix()
	for n, ts := range nt.nonces {
		if ts < cutoff {
			delete(nt.nonces, n)
		}
	}
}

func (nt *NonceTracker) appendDiskLocked(nonce string, timestamp int64) error {
	dir := filepath.Dir(nt.ringFilePath)
	if dir != "" && dir != "." {
		if err := os.MkdirAll(dir, 0700); err != nil {
			return err
		}
	}

	f, err := os.OpenFile(nt.ringFilePath, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0600)
	if err != nil {
		return err
	}
	defer f.Close()

	_, err = fmt.Fprintf(f, "%d:%s\n", timestamp, nonce)
	return err
}

func (nt *NonceTracker) loadFromDisk() error {
	if nt.ringFilePath == "" {
		return nil
	}

	f, err := os.Open(nt.ringFilePath)
	if err != nil {
		return err
	}
	defer f.Close()

	cutoff := time.Now().Add(-nt.window).Unix()
	scanner := bufio.NewScanner(f)

	nt.mu.Lock()
	defer nt.mu.Unlock()

	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" {
			continue
		}
		parts := strings.SplitN(line, ":", 2)
		if len(parts) != 2 {
			continue
		}
		ts, err := strconv.ParseInt(parts[0], 10, 64)
		if err != nil {
			continue
		}
		if ts >= cutoff {
			nt.nonces[parts[1]] = ts
		}
	}

	return scanner.Err()
}
