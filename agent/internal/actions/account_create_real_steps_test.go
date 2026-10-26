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

type fakeCommandResult struct {
	out []byte
	err error
}

type fakeCommandExecutor struct {
	runErrors      map[string]error
	combinedResult map[string]fakeCommandResult
	lookups        map[string]bool
	commands       []string
}

func (f *fakeCommandExecutor) Run(_ context.Context, name string, args ...string) error {
	f.commands = append(f.commands, f.key(name, args...))
	if err, ok := f.runErrors[f.key(name, args...)]; ok {
		return err
	}
	return nil
}

func (f *fakeCommandExecutor) CombinedOutput(_ context.Context, name string, args ...string) ([]byte, error) {
	if result, ok := f.combinedResult[f.key(name, args...)]; ok {
		return result.out, result.err
	}
	return []byte{}, nil
}

func (f *fakeCommandExecutor) LookPath(file string) (string, error) {
	if f.lookups[file] {
		return "/usr/bin/" + file, nil
	}

	return "", errors.New("not found")
}

func (f *fakeCommandExecutor) key(name string, args ...string) string {
	if len(args) == 0 {
		return name
	}
	return name + " " + strings.Join(args, " ")
}

func testPayload() []byte {
	payload, _ := json.Marshal(AccountCreatePayload{
		Username:      "cust01",
		PrimaryDomain: "cust01.example.com",
		PHPVersion:    "8.4",
		StorageMB:     10240,
		Inodes:        100000,
		PHPWorkers:    8,
		PHPMemoryMB:   512,
		CPUCores:      2,
		MemoryLimitMB: 1024,
	})

	return payload
}

func newTestAction(t *testing.T, exec *fakeCommandExecutor) *AccountCreateAction {
	t.Helper()
	root := t.TempDir()
	action := NewAccountCreateActionForTest(
		exec,
		filepath.Join(root, "home"),
		filepath.Join(root, "tmp"),
		filepath.Join(root, "etc", "php"),
		filepath.Join(root, "etc", "nginx", "sites-available"),
		filepath.Join(root, "etc", "nginx", "sites-enabled"),
		filepath.Join(root, "etc", "systemd", "system"),
	)

	return action
}

func TestAccountCreateExecuteRealRunSuccessAndIdempotent(t *testing.T) {
	exec := &fakeCommandExecutor{
		runErrors: map[string]error{
			"id -u cust01": errors.New("user not found"),
		},
		combinedResult: map[string]fakeCommandResult{},
		lookups: map[string]bool{
			"setquota":   true,
			"php-fpm8.4": true,
		},
	}
	action := newTestAction(t, exec)

	res, err := action.Execute(context.Background(), testPayload(), false)
	if err != nil {
		t.Fatalf("expected success, got error: %v", err)
	}
	if !res.Success {
		t.Fatalf("expected success=true, got error %q", res.Error)
	}

	homeDir := filepath.Join(action.homeBaseDir, "cust01")
	if _, err := os.Stat(filepath.Join(homeDir, "public_html", "index.html")); err != nil {
		t.Fatalf("expected default index file to exist: %v", err)
	}

	poolFile := filepath.Join(action.phpConfigBaseDir, "8.4", "fpm", "pool.d", "cust01.conf")
	if _, err := os.Stat(poolFile); err != nil {
		t.Fatalf("expected pool file to exist: %v", err)
	}

	vhostFile := filepath.Join(action.vhostAvailableDir, "cust01.example.com.conf")
	if _, err := os.Stat(vhostFile); err != nil {
		t.Fatalf("expected vhost file to exist: %v", err)
	}

	symlinkPath := filepath.Join(action.vhostEnabledDir, "cust01.example.com.conf")
	if _, err := os.Lstat(symlinkPath); err != nil {
		t.Fatalf("expected vhost symlink to exist: %v", err)
	}

	sliceFile := filepath.Join(action.systemdConfigDir, "hpanel-cust01.slice.d", "50-limits.conf")
	if _, err := os.Stat(sliceFile); err != nil {
		t.Fatalf("expected slice file to exist: %v", err)
	}

	// Verify no config temp files were left in action.tempDir
	if _, err := os.Stat(action.tempDir); !os.IsNotExist(err) {
		entries, _ := os.ReadDir(action.tempDir)
		if len(entries) > 0 {
			t.Fatalf("expected action.tempDir to have no files, found %d entries", len(entries))
		}
	}

	// Verify no leftover .tmp-* files in destination directories
	poolDir := filepath.Dir(poolFile)
	poolEntries, _ := os.ReadDir(poolDir)
	for _, entry := range poolEntries {
		if strings.HasPrefix(entry.Name(), ".") && strings.Contains(entry.Name(), ".tmp-") {
			t.Fatalf("leftover temp file in poolDir: %s", entry.Name())
		}
	}

	vhostEntries, _ := os.ReadDir(action.vhostAvailableDir)
	for _, entry := range vhostEntries {
		if strings.HasPrefix(entry.Name(), ".") && strings.Contains(entry.Name(), ".tmp-") {
			t.Fatalf("leftover temp file in vhostAvailableDir: %s", entry.Name())
		}
	}

	delete(exec.runErrors, "id -u cust01")
	res, err = action.Execute(context.Background(), testPayload(), false)
	if err != nil {
		t.Fatalf("expected idempotent rerun success, got error: %v", err)
	}
	if !res.Success {
		t.Fatalf("expected idempotent rerun success=true, got error %q", res.Error)
	}
}

