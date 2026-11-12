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

type fakePhpSetExecutor struct {
	fpmTestErr  error
	fpmTestOut  string
	nginxErr    error
	nginxOut    string
	socketDir   string
	username    string
	version     string
	createSock  bool
}

func (f *fakePhpSetExecutor) LookPath(file string) (string, error) {
	return "/usr/sbin/" + file, nil
}

func (f *fakePhpSetExecutor) Run(ctx context.Context, name string, args ...string) error {
	if name == "systemctl" && len(args) >= 2 && args[0] == "reload" && strings.HasPrefix(args[1], "php") {
		if f.createSock {
			_ = os.MkdirAll(f.socketDir, 0755)
			_ = os.WriteFile(filepath.Join(f.socketDir, "php"+f.version+"-fpm-"+f.username+".sock"), []byte{}, 0660)
		}
	}
	return nil
}

func (f *fakePhpSetExecutor) CombinedOutput(ctx context.Context, name string, args ...string) ([]byte, error) {
	if strings.HasPrefix(name, "php-fpm") && len(args) >= 1 && args[0] == "-t" {
		return []byte(f.fpmTestOut), f.fpmTestErr
	}
	if name == "nginx" && len(args) >= 1 && args[0] == "-t" {
		return []byte(f.nginxOut), f.nginxErr
	}
	return nil, nil
}

func TestPoolTemplateSocketAndOpenBasedir(t *testing.T) {
	rendered, err := RenderPhpPoolConfig(PhpPoolTemplateData{
		Username:    "alice",
		Version:     "8.4",
		MaxChildren: 5,
	})
	if err != nil {
		t.Fatalf("render pool failed: %v", err)
	}
	expectedSocket := PhpFpmSocketPath("8.4", "alice")
	if !strings.Contains(rendered, "listen = "+expectedSocket) {
		t.Errorf("expected pool to listen on %s", expectedSocket)
	}
	if !strings.Contains(rendered, "open_basedir] = /home/alice/:/tmp/:/usr/share/php/:/var/lib/php/sessions/") {
		t.Errorf("expected open_basedir to cover /home/alice/ and /usr/share/php/")
	}
	if strings.Contains(rendered, "proc_open") {
		t.Errorf("default disable_functions must not include proc_open")
	}
	if !strings.Contains(rendered, "request_terminate_timeout = 300s") {
		t.Errorf("expected request_terminate_timeout = 300s")
	}
}

func TestLaravelVhostRootAndRealpath(t *testing.T) {
	rendered, err := RenderVhostConfig(VhostTemplateData{
		Username:            "alice",
		Domain:              "laravel.example.com",
		DocumentRoot:        "/home/alice/public_html/public",
		AppProfile:          "laravel",
		PHPVersion:          "8.4",
		IsLaravelPublicRoot: true,
	})
	if err != nil {
		t.Fatalf("render laravel vhost failed: %v", err)
	}
	if !strings.Contains(rendered, "fastcgi_pass unix:"+PhpFpmSocketPath("8.4", "alice")+";") {
		t.Errorf("expected vhost fastcgi_pass to match PhpFpmSocketPath")
	}
	if !strings.Contains(rendered, "fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;") {
		t.Errorf("expected $realpath_root SCRIPT_FILENAME")
	}
	if !strings.Contains(rendered, "fastcgi_param DOCUMENT_ROOT $realpath_root;") {
		t.Errorf("expected $realpath_root DOCUMENT_ROOT")
	}
	if !strings.Contains(rendered, "include snippets/fastcgi-php.conf;") {
		t.Errorf("expected snippets/fastcgi-php.conf")
	}
	if !strings.Contains(rendered, ManagedRegionHeader) || !strings.Contains(rendered, CustomRegionStart) {
		t.Errorf("expected HPANEL MANAGED and CUSTOM markers in vhost")
	}
}

