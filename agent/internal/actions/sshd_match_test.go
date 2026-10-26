package actions

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestAccountCreateSshdMatchBlockSuccessAndRollback(t *testing.T) {
	t.Run("success writes match block and reloads sshd", func(t *testing.T) {
		exec := &fakeCommandExecutor{
			combinedResult: map[string]fakeCommandResult{
				"sshd -t": {out: []byte("Syntax OK"), err: nil},
			},
			lookups: map[string]bool{
				"setquota":   true,
				"php-fpm8.4": true,
			},
		}

		root := t.TempDir()
		sshdDir := filepath.Join(root, "etc", "ssh", "sshd_config.d")
		action := NewAccountCreateActionForTest(
			exec,
			filepath.Join(root, "home"),
			filepath.Join(root, "tmp"),
			filepath.Join(root, "etc", "php"),
			filepath.Join(root, "etc", "nginx", "sites-available"),
			filepath.Join(root, "etc", "nginx", "sites-enabled"),
			filepath.Join(root, "etc", "systemd", "system"),
		).WithSshdConfigDir(sshdDir)

		payload, _ := json.Marshal(AccountCreatePayload{
			Username:      "sftpuser1",
			PrimaryDomain: "sftpuser1.com",
			PHPVersion:    "8.4",
			StorageMB:     1024,
			Inodes:        50000,
		})

		res, err := action.Execute(context.Background(), payload, false)
		if err != nil {
			t.Fatalf("unexpected error: %v", err)
		}
		if !res.Success {
			t.Fatalf("expected success, got error: %s", res.Error)
		}

		confPath := filepath.Join(sshdDir, "hpanel-sftpuser1.conf")
		data, err := os.ReadFile(confPath)
		if err != nil {
			t.Fatalf("expected sshd conf to exist: %v", err)
		}
		content := string(data)
		if !strings.Contains(content, "Match User sftpuser1") {
			t.Errorf("expected Match User sftpuser1 in config, got:\n%s", content)
		}
		if !strings.Contains(content, "ChrootDirectory /home/sftpuser1") {
			t.Errorf("expected ChrootDirectory /home/sftpuser1 in config, got:\n%s", content)
		}
		if !strings.Contains(content, "ForceCommand internal-sftp") {
			t.Errorf("expected ForceCommand internal-sftp in config, got:\n%s", content)
		}
	})

	t.Run("sshd -t failure rolls back match block", func(t *testing.T) {
		exec := &fakeCommandExecutor{
			combinedResult: map[string]fakeCommandResult{
				"sshd -t": {out: []byte("line 5: Bad configuration option: InvalidOption"), err: errors.New("exit status 1")},
			},
			lookups: map[string]bool{
				"setquota":   true,
				"php-fpm8.4": true,
			},
		}

		root := t.TempDir()
		sshdDir := filepath.Join(root, "etc", "ssh", "sshd_config.d")
		action := NewAccountCreateActionForTest(
			exec,
			filepath.Join(root, "home"),
			filepath.Join(root, "tmp"),
			filepath.Join(root, "etc", "php"),
			filepath.Join(root, "etc", "nginx", "sites-available"),
			filepath.Join(root, "etc", "nginx", "sites-enabled"),
			filepath.Join(root, "etc", "systemd", "system"),
		).WithSshdConfigDir(sshdDir)

		payload, _ := json.Marshal(AccountCreatePayload{
			Username:      "sftpbad1",
			PrimaryDomain: "sftpbad1.com",
			PHPVersion:    "8.4",
			StorageMB:     1024,
			Inodes:        50000,
		})

		res, err := action.Execute(context.Background(), payload, false)
		if err == nil {
			t.Fatalf("expected error from failed sshd -t, got nil")
		}
		if res.Success {
			t.Fatalf("expected failure result, got success")
		}
		if !strings.Contains(res.Error, "sshd -t validation failed") {
			t.Errorf("expected error to mention sshd -t validation failed, got: %s", res.Error)
		}

		confPath := filepath.Join(sshdDir, "hpanel-sftpbad1.conf")
		if _, err := os.Stat(confPath); !os.IsNotExist(err) {
			t.Errorf("expected config file to be rolled back/removed, but it exists: %v", err)
		}
	})
}

func TestSftpAccountCreateMatchBlockSuccessAndRollback(t *testing.T) {
	t.Run("sftp.account_create writes subuser match block and reloads", func(t *testing.T) {
		exec := &fakeCommandExecutor{
			combinedResult: map[string]fakeCommandResult{
				"sshd -t": {out: []byte("Syntax OK"), err: nil},
			},
		}

		root := t.TempDir()
		sshdDir := filepath.Join(root, "etc", "ssh", "sshd_config.d")
		action := NewSftpAccountCreateActionForTest(exec, sshdDir)

		payload, _ := json.Marshal(SftpAccountCreatePayload{
			ParentUser: "parent1",
			Subuser:    "parent1_sub1",
			Homedir:    "/home/parent1/public_html/dev",
			Password:   "SecretPass123!",
		})

		res, err := action.Execute(context.Background(), payload, false)
		if err != nil {
			t.Fatalf("unexpected error: %v", err)
		}
		if !res.Success {
			t.Fatalf("expected success, got error: %s", res.Error)
		}

		confPath := filepath.Join(sshdDir, "hpanel-subuser-parent1_sub1.conf")
		data, err := os.ReadFile(confPath)
		if err != nil {
			t.Fatalf("expected subuser sshd conf to exist: %v", err)
		}
		content := string(data)
		if !strings.Contains(content, "Match User parent1_sub1") {
			t.Errorf("expected Match User parent1_sub1 in config, got:\n%s", content)
		}
		if !strings.Contains(content, "ChrootDirectory /home/parent1/public_html/dev") {
			t.Errorf("expected ChrootDirectory in config, got:\n%s", content)
		}
	})

	t.Run("sftp.account_create rolls back subuser match block on sshd -t error", func(t *testing.T) {
		exec := &fakeCommandExecutor{
			combinedResult: map[string]fakeCommandResult{
				"sshd -t": {out: []byte("line 12: unknown keyword"), err: errors.New("exit status 1")},
			},
		}

		root := t.TempDir()
		sshdDir := filepath.Join(root, "etc", "ssh", "sshd_config.d")
		action := NewSftpAccountCreateActionForTest(exec, sshdDir)

		payload, _ := json.Marshal(SftpAccountCreatePayload{
			ParentUser: "parent2",
			Subuser:    "parent2_sub2",
			Homedir:    "/home/parent2/public_html/dev",
			Password:   "SecretPass123!",
		})

		res, err := action.Execute(context.Background(), payload, false)
		if err == nil {
			t.Fatalf("expected error from failed sshd -t, got nil")
		}
		if res.Success {
			t.Fatalf("expected failure result, got success")
		}

		confPath := filepath.Join(sshdDir, "hpanel-subuser-parent2_sub2.conf")
		if _, err := os.Stat(confPath); !os.IsNotExist(err) {
			t.Errorf("expected config file to be rolled back/removed, but it exists: %v", err)
		}
	})
}
