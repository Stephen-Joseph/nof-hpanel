package actions

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"
)

func TestGlobalSshdConfigGenerationAndCommenting(t *testing.T) {
	root := t.TempDir()
	sshDir := filepath.Join(root, "etc", "ssh")
	sshdDir := filepath.Join(sshDir, "sshd_config.d")
	_ = os.MkdirAll(sshdDir, 0755)

	mainConfigPath := filepath.Join(sshDir, "sshd_config")
	initialContent := `# Default sshd_config
Port 22
PasswordAuthentication no
X11Forwarding yes
KbdInteractiveAuthentication no
UsePAM yes
`
	if err := os.WriteFile(mainConfigPath, []byte(initialContent), 0644); err != nil {
		t.Fatalf("failed writing test sshd_config: %v", err)
	}

	exec := &fakeCommandExecutor{
		combinedResult: map[string]fakeCommandResult{
			"sshd -t": {out: []byte("Syntax OK"), err: nil},
		},
	}

	// 1. First run: should comment out conflicting lines and create 00-hpanel.conf
	err := EnsureGlobalSshdConfig(context.Background(), mainConfigPath, sshdDir, exec)
	if err != nil {
		t.Fatalf("EnsureGlobalSshdConfig failed: %v", err)
	}

	// Verify main config was modified
	data, err := os.ReadFile(mainConfigPath)
	if err != nil {
		t.Fatalf("failed reading main config: %v", err)
	}
	content := string(data)
	if strings.Contains(content, "\nPasswordAuthentication no") {
		t.Errorf("expected PasswordAuthentication no to be commented out, got:\n%s", content)
	}
	if strings.Contains(content, "\nKbdInteractiveAuthentication no") {
		t.Errorf("expected KbdInteractiveAuthentication no to be commented out, got:\n%s", content)
	}
	if !strings.Contains(content, "# PasswordAuthentication no (disabled by HPanel)") {
		t.Errorf("expected replacement comment for PasswordAuthentication, got:\n%s", content)
	}
	if !strings.Contains(content, "# KbdInteractiveAuthentication no (disabled by HPanel)") {
		t.Errorf("expected replacement comment for KbdInteractiveAuthentication, got:\n%s", content)
	}

	// Verify backup was created
	bakPath := mainConfigPath + ".hpanel.bak"
	bakData, err := os.ReadFile(bakPath)
	if err != nil {
		t.Fatalf("expected backup %s to exist: %v", bakPath, err)
	}
	if string(bakData) != initialContent {
		t.Errorf("expected backup to match initial content exactly")
	}

	// Verify 00-hpanel.conf was generated
	globalPath := filepath.Join(sshdDir, "00-hpanel.conf")
	globalData, err := os.ReadFile(globalPath)
	if err != nil {
		t.Fatalf("expected 00-hpanel.conf to exist: %v", err)
	}
	globalStr := string(globalData)
	if !strings.Contains(globalStr, "PasswordAuthentication yes") {
		t.Errorf("expected PasswordAuthentication yes in 00-hpanel.conf, got:\n%s", globalStr)
	}
	if !strings.Contains(globalStr, "KbdInteractiveAuthentication yes") {
		t.Errorf("expected KbdInteractiveAuthentication yes in 00-hpanel.conf, got:\n%s", globalStr)
	}
	if !strings.Contains(globalStr, "PubkeyAuthentication yes") {
		t.Errorf("expected PubkeyAuthentication yes in 00-hpanel.conf, got:\n%s", globalStr)
	}
	if !strings.Contains(globalStr, "Subsystem sftp") {
		t.Errorf("expected Subsystem sftp in 00-hpanel.conf, got:\n%s", globalStr)
	}

	// 2. Second run: idempotence test (no duplicate comments, no errors)
	err = EnsureGlobalSshdConfig(context.Background(), mainConfigPath, sshdDir, exec)
	if err != nil {
		t.Fatalf("subsequent EnsureGlobalSshdConfig call failed: %v", err)
	}
	data2, _ := os.ReadFile(mainConfigPath)
	if string(data2) != string(data) {
		t.Errorf("expected subsequent run to be strictly idempotent, got differences:\n%s", string(data2))
	}
}

func TestGlobalSshdConfigRollbackOnSshdFailure(t *testing.T) {
	root := t.TempDir()
	sshDir := filepath.Join(root, "etc", "ssh")
	sshdDir := filepath.Join(sshDir, "sshd_config.d")
	_ = os.MkdirAll(sshdDir, 0755)

	mainConfigPath := filepath.Join(sshDir, "sshd_config")
	initialContent := "Port 22\nPasswordAuthentication no\n"
	_ = os.WriteFile(mainConfigPath, []byte(initialContent), 0644)

	exec := &fakeCommandExecutor{
		combinedResult: map[string]fakeCommandResult{
			"sshd -t": {out: []byte("syntax error in configuration"), err: errors.New("exit status 1")},
		},
	}

	err := EnsureGlobalSshdConfig(context.Background(), mainConfigPath, sshdDir, exec)
	if err == nil {
		t.Fatalf("expected error from failed sshd -t, got nil")
	}

	// Assert main config was restored to initial content
	currentData, _ := os.ReadFile(mainConfigPath)
	if string(currentData) != initialContent {
		t.Errorf("expected main config to be restored on sshd -t failure, got:\n%s", string(currentData))
	}
}