func TestPhpSetVersionSuccessAndRollbackOnFpmFailure(t *testing.T) {
	poolBase := t.TempDir()
	availDir := t.TempDir()
	enabledDir := t.TempDir()
	socketDir := t.TempDir()

	// 1. Rollback when php-fpm -t fails
	failExec := &fakePhpSetExecutor{
		fpmTestErr: errors.New("exit 1"),
		fpmTestOut: "ERROR: invalid pool config",
		socketDir:  socketDir,
		username:   "alice",
		version:    "8.4",
	}
	actFail := NewPhpSetVersionActionForTest(failExec, poolBase, availDir, enabledDir, socketDir)
	payload, _ := json.Marshal(PhpSetVersionPayload{
		Domain:       "app.example.com",
		Username:     "alice",
		Version:      "8.4",
		DocumentRoot: "/home/alice/public_html",
		AppProfile:   "laravel",
	})
	res, err := actFail.Execute(context.Background(), payload, false)
	if err == nil || res.Success {
		t.Fatalf("expected php.set_version to fail on php-fpm -t failure")
	}
	poolFile := filepath.Join(poolBase, "8.4", "fpm", "pool.d", "alice.conf")
	if _, statErr := os.Stat(poolFile); !os.IsNotExist(statErr) {
		t.Fatalf("expected pool file %s to be rolled back", poolFile)
	}

	// 2. Success creates pool and updates vhost
	okExec := &fakePhpSetExecutor{
		socketDir:  socketDir,
		username:   "alice",
		version:    "8.4",
		createSock: true,
	}
	actOk := NewPhpSetVersionActionForTest(okExec, poolBase, availDir, enabledDir, socketDir)
	resOk, err := actOk.Execute(context.Background(), payload, false)
	if err != nil || !resOk.Success {
		t.Fatalf("expected php.set_version to succeed, got err=%v", err)
	}
	if _, statErr := os.Stat(poolFile); statErr != nil {
		t.Fatalf("expected pool file %s to exist", poolFile)
	}
	vhostBytes, err := os.ReadFile(filepath.Join(availDir, "app.example.com.conf"))
	if err != nil {
		t.Fatalf("expected vhost file to exist: %v", err)
	}
	if !strings.Contains(string(vhostBytes), PhpFpmSocketPath("8.4", "alice")) {
		t.Errorf("expected vhost to point to 8.4 socket")
	}
}

func TestLaravelArtisanValidationRejectsDisallowedAndMetacharacters(t *testing.T) {
	act := NewLaravelArtisanActionForTest(t.TempDir())

	badCommands := []string{
		"tinker",
		"db:wipe",
		"migrate --force; id",
		"optimize:clear && whoami",
		"config:clear | cat /etc/passwd",
		"config:clear $(id)",
		"config:clear `id`",
		"config:clear\nid",
	}
	for _, cmd := range badCommands {
		p, _ := json.Marshal(LaravelArtisanPayload{
			Domain:     "app.example.com",
			Username:   "alice",
			PhpVersion: "8.4",
			Command:    cmd,
		})
		if err := act.Validate(p); err == nil {
			t.Errorf("expected artisan command %q to be rejected", cmd)
		}
	}

	goodPayload, _ := json.Marshal(LaravelArtisanPayload{
		Domain:     "app.example.com",
		Username:   "alice",
		PhpVersion: "8.4",
		Command:    "optimize:clear",
	})
	if err := act.Validate(goodPayload); err != nil {
		t.Errorf("expected optimize:clear to be accepted, got %v", err)
	}
}

func TestLaravelFixPermissionsRefusesOutsideAndSymlinkEscapes(t *testing.T) {
	homeRoot := t.TempDir()
	outsideDir := t.TempDir()
	aliceHome := filepath.Join(homeRoot, "alice")
	appDir := filepath.Join(aliceHome, "public_html")
	_ = os.MkdirAll(filepath.Join(appDir, "storage", "logs"), 0755)
	_ = os.MkdirAll(filepath.Join(appDir, "bootstrap", "cache"), 0755)
	_ = os.WriteFile(filepath.Join(appDir, "artisan"), []byte("<?php"), 0644)

	// verifyInsideHome must refuse outside path
	if _, err := verifyInsideHome(aliceHome, outsideDir); err == nil {
		t.Errorf("expected verifyInsideHome to reject path outside home")
	}

	// Symlink inside storage pointing outside home must be rejected
	escapeLink := filepath.Join(appDir, "storage", "escape")
	if err := os.Symlink(outsideDir, escapeLink); err == nil {
		act := NewLaravelFixPermissionsActionForTest(homeRoot)
		p, _ := json.Marshal(LaravelDomainPayload{Domain: "app.example.com", Username: "alice"})
		if _, err := act.Execute(context.Background(), p, false); err == nil {
			t.Errorf("expected laravel.fix_permissions to reject symlink escaping home")
		}
	}
}