func TestAccountCreateExecuteReturnsStepErrorOnCreateUserFailure(t *testing.T) {
	exec := &fakeCommandExecutor{
		runErrors: map[string]error{
			"id -u cust01": errors.New("user missing"),
		},
		combinedResult: map[string]fakeCommandResult{
			"useradd -m -d /home/cust01 -s /usr/sbin/nologin -c Hosting Account cust01 cust01": {
				out: []byte("permission denied"),
				err: errors.New("exit status 1"),
			},
		},
		lookups: map[string]bool{},
	}
	action := NewAccountCreateActionForTest(exec, "/home", t.TempDir(), t.TempDir(), t.TempDir(), t.TempDir(), t.TempDir())

	res, err := action.Execute(context.Background(), testPayload(), false)
	if err == nil {
		t.Fatal("expected error")
	}
	if res.Success {
		t.Fatal("expected success=false")
	}
	if !strings.Contains(res.Error, "step 'create_user' failed") {
		t.Fatalf("unexpected error message: %s", res.Error)
	}
}

func TestAccountCreateExecuteReturnsStepErrorOnQuotaFailure(t *testing.T) {
	exec := &fakeCommandExecutor{
		runErrors: map[string]error{
			"id -u cust01": nil,
		},
		combinedResult: map[string]fakeCommandResult{
			"setquota -u cust01 10485760 10485760 100000 100000 /": {
				err: errors.New("exit status 1"),
			},
		},
		lookups: map[string]bool{"setquota": true},
	}
	action := newTestAction(t, exec)

	res, err := action.Execute(context.Background(), testPayload(), false)
	if err == nil {
		t.Fatal("expected error")
	}
	if !strings.Contains(res.Error, "step 'quota' failed") {
		t.Fatalf("unexpected error message: %s", res.Error)
	}
}

func TestAccountCreateExecuteReturnsStepErrorOnFpmFailure(t *testing.T) {
	exec := &fakeCommandExecutor{
		runErrors: map[string]error{},
		combinedResult: map[string]fakeCommandResult{
			"php-fpm8.4 -t": {
				out: []byte("config test failed"),
				err: errors.New("exit status 1"),
			},
		},
		lookups: map[string]bool{"php-fpm8.4": true},
	}
	action := newTestAction(t, exec)

	res, err := action.Execute(context.Background(), testPayload(), false)
	if err == nil {
		t.Fatal("expected error")
	}
	if !strings.Contains(res.Error, "step 'php_pool' failed") {
		t.Fatalf("unexpected error message: %s", res.Error)
	}
}

