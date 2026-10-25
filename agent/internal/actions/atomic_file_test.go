package actions

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestCreateConfigTempInDestinationDirectory(t *testing.T) {
	destDir := t.TempDir()
	dest := filepath.Join(destDir, "test.conf")
	content := []byte("listen = 127.0.0.1:9000\npm = dynamic\n")

	tmpPath, err := CreateConfigTemp(dest, content, 0640)
	if err != nil {
		t.Fatalf("unexpected error from CreateConfigTemp: %v", err)
	}
	defer os.Remove(tmpPath)

	// Verify temp file is created strictly in the destination directory, not os.TempDir()
	if filepath.Dir(tmpPath) != destDir {
		t.Fatalf("expected temp file to be in destination dir %q, got %q", destDir, filepath.Dir(tmpPath))
	}

	// Verify the filename pattern matches .<base>.tmp-*
	base := filepath.Base(tmpPath)
	expectedPrefix := "." + filepath.Base(dest) + ".tmp-"
	if !strings.HasPrefix(base, expectedPrefix) {
		t.Fatalf("expected temp file prefix %q, got %q", expectedPrefix, base)
	}

	// Verify content
	data, err := os.ReadFile(tmpPath)
	if err != nil {
		t.Fatalf("failed reading temp file: %v", err)
	}
	if string(data) != string(content) {
		t.Fatalf("content mismatch, expected %q, got %q", string(content), string(data))
	}

	// Verify permissions
	info, err := os.Stat(tmpPath)
	if err != nil {
		t.Fatalf("failed to stat temp file: %v", err)
	}
	if info.Mode().Perm() != 0640 {
		t.Fatalf("expected file perm 0640, got %v", info.Mode().Perm())
	}
}

func TestCommitConfigFileRenameSuccess(t *testing.T) {
	destDir := t.TempDir()
	dest := filepath.Join(destDir, "vhost.conf")
	content := []byte("server { listen 80; }")

	tmpPath, err := CreateConfigTemp(dest, content, 0644)
	if err != nil {
		t.Fatalf("failed creating temp file: %v", err)
	}

	if err := CommitConfigFile(tmpPath, dest, 0644); err != nil {
		t.Fatalf("unexpected error committing config file: %v", err)
	}

	// Verify destination exists and has correct content
	data, err := os.ReadFile(dest)
	if err != nil {
		t.Fatalf("destination file not found: %v", err)
	}
	if string(data) != string(content) {
		t.Fatalf("expected %q, got %q", string(content), string(data))
	}

	// Verify temp file has been removed
	if _, err := os.Stat(tmpPath); !os.IsNotExist(err) {
		t.Fatalf("expected temp file %q to be removed, stat err: %v", tmpPath, err)
	}
}

func TestCommitConfigFileFallbackToCopyAndReplace(t *testing.T) {
	destDir := t.TempDir()
	dest := filepath.Join(destDir, "fallback.conf")
	content := []byte("user www-data;\nworker_processes auto;\n")

	tmpPath, err := CreateConfigTemp(dest, content, 0640)
	if err != nil {
		t.Fatalf("failed creating temp file: %v", err)
	}

	// Force os.Rename failure (e.g. simulating EXDEV cross-device link)
	oldRename := renameFunc
	renameFunc = func(oldpath, newpath string) error {
		return errors.New("simulated EXDEV: cross-device link")
	}
	defer func() { renameFunc = oldRename }()

	if err := CommitConfigFile(tmpPath, dest, 0640); err != nil {
		t.Fatalf("expected fallback copy-and-replace to succeed, got: %v", err)
	}

	// Verify dest has the expected content
	data, err := os.ReadFile(dest)
	if err != nil {
		t.Fatalf("destination file not found: %v", err)
	}
	if string(data) != string(content) {
		t.Fatalf("expected %q, got %q", string(content), string(data))
	}

	// Verify permissions
	info, err := os.Stat(dest)
	if err != nil {
		t.Fatalf("failed to stat destination file: %v", err)
	}
	if info.Mode().Perm() != 0640 {
		t.Fatalf("expected file perm 0640, got %v", info.Mode().Perm())
	}

	// Verify temp file has been removed after fallback copy
	if _, err := os.Stat(tmpPath); !os.IsNotExist(err) {
		t.Fatalf("expected temp file %q to be removed after fallback, stat err: %v", tmpPath, err)
	}
}

