package security

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"strconv"
)

// ComputePayloadHash computes SHA-256 hex string of the raw JSON payload.
func ComputePayloadHash(payload []byte) string {
	hash := sha256.Sum256(payload)
	return hex.EncodeToString(hash[:])
}

// BuildCanonicalString generates the exact string to be signed.
// Format: action.requestId.timestamp.nonce.dryRun.payloadHash
func BuildCanonicalString(action, requestID string, timestamp int64, nonce string, dryRun bool, payload []byte) string {
	dryRunStr := "0"
	if dryRun {
		dryRunStr = "1"
	}
	payloadHash := ComputePayloadHash(payload)

	return fmt.Sprintf("%s.%s.%s.%s.%s.%s",
		action,
		requestID,
		strconv.FormatInt(timestamp, 10),
		nonce,
		dryRunStr,
		payloadHash,
	)
}

// Sign computes the HMAC-SHA256 signature for a request.
func Sign(secretKey []byte, action, requestID string, timestamp int64, nonce string, dryRun bool, payload []byte) string {
	mac := hmac.New(sha256.New, secretKey)
	canonical := BuildCanonicalString(action, requestID, timestamp, nonce, dryRun, payload)
	mac.Write([]byte(canonical))
	return hex.EncodeToString(mac.Sum(nil))
}

// VerifySignature verifies the provided hex signature using constant-time comparison.
func VerifySignature(secretKey []byte, action, requestID string, timestamp int64, nonce string, dryRun bool, payload []byte, signatureHex string) bool {
	expectedSig := Sign(secretKey, action, requestID, timestamp, nonce, dryRun, payload)
	expectedBytes, err := hex.DecodeString(expectedSig)
	if err != nil {
		return false
	}

	actualBytes, err := hex.DecodeString(signatureHex)
	if err != nil {
		return false
	}

	return hmac.Equal(expectedBytes, actualBytes)
}