func TestAccountCreateExecuteReturnsStepErrorOnVhostFailure(t *testing.T) {
	exec := &fakeCommandExecutor{
		runErrors: map[string]error{},
		combinedResult: map[string]fakeCommandResult{
			"nginx -t": {
				out: []byte("invalid vhost"),
				err: errors.New("exit status 1"),
			},
		},
		lookups: map[string]bool{"php-fpm8.4": true},
	}
	action := newTestAction(t, exec)

	res, err := action.Execute(context.Background(), testPayload(), false)
	if err == nil {
		t.Fatal("expected error")
	}
	if !strings.Contains(res.Error, "step 'vhost' failed") {
		t.Fatalf("unexpected error message: %s", res.Error)
	}
}

func TestAccountCreateExecuteReturnsStepErrorOnSystemdSliceFailure(t *testing.T) {
	exec := &fakeCommandExecutor{
		runErrors: map[string]error{
			"systemctl daemon-reload": errors.New("daemon-reload failed"),
		},
		combinedResult: map[string]fakeCommandResult{},
		lookups:        map[string]bool{"php-fpm8.4": true},
	}
	action := newTestAction(t, exec)

	res, err := action.Execute(context.Background(), testPayload(), false)
	if err == nil {
		t.Fatal("expected error")
	}
	if !strings.Contains(res.Error, "step 'systemd_slice' failed") {
		t.Fatalf("unexpected error message: %s", res.Error)
	}
}

func TestAccountCreateExecuteSkipsQuotaWhenUnsupported(t *testing.T) {
	testCases := []struct {
		name string
		out  []byte
		err  error
	}{
		{
			name: "quota not enabled",
			out:  []byte("setquota: quota not enabled on filesystem /"),
			err:  errors.New("exit status 1"),
		},
		{
			name: "Cannot find filesystem",
			out:  []byte("setquota: Cannot find filesystem to grant quota for [/dev/sda1]"),
			err:  errors.New("exit status 1"),
		},
		{
			name: "not supported",
			out:  []byte("setquota: filesystem does not support quotas (not supported)"),
			err:  errors.New("exit status 1"),
		},
		{
			name: "quotactl in output",
			out:  []byte("setquota: quotactl on /dev/root [/]: Function not implemented"),
			err:  errors.New("exit status 1"),
		},
		{
			name: "quotactl in err",
			out:  []byte{},
			err:  errors.New("quotactl syscall failed: operation not supported"),
		},
		{
			name: "Operation not permitted when quotaon is off",
			out:  []byte("setquota: Operation not permitted"),
			err:  errors.New("exit status 1"),
		},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			exec := &fakeCommandExecutor{
				runErrors: map[string]error{
					"id -u cust01": nil,
				},
				combinedResult: map[string]fakeCommandResult{
					"setquota -u cust01 10485760 10485760 100000 100000 /": {
						out: tc.out,
						err: tc.err,
					},
				},
				lookups: map[string]bool{
					"setquota":   true,
					"php-fpm8.4": true,
				},
			}
			action := newTestAction(t, exec)

			res, err := action.Execute(context.Background(), testPayload(), false)
			if err != nil {
				t.Fatalf("expected action to continue and succeed, got error: %v", err)
			}
			if !res.Success {
				t.Fatalf("expected res.Success=true, got error: %s", res.Error)
			}

			// Verify warning was logged
			hasWarning := false
			hasSkippedStep := false
			for _, l := range res.Logs {
				if strings.Contains(l, "warning: quota unsupported on this filesystem") {
					hasWarning = true
				}
				if strings.Contains(l, "step 'quota' skipped with reason: quota unsupported on this filesystem") {
					hasSkippedStep = true
				}
			}
			if !hasWarning {
				t.Errorf("expected warning log in res.Logs: %v", res.Logs)
			}
			if !hasSkippedStep {
				t.Errorf("expected skipped step log in res.Logs: %v", res.Logs)
			}

			if skipped, ok := res.Data["quota_skipped"].(bool); !ok || !skipped {
				t.Errorf("expected res.Data[quota_skipped] to be true, got %v", res.Data["quota_skipped"])
			}
			if reason, ok := res.Data["quota_skipped_reason"].(string); !ok || reason != "quota unsupported on this filesystem" {
				t.Errorf("expected res.Data[quota_skipped_reason] to be 'quota unsupported on this filesystem', got %v", res.Data["quota_skipped_reason"])
			}

			// Verify subsequent steps were executed
			homeDir := filepath.Join(action.homeBaseDir, "cust01")
			if _, err := os.Stat(filepath.Join(homeDir, "public_html", "index.html")); err != nil {
				t.Fatalf("expected default index file to exist: %v", err)
			}
			poolFile := filepath.Join(action.phpConfigBaseDir, "8.4", "fpm", "pool.d", "cust01.conf")
			if _, err := os.Stat(poolFile); err != nil {
				t.Fatalf("expected pool file to exist: %v", err)
			}
			vhostFile := filepath.Join(action.vhostAvailableDir, "cust01.example.com.conf")
			if _, err := os.Stat(vhostFile); err != nil {
				t.Fatalf("expected vhost file to exist: %v", err)
			}
		})
	}
}

