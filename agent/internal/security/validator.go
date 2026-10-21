package security

import (
	"errors"
	"math"
	"time"
)

var (
	ErrTimestampExpired = errors.New("security: request timestamp is older than allowed window or too far in the future")
	ErrInvalidSignature = errors.New("security: invalid HMAC-SHA256 signature")
	ErrMissingAction    = errors.New("validation: action field is required")
	ErrMissingRequestID = errors.New("validation: request_id field is required")
)

// RequestValidator orchestrates timestamp, nonce, and HMAC verification.
type RequestValidator struct {
	hmacSecret   []byte
	nonceTracker *NonceTracker
	maxSkewSecs  int64
}

func NewRequestValidator(hmacSecret []byte, nonceTracker *NonceTracker, maxSkewSecs int64) *RequestValidator {
	if maxSkewSecs <= 0 {
		maxSkewSecs = 60
	}
	return &RequestValidator{
		hmacSecret:   hmacSecret,
		nonceTracker: nonceTracker,
		maxSkewSecs:  maxSkewSecs,
	}
}

// ValidateRequest performs timestamp freshness check, nonce replay check, and HMAC verification.
func (rv *RequestValidator) ValidateRequest(action, requestID string, timestamp int64, nonce string, dryRun bool, payload []byte, signatureHex string) error {
	if action == "" {
		return ErrMissingAction
	}
	if requestID == "" {
		return ErrMissingRequestID
	}

	// 1. Timestamp window check
	now := time.Now().Unix()
	if int64(math.Abs(float64(now-timestamp))) > rv.maxSkewSecs {
		return ErrTimestampExpired
	}

	// 2. HMAC-SHA256 signature verification (must be verified before consuming nonce)
	if !VerifySignature(rv.hmacSecret, action, requestID, timestamp, nonce, dryRun, payload, signatureHex) {
		return ErrInvalidSignature
	}

	// 3. Replay check (nonce)
	if err := rv.nonceTracker.CheckAndRecord(nonce, timestamp); err != nil {
		return err
	}

	return nil
}
