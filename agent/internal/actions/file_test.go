package actions

import (
	"archive/zip"
	"bytes"
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestResolveSafePath(t *testing.T) {
	username := "testuser"

	// Valid paths inside home
	validPaths := []string{
		"/home/testuser",
		"/home/testuser/public_html",
		"/home/testuser/public_html/index.php",
		"public_html/style.css",
		"./logs/access.log",
	}

	for _, p := range validPaths {
		safe, err := ResolveSafePath(username, p)
		if err != nil {
			t.Errorf("expected valid path for '%s', got error: %v", p, err)
		}
		if !strings.HasPrefix(safe, "/home/testuser") {
			t.Errorf("expected safe path to start with /home/testuser, got: %s", safe)
		}
	}

	// Invalid traversal attacks
	invalidPaths := []string{
		"/etc/passwd",
		"/home/testuser/../../etc/shadow",
		"../../../../var/www",
		"/home/otheruser/public_html",
		"public_html/..\x00/evil",
	}

	for _, p := range invalidPaths {
		_, err := ResolveSafePath(username, p)
		if err == nil {
			t.Errorf("expected error for traversal attack '%s', got nil", p)
		}
	}
}

func TestZipSlipDefense(t *testing.T) {
	// Create a malicious zip in-memory containing a directory traversal entry
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)

	// Create an illegal Zip Slip header
	header := &zip.FileHeader{
		Name:   "../../../../tmp/pwned.txt",
		Method: zip.Deflate,
	}
	w, err := zw.CreateHeader(header)
	if err != nil {
		t.Fatalf("failed creating zip header: %v", err)
	}
	_, _ = w.Write([]byte("malicious content"))
	_ = zw.Close()

	// Write zip to temporary file
	tempDir, err := os.MkdirTemp("", "zipslip-test-*")
	if err != nil {
		t.Fatalf("failed creating temp dir: %v", err)
	}
	defer os.RemoveAll(tempDir)

	zipPath := filepath.Join(tempDir, "malicious.zip")
	if err := os.WriteFile(zipPath, buf.Bytes(), 0644); err != nil {
		t.Fatalf("failed writing zip: %v", err)
	}

	destDir := filepath.Join(tempDir, "dest")
	_ = os.MkdirAll(destDir, 0755)

	// ExtractZipSafely must detect and abort the Zip Slip attempt
	err = ExtractZipSafely(zipPath, destDir)
	if err == nil {
		t.Fatalf("expected ExtractZipSafely to fail with Zip Slip error, but succeeded")
	}
	if !strings.Contains(err.Error(), "zip slip") {
		t.Errorf("expected zip slip error message, got: %v", err)
	}
}

func TestSSHKeyValidation(t *testing.T) {
	// Valid ed25519 key
	validEd25519 := "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIH/9m3K/nJ3zL22Lq+hR4jH4H5P6cWjT/8G7b4vF8r3P admin@workstation"
	info, err := ValidateAndParseSSHPublicKey(validEd25519)
	if err != nil {
		t.Fatalf("expected valid ed25519 key, got error: %v", err)
	}
	if info.KeyType != "ssh-ed25519" {
		t.Errorf("expected key type ssh-ed25519, got: %s", info.KeyType)
	}
	if !strings.HasPrefix(info.Fingerprint, "SHA256:") {
		t.Errorf("expected SHA256: fingerprint, got: %s", info.Fingerprint)
	}

	// Invalid key type (dsa)
	invalidDSA := "ssh-dss AAAAB3NzaC1kc3MAAACBAP123... insecure@key"
	if _, err := ValidateAndParseSSHPublicKey(invalidDSA); err == nil {
		t.Errorf("expected rejection of weak/unsupported ssh-dss key, got nil")
	}

	// Weak RSA (< 2048 bits)
	weakRSA := "ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAAAQQD short"
	if _, err := ValidateAndParseSSHPublicKey(weakRSA); err == nil {
		t.Errorf("expected rejection of short/weak RSA key, got nil")
	}
}

func TestFileActionsDryRun(t *testing.T) {
	ctx := context.Background()

	// List
	listAction := NewFileListAction()
	listPayload, _ := json.Marshal(FileListPayload{Username: "bob", Path: "/home/bob"})
	if res, err := listAction.Execute(ctx, listPayload, true); err != nil || !res.Success {
		t.Fatalf("file.list dry-run failed: %v", err)
	}

	// Read
	readAction := NewFileReadAction()
	readPayload, _ := json.Marshal(FileReadPayload{Username: "bob", Path: "/home/bob/index.php"})
	if res, err := readAction.Execute(ctx, readPayload, true); err != nil || !res.Success {
		t.Fatalf("file.read dry-run failed: %v", err)
	}

	// Write
	writeAction := NewFileWriteAction()
	writePayload, _ := json.Marshal(FileWritePayload{Username: "bob", Path: "/home/bob/test.txt", Content: "Hello"})
	if res, err := writeAction.Execute(ctx, writePayload, true); err != nil || !res.Success {
		t.Fatalf("file.write dry-run failed: %v", err)
	}

	// Create
	createAction := NewFileCreateAction()
	createPayload, _ := json.Marshal(FileCreatePayload{Username: "bob", Path: "/home/bob/newdir", IsDir: true})
	if res, err := createAction.Execute(ctx, createPayload, true); err != nil || !res.Success {
		t.Fatalf("file.create dry-run failed: %v", err)
	}

	// Delete
	deleteAction := NewFileDeleteAction()
	deletePayload, _ := json.Marshal(FileDeletePayload{Username: "bob", Path: "/home/bob/old.txt"})
	if res, err := deleteAction.Execute(ctx, deletePayload, true); err != nil || !res.Success {
		t.Fatalf("file.delete dry-run failed: %v", err)
	}

	// Chmod
	chmodAction := NewFileChmodAction()
	chmodPayload, _ := json.Marshal(FileChmodPayload{Username: "bob", Path: "/home/bob/script.sh", Mode: "0755"})
	if res, err := chmodAction.Execute(ctx, chmodPayload, true); err != nil || !res.Success {
		t.Fatalf("file.chmod dry-run failed: %v", err)
	}

	// SSH Add & Remove
	sshAddAction := NewSshKeyAddAction()
	validKey := "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIH/9m3K/nJ3zL22Lq+hR4jH4H5P6cWjT/8G7b4vF8r3P mykey"
	sshPayload, _ := json.Marshal(SshKeyAddPayload{Username: "bob", PublicKey: validKey, Name: "Work", SSHEnabled: true})
	if res, err := sshAddAction.Execute(ctx, sshPayload, true); err != nil || !res.Success {
		t.Fatalf("ssh.key_add dry-run failed: %v", err)
	}

	sshRemoveAction := NewSshKeyRemoveAction()
	sshRemPayload, _ := json.Marshal(SshKeyRemovePayload{Username: "bob", PublicKey: validKey})
	if res, err := sshRemoveAction.Execute(ctx, sshRemPayload, true); err != nil || !res.Success {
		t.Fatalf("ssh.key_remove dry-run failed: %v", err)
	}
}