func TestWriteConfigFileAtomically(t *testing.T) {
	destDir := t.TempDir()
	dest := filepath.Join(destDir, "atomic.conf")
	content := []byte("[global]\npid = /run/php-fpm.pid\n")

	if err := WriteConfigFileAtomically(dest, content, 0600); err != nil {
		t.Fatalf("WriteConfigFileAtomically failed: %v", err)
	}

	data, err := os.ReadFile(dest)
	if err != nil {
		t.Fatalf("destination file not found: %v", err)
	}
	if string(data) != string(content) {
		t.Fatalf("expected %q, got %q", string(content), string(data))
	}

	// Check that no temporary files were left behind in destDir
	entries, err := os.ReadDir(destDir)
	if err != nil {
		t.Fatalf("failed reading destDir: %v", err)
	}
	for _, entry := range entries {
		if strings.HasPrefix(entry.Name(), ".") && strings.Contains(entry.Name(), ".tmp-") {
			t.Fatalf("leftover temporary file found: %s", entry.Name())
		}
	}
}

func TestCreateConfigTempPhpFpmPoolAndNginxVhostPaths(t *testing.T) {
	root := t.TempDir()

	// 1. PHP-FPM Pool path simulation
	poolDir := filepath.Join(root, "etc", "php", "8.4", "fpm", "pool.d")
	poolDest := filepath.Join(poolDir, "customer1.conf")
	poolContent := []byte("[customer1]\nuser = customer1\n")

	poolTmp, err := CreateConfigTemp(poolDest, poolContent, 0640)
	if err != nil {
		t.Fatalf("failed creating pool temp file: %v", err)
	}
	defer os.Remove(poolTmp)

	if filepath.Dir(poolTmp) != poolDir {
		t.Fatalf("expected pool temp file to be in poolDir %q, got %q", poolDir, filepath.Dir(poolTmp))
	}
	if !strings.HasPrefix(filepath.Base(poolTmp), ".customer1.conf.tmp-") {
		t.Fatalf("unexpected pool temp file name: %s", filepath.Base(poolTmp))
	}

	if err := CommitConfigFile(poolTmp, poolDest, 0640); err != nil {
		t.Fatalf("failed committing pool config: %v", err)
	}
	if _, err := os.Stat(poolDest); err != nil {
		t.Fatalf("poolDest not found after commit: %v", err)
	}

	// 2. Nginx Vhost path simulation
	vhostDir := filepath.Join(root, "etc", "nginx", "sites-available")
	vhostDest := filepath.Join(vhostDir, "customer1.com.conf")
	vhostContent := []byte("server {\n  server_name customer1.com;\n}\n")

	vhostTmp, err := CreateConfigTemp(vhostDest, vhostContent, 0644)
	if err != nil {
		t.Fatalf("failed creating vhost temp file: %v", err)
	}
	defer os.Remove(vhostTmp)

	if filepath.Dir(vhostTmp) != vhostDir {
		t.Fatalf("expected vhost temp file to be in vhostDir %q, got %q", vhostDir, filepath.Dir(vhostTmp))
	}
	if !strings.HasPrefix(filepath.Base(vhostTmp), ".customer1.com.conf.tmp-") {
		t.Fatalf("unexpected vhost temp file name: %s", filepath.Base(vhostTmp))
	}

	if err := CommitConfigFile(vhostTmp, vhostDest, 0644); err != nil {
		t.Fatalf("failed committing vhost config: %v", err)
	}
	if _, err := os.Stat(vhostDest); err != nil {
		t.Fatalf("vhostDest not found after commit: %v", err)
	}
}
