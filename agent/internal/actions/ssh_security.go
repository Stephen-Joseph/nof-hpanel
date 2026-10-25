package actions

import (
	"crypto/sha256"
	"encoding/base64"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

var allowedSSHKeyTypes = []string{
	"ssh-ed25519",
	"ssh-rsa",
	"ecdsa-sha2-nistp256",
	"ecdsa-sha2-nistp384",
	"ecdsa-sha2-nistp521",
	"sk-ssh-ed25519@openssh.com",
}

type SSHPublicKeyInfo struct {
	KeyType     string `json:"key_type"`
	BlobBase64  string `json:"blob_base64"`
	Fingerprint string `json:"fingerprint"`
	Comment     string `json:"comment"`
	Raw         string `json:"raw"`
}

// ValidateAndParseSSHPublicKey validates the SSH key type, base64 encoding, minimum key strength, and calculates its SHA256 fingerprint.
func ValidateAndParseSSHPublicKey(keyStr string) (*SSHPublicKeyInfo, error) {
	trimmed := strings.TrimSpace(keyStr)
	if trimmed == "" {
		return nil, errors.New("SSH public key cannot be empty")
	}

	parts := strings.Fields(trimmed)
	if len(parts) < 2 {
		return nil, errors.New("invalid SSH public key format: expected '<type> <base64-blob> [comment]'")
	}

	keyType := parts[0]
	blob := parts[1]
	comment := ""
	if len(parts) > 2 {
		comment = strings.Join(parts[2:], " ")
	}

	// 1. Allow-list check
	isAllowed := false
	for _, allowed := range allowedSSHKeyTypes {
		if keyType == allowed {
			isAllowed = true
			break
		}
	}
	if !isAllowed {
		return nil, fmt.Errorf("unsupported or insecure SSH key type '%s' (allowed: ed25519, rsa, ecdsa)", keyType)
	}

	// 2. Decode base64
	decoded, err := base64.StdEncoding.DecodeString(blob)
	if err != nil {
		return nil, fmt.Errorf("corrupt SSH key base64 data: %w", err)
	}

	// 3. Reject weak keys (e.g. RSA < 2048 bits)
	if keyType == "ssh-rsa" && len(decoded) < 260 {
		return nil, errors.New("weak RSA key: key size must be at least 2048 bits (recommend ssh-ed25519)")
	}

	// 4. SHA256 Fingerprint calculation (standard OpenSSH format)
	hash := sha256.Sum256(decoded)
	fingerprint := "SHA256:" + base64.RawStdEncoding.EncodeToString(hash[:])

	return &SSHPublicKeyInfo{
		KeyType:     keyType,
		BlobBase64:  blob,
		Fingerprint: fingerprint,
		Comment:     comment,
		Raw:         fmt.Sprintf("%s %s %s", keyType, blob, comment),
	}, nil
}

// WriteAuthorizedKey appends or updates an authorized key with optional SFTP jailing if shell is disabled.
func WriteAuthorizedKey(username string, keyInfo *SSHPublicKeyInfo, sshEnabled bool) error {
	sshDir := filepath.Join(fmt.Sprintf("/home/%s", username), ".ssh")
	if err := os.MkdirAll(sshDir, 0700); err != nil {
		return err
	}

	authKeysFile := filepath.Join(sshDir, "authorized_keys")

	line := keyInfo.Raw
	if !sshEnabled {
		// Strict SFTP only jail: disable interactive shell, PTY, and X11 forwarding
		line = fmt.Sprintf(`no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty,command="/usr/lib/openssh/sftp-server" %s`, line)
	}

	// Read existing keys to prevent duplicates
	var lines []string
	if existing, err := os.ReadFile(authKeysFile); err == nil {
		for _, l := range strings.Split(string(existing), "\n") {
			l = strings.TrimSpace(l)
			if l != "" && !strings.Contains(l, keyInfo.BlobBase64) {
				lines = append(lines, l)
			}
		}
	}

	lines = append(lines, line)
	content := strings.Join(lines, "\n") + "\n"

	return os.WriteFile(authKeysFile, []byte(content), 0600)
}

// RemoveAuthorizedKeyByFingerprint removes a key matching the blob from authorized_keys.
func RemoveAuthorizedKeyByBlob(username, blob string) error {
	authKeysFile := filepath.Join(fmt.Sprintf("/home/%s", username), ".ssh", "authorized_keys")
	existing, err := os.ReadFile(authKeysFile)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return err
	}

	var newLines []string
	for _, l := range strings.Split(string(existing), "\n") {
		l = strings.TrimSpace(l)
		if l != "" && !strings.Contains(l, blob) {
			newLines = append(newLines, l)
		}
	}

	content := strings.Join(newLines, "\n")
	if len(newLines) > 0 {
		content += "\n"
	}

	return os.WriteFile(authKeysFile, []byte(content), 0600)
}