func TestSftpEnableAndDisableIdempotenceAndNoChroot(t *testing.T) {
	root := t.TempDir()
	sshdDir := filepath.Join(root, "etc", "ssh", "sshd_config.d")
	_ = os.MkdirAll(sshdDir, 0755)

	exec := &fakeCommandExecutor{
		combinedResult: map[string]fakeCommandResult{
			"sshd -t": {out: []byte("Syntax OK"), err: nil},
		},
	}

	enableAction := NewSftpEnableActionForTest(exec, sshdDir)
	disableAction := NewSftpDisableActionForTest(exec, sshdDir)

	payload, _ := json.Marshal(SftpEnablePayload{
		Username: "custsftp1",
	})

	// 1. Enable SFTP
	res, err := enableAction.Execute(context.Background(), payload, false)
	if err != nil {
		t.Fatalf("unexpected error from sftp.enable: %v", err)
	}
	if !res.Success {
		t.Fatalf("expected success, got error: %s", res.Error)
	}

	confPath := filepath.Join(sshdDir, "50-hpanel-custsftp1.conf")
	data, err := os.ReadFile(confPath)
	if err != nil {
		t.Fatalf("expected 50-hpanel-custsftp1.conf to exist: %v", err)
	}
	content := string(data)

	if !strings.Contains(content, "Match User custsftp1") {
		t.Errorf("expected Match User custsftp1 in config, got:\n%s", content)
	}
	if regexp.MustCompile(`(?m)^[ \t]*ChrootDirectory\b`).MatchString(content) {
		t.Errorf("expected NO ChrootDirectory directive in 50-hpanel-custsftp1.conf, got:\n%s", content)
	}
	if !strings.Contains(content, "ForceCommand internal-sftp -u 022") {
		t.Errorf("expected ForceCommand internal-sftp -u 022 in config, got:\n%s", content)
	}
	if !strings.Contains(content, "PasswordAuthentication yes") {
		t.Errorf("expected PasswordAuthentication yes in config, got:\n%s", content)
	}

	// 2. Re-enable (idempotent run)
	res2, err2 := enableAction.Execute(context.Background(), payload, false)
	if err2 != nil || !res2.Success {
		t.Fatalf("expected idempotent re-run to succeed, got err=%v, res=%v", err2, res2)
	}

	// 3. Disable SFTP
	disablePayload, _ := json.Marshal(SftpDisablePayload{
		Username: "custsftp1",
	})
	resDis, errDis := disableAction.Execute(context.Background(), disablePayload, false)
	if errDis != nil || !resDis.Success {
		t.Fatalf("expected sftp.disable to succeed: err=%v, res=%v", errDis, resDis)
	}

	if _, statErr := os.Stat(confPath); !os.IsNotExist(statErr) {
		t.Errorf("expected 50-hpanel-custsftp1.conf to be deleted after disable")
	}

	// 4. Disable again (idempotent)
	resDis2, errDis2 := disableAction.Execute(context.Background(), disablePayload, false)
	if errDis2 != nil || !resDis2.Success {
		t.Fatalf("expected second sftp.disable to succeed idempotently: err=%v, res=%v", errDis2, resDis2)
	}
}

func TestSftpEnableRollbackOnSshdFailure(t *testing.T) {
	root := t.TempDir()
	sshdDir := filepath.Join(root, "etc", "ssh", "sshd_config.d")
	_ = os.MkdirAll(sshdDir, 0755)

	exec := &fakeCommandExecutor{
		combinedResult: map[string]fakeCommandResult{
			"sshd -t": {out: []byte("line 3: Directive invalid"), err: errors.New("exit status 1")},
		},
	}

	action := NewSftpEnableActionForTest(exec, sshdDir)
	payload, _ := json.Marshal(SftpEnablePayload{
		Username: "custbad1",
	})

	res, err := action.Execute(context.Background(), payload, false)
	if err == nil {
		t.Fatalf("expected error from failed sshd -t, got nil")
	}
	if res.Success {
		t.Fatalf("expected failure result, got success")
	}

	confPath := filepath.Join(sshdDir, "50-hpanel-custbad1.conf")
	if _, err := os.Stat(confPath); !os.IsNotExist(err) {
		t.Errorf("expected 50-hpanel-custbad1.conf to be rolled back on sshd failure")
	}
}