func TestAccountCreateExecuteFailsOnOtherSetquotaErrors(t *testing.T) {
	testCases := []struct {
		name string
		out  []byte
		err  error
	}{
		{
			name: "permission denied",
			out:  []byte("setquota: permission denied"),
			err:  errors.New("exit status 1"),
		},
		{
			name: "disk I/O error",
			out:  []byte("setquota: I/O error writing quota record"),
			err:  errors.New("exit status 2"),
		},
		{
			name: "generic exit error without message",
			out:  []byte{},
			err:  errors.New("exit status 1"),
		},
	}

	for _, tc := range testCases {
		t.Run(tc.name, func(t *testing.T) {
			exec := &fakeCommandExecutor{
				runErrors: map[string]error{
					"id -u cust01": nil,
				},
				combinedResult: map[string]fakeCommandResult{
					"setquota -u cust01 10485760 10485760 100000 100000 /": {
						out: tc.out,
						err: tc.err,
					},
				},
				lookups: map[string]bool{
					"setquota":   true,
					"php-fpm8.4": true,
				},
			}
			action := newTestAction(t, exec)

			res, err := action.Execute(context.Background(), testPayload(), false)
			if err == nil {
				t.Fatal("expected error, got nil")
			}
			if res.Success {
				t.Fatal("expected res.Success=false, got true")
			}
			if !strings.Contains(res.Error, "step 'quota' failed") {
				t.Fatalf("expected res.Error to mention step 'quota' failed, got: %s", res.Error)
			}

			// Verify subsequent steps did NOT run
			poolFile := filepath.Join(action.phpConfigBaseDir, "8.4", "fpm", "pool.d", "cust01.conf")
			if _, err := os.Stat(poolFile); err == nil {
				t.Fatal("expected pool file to NOT exist when quota fails")
			}
		})
	}
}

func TestAccountCreateDirectoryAndFilePermissionsAndOwnership(t *testing.T) {
	exec := &fakeCommandExecutor{
		runErrors: map[string]error{
			"id -u cust01": nil, // user exists
		},
		combinedResult: map[string]fakeCommandResult{},
		lookups: map[string]bool{
			"setquota":   true,
			"php-fpm8.4": true,
		},
	}
	action := newTestAction(t, exec)
	homeDir := filepath.Join(action.homeBaseDir, "cust01")

	// Pre-create homeDir with non-standard permissions (0700)
	if err := os.MkdirAll(homeDir, 0700); err != nil {
		t.Fatalf("failed creating homeDir: %v", err)
	}
	_ = os.Chmod(homeDir, 0700)

	// Pre-create public_html with a subdirectory and file having restricted permissions (0700, 0600)
	subDir := filepath.Join(homeDir, "public_html", "assets", "css")
	if err := os.MkdirAll(subDir, 0700); err != nil {
		t.Fatalf("failed creating subDir: %v", err)
	}
	_ = os.Chmod(filepath.Dir(subDir), 0700)
	_ = os.Chmod(subDir, 0700)

	subFile := filepath.Join(subDir, "style.css")
	if err := os.WriteFile(subFile, []byte("body { color: #333; }"), 0600); err != nil {
		t.Fatalf("failed writing subFile: %v", err)
	}
	_ = os.Chmod(subFile, 0600)

	// Pre-create logs directory with non-standard permissions (0777)
	logsDir := filepath.Join(homeDir, "logs")
	if err := os.MkdirAll(logsDir, 0777); err != nil {
		t.Fatalf("failed creating logsDir: %v", err)
	}
	_ = os.Chmod(logsDir, 0777)

	// Pre-create backups and .ssh with non-standard permissions to test recursive repair
	backupsDir := filepath.Join(homeDir, "backups")
	if err := os.MkdirAll(backupsDir, 0700); err != nil {
		t.Fatalf("failed creating backupsDir: %v", err)
	}
	_ = os.Chmod(backupsDir, 0700)
	backupFile := filepath.Join(backupsDir, "archive.tar")
	if err := os.WriteFile(backupFile, []byte("data"), 0600); err != nil {
		t.Fatalf("failed writing backupFile: %v", err)
	}

	sshDir := filepath.Join(homeDir, ".ssh")
	if err := os.MkdirAll(sshDir, 0777); err != nil {
		t.Fatalf("failed creating sshDir: %v", err)
	}
	_ = os.Chmod(sshDir, 0777)
	sshKeyFile := filepath.Join(sshDir, "authorized_keys")
	if err := os.WriteFile(sshKeyFile, []byte("ssh-rsa test"), 0644); err != nil {
		t.Fatalf("failed writing sshKeyFile: %v", err)
	}

	// Execute account.create
	res, err := action.Execute(context.Background(), testPayload(), false)
	if err != nil {
		t.Fatalf("expected execute success, got error: %v", err)
	}
	if !res.Success {
		t.Fatalf("expected res.Success=true, got error: %q", res.Error)
	}

	// 1. Assert home directory mode is 0711
	fi, err := os.Stat(homeDir)
	if err != nil {
		t.Fatalf("stat homeDir failed: %v", err)
	}
	if perm := fi.Mode().Perm(); perm != 0711 {
		t.Errorf("expected homeDir mode 0711, got %04o", perm)
	}

	// 2. Assert public_html mode is 0755
	publicHtml := filepath.Join(homeDir, "public_html")
	fi, err = os.Stat(publicHtml)
	if err != nil {
		t.Fatalf("stat publicHtml failed: %v", err)
	}
	if perm := fi.Mode().Perm(); perm != 0755 {
		t.Errorf("expected public_html mode 0755, got %04o", perm)
	}

	// 3. Assert subdirectories inside public_html are 0755
	assetsDir := filepath.Join(publicHtml, "assets")
	fi, err = os.Stat(assetsDir)
	if err != nil {
		t.Fatalf("stat assetsDir failed: %v", err)
	}
	if perm := fi.Mode().Perm(); perm != 0755 {
		t.Errorf("expected public_html/assets mode 0755, got %04o", perm)
	}

	fi, err = os.Stat(subDir)
	if err != nil {
		t.Fatalf("stat subDir failed: %v", err)
	}
	if perm := fi.Mode().Perm(); perm != 0755 {
		t.Errorf("expected public_html/assets/css mode 0755, got %04o", perm)
	}

	// 4. Assert files inside public_html (default index.html and subFile) are 0644
	indexHtml := filepath.Join(publicHtml, "index.html")
	fi, err = os.Stat(indexHtml)
	if err != nil {
		t.Fatalf("stat indexHtml failed: %v", err)
	}
	if perm := fi.Mode().Perm(); perm != 0644 {
		t.Errorf("expected index.html mode 0644, got %04o", perm)
	}

	fi, err = os.Stat(subFile)
	if err != nil {
		t.Fatalf("stat subFile failed: %v", err)
	}
	if perm := fi.Mode().Perm(); perm != 0644 {
		t.Errorf("expected style.css mode 0644, got %04o", perm)
	}

	// 5. Assert logs is 0750
	fi, err = os.Stat(logsDir)
	if err != nil {
		t.Fatalf("stat logsDir failed: %v", err)
	}
	if perm := fi.Mode().Perm(); perm != 0750 {
		t.Errorf("expected logs mode 0750, got %04o", perm)
	}

	// 6. Assert tmp is 0750
	tmpDir := filepath.Join(homeDir, "tmp")
	fi, err = os.Stat(tmpDir)
	if err != nil {
		t.Fatalf("stat tmpDir failed: %v", err)
	}
	if perm := fi.Mode().Perm(); perm != 0750 {
		t.Errorf("expected tmp mode 0750, got %04o", perm)
	}

	// 7. Assert backups is 0750
	fi, err = os.Stat(backupsDir)
	if err != nil {
		t.Fatalf("stat backupsDir failed: %v", err)
	}
	if perm := fi.Mode().Perm(); perm != 0750 {
		t.Errorf("expected backups mode 0750, got %04o", perm)
	}

	// 8. Assert .ssh is 0700
	fi, err = os.Stat(sshDir)
	if err != nil {
		t.Fatalf("stat sshDir failed: %v", err)
	}
	if perm := fi.Mode().Perm(); perm != 0700 {
		t.Errorf("expected .ssh mode 0700, got %04o", perm)
	}

	// 9. Assert chown was called for account_user:account_user on each path
	expectedChowns := []string{
		"chown cust01:cust01 " + homeDir,
		"chown cust01:cust01 " + publicHtml,
		"chown cust01:cust01 " + assetsDir,
		"chown cust01:cust01 " + subDir,
		"chown cust01:cust01 " + indexHtml,
		"chown cust01:cust01 " + subFile,
		"chown cust01:cust01 " + logsDir,
		"chown cust01:cust01 " + tmpDir,
		"chown cust01:cust01 " + backupsDir,
		"chown cust01:cust01 " + sshDir,
	}

	for _, expected := range expectedChowns {
		found := false
		for _, cmd := range exec.commands {
			if cmd == expected {
				found = true
				break
			}
		}
		if !found {
			t.Errorf("expected command %q was not executed. Recorded commands:\n%s", expected, strings.Join(exec.commands, "\n"))
		}
	}
}