func TestSftpSetPasswordValidationAndNoLeak(t *testing.T) {
	exec := &fakeCommandExecutor{
		combinedResult: map[string]fakeCommandResult{},
	}
	action := NewSftpSetPasswordActionForTest(exec)

	// 1. Password under 12 characters rejected
	shortPayload, _ := json.Marshal(SftpSetPasswordPayload{
		Username: "cust01",
		Password: "shortpass12", // 11 characters
	})
	if err := action.Validate(shortPayload); err == nil {
		t.Fatalf("expected validation error for password < 12 characters, got nil")
	}

	// 2. Valid password (16 chars) accepted and executed via stdin
	secretPass := "ComplexSecretPass987!"
	validPayload, _ := json.Marshal(SftpSetPasswordPayload{
		Username: "cust01",
		Password: secretPass,
	})

	res, err := action.Execute(context.Background(), validPayload, false)
	if err != nil {
		t.Fatalf("unexpected error from sftp.set_password: %v", err)
	}
	if !res.Success {
		t.Fatalf("expected success, got error: %s", res.Error)
	}

	// Assert password was NEVER logged in ActionResult logs
	for _, l := range res.Logs {
		if strings.Contains(l, secretPass) {
			t.Errorf("SECURITY LEAK: Password found in execution log: %s", l)
		}
	}

	// Assert command executed was "chpasswd" without password in args
	for _, cmd := range exec.commands {
		if strings.Contains(cmd, secretPass) {
			t.Errorf("SECURITY LEAK: Password found in command arguments: %s", cmd)
		}
	}
}

func TestMigrateLegacySubuserDropins(t *testing.T) {
	root := t.TempDir()
	sshdDir := filepath.Join(root, "sshd_config.d")
	_ = os.MkdirAll(sshdDir, 0755)

	legacyConf := filepath.Join(sshdDir, "hpanel-subuser-user1_sub.conf")
	legacyContent := `Match User user1_sub
    ChrootDirectory /home/user1/public_html
    ForceCommand internal-sftp
    AllowTcpForwarding no
    X11Forwarding no
`
	_ = os.WriteFile(legacyConf, []byte(legacyContent), 0644)

	exec := &fakeCommandExecutor{
		combinedResult: map[string]fakeCommandResult{
			"sshd -t": {out: []byte("Syntax OK"), err: nil},
		},
	}

	err := MigrateLegacySubuserDropins(context.Background(), sshdDir, exec)
	if err != nil {
		t.Fatalf("MigrateLegacySubuserDropins failed: %v", err)
	}

	// Check backup was created
	bakData, err := os.ReadFile(legacyConf + ".bak")
	if err != nil {
		t.Fatalf("expected backup to exist: %v", err)
	}
	if string(bakData) != legacyContent {
		t.Errorf("expected backup to preserve original content with ChrootDirectory")
	}

	// Check ChrootDirectory line was deleted
	cleanData, err := os.ReadFile(legacyConf)
	if err != nil {
		t.Fatalf("expected cleaned file to exist: %v", err)
	}
	cleanStr := string(cleanData)
	if strings.Contains(cleanStr, "ChrootDirectory") {
		t.Errorf("expected ChrootDirectory to be stripped from legacy dropin, got:\n%s", cleanStr)
	}
	if !strings.Contains(cleanStr, "Match User user1_sub") {
		t.Errorf("expected Match User to remain intact, got:\n%s", cleanStr)
	}
}

func TestSshdEffectiveActionParsing(t *testing.T) {
	sshdSampleOutput := `
passwordauthentication yes
kbdinteractiveauthentication yes
pubkeyauthentication yes
forcecommand internal-sftp -u 022
chrootdirectory none
permitrootlogin prohibit-password
port 22
`
	exec := &fakeCommandExecutor{
		combinedResult: map[string]fakeCommandResult{
			"sshd -T -C user=cust01": {out: []byte(sshdSampleOutput), err: nil},
			"id -u cust01":           {out: []byte("1001\n"), err: nil},
		},
	}

	action := NewSshdEffectiveActionForTest(exec)
	payload, _ := json.Marshal(SshdEffectivePayload{
		Username: "cust01",
	})

	res, err := action.Execute(context.Background(), payload, false)
	if err != nil {
		t.Fatalf("unexpected error from sshd.effective: %v", err)
	}
	if !res.Success {
		t.Fatalf("expected success, got error: %s", res.Error)
	}

	data := res.Data
	if data["user_exists"] != true {
		t.Errorf("expected user_exists=true, got %v", data["user_exists"])
	}
	if data["password_authentication"] != "yes" {
		t.Errorf("expected password_authentication=yes, got %v", data["password_authentication"])
	}
	if data["force_command"] != "internal-sftp -u 022" {
		t.Errorf("expected force_command='internal-sftp -u 022', got %v", data["force_command"])
	}
	if data["chroot_directory"] != "none" {
		t.Errorf("expected chroot_directory='none', got %v", data["chroot_directory"])
	}
	if data["ready_for_sftp"] != true {
		t.Errorf("expected ready_for_sftp=true, got %v", data["ready_for_sftp"])
	}
}