func TestAccountCreateLogsDirectoryCreatedBeforeVhost(t *testing.T) {
	exec := &fakeCommandExecutor{
		lookups: map[string]bool{
			"php-fpm8.4": true,
			"nginx":      true,
			"systemctl":  true,
		},
	}
	action := newTestAction(t, exec)
	homeDir := filepath.Join(action.homeBaseDir, "cust01")
	logsDir := filepath.Join(homeDir, "logs")

	// Ensure logsDir does not exist initially
	_ = os.RemoveAll(logsDir)

	// Call deployVhost directly
	payload := AccountCreatePayload{
		Username:      "cust01",
		PrimaryDomain: "cust01.example.com",
		PHPVersion:    "8.4",
	}
	err := action.deployVhost(context.Background(), payload)
	if err != nil {
		t.Fatalf("deployVhost failed: %v", err)
	}

	// Assert logsDir was created before or during deployVhost with mode 0750
	fi, err := os.Stat(logsDir)
	if err != nil {
		t.Fatalf("expected logsDir to exist after deployVhost, got err: %v", err)
	}
	if perm := fi.Mode().Perm(); perm != 0750 {
		t.Errorf("expected logsDir mode 0750, got %04o", perm)
	}

	// Verify chown was called on logsDir
	expectedChown := "chown cust01:cust01 " + logsDir
	found := false
	for _, cmd := range exec.commands {
		if cmd == expectedChown {
			found = true
			break
		}
	}
	if !found {
		t.Errorf("expected %q was not executed", expectedChown)
	}
}
