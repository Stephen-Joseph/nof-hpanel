package actions

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io/fs"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"regexp"
	"runtime"
	"strconv"
	"strings"
	"syscall"
	"time"
)

const maxArtisanOutputBytes = 4096

var allowedArtisanCommands = map[string][]string{
	"config:clear":       {"config:clear"},
	"config:cache":       {"config:cache"},
	"route:cache":        {"route:cache"},
	"view:cache":         {"view:cache"},
	"optimize:clear":     {"optimize:clear"},
	"migrate --force":    {"migrate", "--force"},
	"storage:link":       {"storage:link"},
	"key:generate --force": {"key:generate", "--force"},
}

var phpConstraintRegex = regexp.MustCompile(`8\.[0-9]+`)

// resolveHomeBase returns the cleaned and symlink-resolved home directory.
func resolveHomeBase(homeBase string) (string, error) {
	cleanHome := filepath.Clean(homeBase)
	if resolved, err := filepath.EvalSymlinks(cleanHome); err == nil {
		return resolved, nil
	}
	return cleanHome, nil
}

// verifyInsideHome checks that target (and its symlink target if it exists) is strictly inside homeDir.
func verifyInsideHome(homeDir, target string) (string, error) {
	resolvedHome, err := resolveHomeBase(homeDir)
	if err != nil {
		return "", err
	}
	cleanTarget := filepath.Clean(target)
	rel, err := filepath.Rel(resolvedHome, cleanTarget)
	if err != nil || strings.HasPrefix(rel, "..") {
		return "", fmt.Errorf("path '%s' is outside account home '%s'", target, homeDir)
	}
	if _, err := os.Lstat(cleanTarget); err == nil {
		resolvedTarget, err := filepath.EvalSymlinks(cleanTarget)
		if err != nil {
			return "", fmt.Errorf("cannot resolve '%s': %w", target, err)
		}
		relRes, err := filepath.Rel(resolvedHome, resolvedTarget)
		if err != nil || strings.HasPrefix(relRes, "..") {
			return "", fmt.Errorf("symlink '%s' escapes account home '%s'", target, homeDir)
		}
		return resolvedTarget, nil
	}
	return cleanTarget, nil
}

// findLaravelRoot locates the directory containing `artisan` under homeDir.
func findLaravelRoot(homeDir string) (string, error) {
	candidate := filepath.Join(homeDir, "public_html")
	if _, err := os.Stat(filepath.Join(candidate, "artisan")); err == nil {
		return verifyInsideHome(homeDir, candidate)
	}

	var found string
	_ = filepath.WalkDir(homeDir, func(path string, d fs.DirEntry, err error) error {
		if err != nil || found != "" {
			return nil
		}
		if d.Type()&os.ModeSymlink != 0 {
			if d.IsDir() {
				return filepath.SkipDir
			}
			return nil
		}
		rel, rErr := filepath.Rel(homeDir, path)
		if rErr == nil && rel != "." {
			depth := strings.Count(rel, string(os.PathSeparator)) + 1
			if d.IsDir() && (d.Name() == "vendor" || d.Name() == "node_modules" || depth > 3) {
				return filepath.SkipDir
			}
		}
		if !d.IsDir() && d.Name() == "artisan" {
			parent := filepath.Dir(path)
			if verified, vErr := verifyInsideHome(homeDir, parent); vErr == nil {
				found = verified
				return filepath.SkipAll
			}
		}
		return nil
	})

	if found == "" {
		return "", fmt.Errorf("no Laravel application (artisan file) found inside %s", homeDir)
	}
	return found, nil
}

// ============================================================================
// 1. laravel.fix_permissions
// ============================================================================

type LaravelDomainPayload struct {
	Domain   string `json:"domain"`
	Username string `json:"username"`
}

type LaravelFixPermissionsAction struct {
	homeRoot string
}

func NewLaravelFixPermissionsAction() *LaravelFixPermissionsAction {
	return &LaravelFixPermissionsAction{homeRoot: "/home"}
}

func NewLaravelFixPermissionsActionForTest(homeRoot string) *LaravelFixPermissionsAction {
	return &LaravelFixPermissionsAction{homeRoot: homeRoot}
}

func (a *LaravelFixPermissionsAction) Name() string {
	return "laravel.fix_permissions"
}

func (a *LaravelFixPermissionsAction) Validate(payload []byte) error {
	var p LaravelDomainPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateDomain(p.Domain); err != nil {
		return err
	}
	return ValidateLinuxUsername(p.Username)
}

func (a *LaravelFixPermissionsAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p LaravelDomainPayload
	_ = json.Unmarshal(payload, &p)

	homeDir := filepath.Join(a.homeRoot, p.Username)
	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"domain":   p.Domain,
				"username": p.Username,
				"dry_run":  true,
			},
			Logs: []string{fmt.Sprintf("[dry-run] fixed Laravel permissions for %s", p.Domain)},
		}, nil
	}

	laravelRoot, err := findLaravelRoot(homeDir)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	wwwDataGid := -1
	if grp, err := user.LookupGroup("www-data"); err == nil {
		if gid, pErr := strconv.Atoi(grp.Gid); pErr == nil {
			wwwDataGid = gid
		}
	}

	// Ensure required storage subdirectories exist
	requiredDirs := []string{
		filepath.Join(laravelRoot, "storage"),
		filepath.Join(laravelRoot, "storage", "framework"),
		filepath.Join(laravelRoot, "storage", "framework", "cache"),
		filepath.Join(laravelRoot, "storage", "framework", "sessions"),
		filepath.Join(laravelRoot, "storage", "framework", "views"),
		filepath.Join(laravelRoot, "storage", "logs"),
		filepath.Join(laravelRoot, "bootstrap", "cache"),
	}
	for _, dir := range requiredDirs {
		safeDir, err := verifyInsideHome(homeDir, dir)
		if err != nil {
			return &ActionResult{Success: false, Error: err.Error()}, err
		}
		if err := os.MkdirAll(safeDir, 0775); err != nil {
			return &ActionResult{Success: false, Error: fmt.Sprintf("creating %s: %v", safeDir, err)}, err
		}
	}

	// Set 0775 on dirs, 0664 on files, and group www-data inside storage and bootstrap/cache
	writableRoots := []string{
		filepath.Join(laravelRoot, "storage"),
		filepath.Join(laravelRoot, "bootstrap", "cache"),
	}
	for _, root := range writableRoots {
		safeRoot, err := verifyInsideHome(homeDir, root)
		if err != nil {
			return &ActionResult{Success: false, Error: err.Error()}, err
		}
		walkErr := filepath.WalkDir(safeRoot, func(path string, d fs.DirEntry, wErr error) error {
			if wErr != nil {
				return wErr
			}
			// Reject any symlink inside writable paths
			if d.Type()&os.ModeSymlink != 0 {
				if _, err := verifyInsideHome(homeDir, path); err != nil {
					return err
				}
				return nil
			}
			if _, err := verifyInsideHome(homeDir, path); err != nil {
				return err
			}
			if d.IsDir() {
				_ = os.Chmod(path, 0775)
			} else {
				_ = os.Chmod(path, 0664)
			}
			if wwwDataGid >= 0 && runtime.GOOS == "linux" {
				_ = os.Chown(path, -1, wwwDataGid)
			}
			return nil
		})
		if walkErr != nil {
			return &ActionResult{Success: false, Error: walkErr.Error()}, walkErr
		}
	}

	// Set .env to 0640 group www-data if present
	envPath := filepath.Join(laravelRoot, ".env")
	if _, err := os.Lstat(envPath); err == nil {
		safeEnv, err := verifyInsideHome(homeDir, envPath)
		if err != nil {
			return &ActionResult{Success: false, Error: err.Error()}, err
		}
		_ = os.Chmod(safeEnv, 0640)
		if wwwDataGid >= 0 && runtime.GOOS == "linux" {
			_ = os.Chown(safeEnv, -1, wwwDataGid)
		}
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"domain":       p.Domain,
			"laravel_root": laravelRoot,
		},
		Logs: []string{fmt.Sprintf("fixed Laravel permissions in %s", laravelRoot)},
	}, nil
}

// ============================================================================
// 2. laravel.artisan
// ============================================================================

type LaravelArtisanPayload struct {
	Domain     string `json:"domain"`
	Username   string `json:"username"`
	PhpVersion string `json:"php_version"`
	Command    string `json:"command"`
}

type LaravelArtisanAction struct {
	homeRoot string
}

func NewLaravelArtisanAction() *LaravelArtisanAction {
	return &LaravelArtisanAction{homeRoot: "/home"}
}

func NewLaravelArtisanActionForTest(homeRoot string) *LaravelArtisanAction {
	return &LaravelArtisanAction{homeRoot: homeRoot}
}

func (a *LaravelArtisanAction) Name() string {
	return "laravel.artisan"
}

func (a *LaravelArtisanAction) Validate(payload []byte) error {
	var p LaravelArtisanPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateDomain(p.Domain); err != nil {
		return err
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if p.PhpVersion != "" {
		if err := ValidateSupportedPHPVersion(p.PhpVersion); err != nil {
			return err
		}
	}
	if strings.ContainsAny(p.Command, ";&|$`\n\r") {
		return errors.New("forbidden shell metacharacters in artisan command")
	}
	if _, ok := allowedArtisanCommands[strings.TrimSpace(p.Command)]; !ok {
		return fmt.Errorf("artisan command '%s' is not on the allow-list", p.Command)
	}
	return nil
}

func (a *LaravelArtisanAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p LaravelArtisanPayload
	_ = json.Unmarshal(payload, &p)

	if p.PhpVersion == "" {
		p.PhpVersion = "8.3"
	}
	cmdArgs := allowedArtisanCommands[strings.TrimSpace(p.Command)]

	if dryRun || runtime.GOOS != "linux" {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"domain":  p.Domain,
				"command": p.Command,
				"output":  fmt.Sprintf("[dry-run] php%s artisan %s", p.PhpVersion, strings.Join(cmdArgs, " ")),
				"dry_run": dryRun,
			},
		}, nil
	}

	homeDir := filepath.Join(a.homeRoot, p.Username)
	laravelRoot, err := findLaravelRoot(homeDir)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	phpBin := fmt.Sprintf("/usr/bin/php%s", p.PhpVersion)
	args := append([]string{"artisan"}, cmdArgs...)

	runCtx, cancel := context.WithTimeout(ctx, 120*time.Second)
	defer cancel()

	cmd := exec.CommandContext(runCtx, phpBin, args...)
	cmd.Dir = laravelRoot

	if u, uErr := user.Lookup(p.Username); uErr == nil {
		uid, _ := strconv.Atoi(u.Uid)
		gid, _ := strconv.Atoi(u.Gid)
		cmd.SysProcAttr = &syscall.SysProcAttr{
			Credential: &syscall.Credential{
				Uid: uint32(uid),
				Gid: uint32(gid),
			},
		}
	}

	outBytes, err := cmd.CombinedOutput()
	output := string(outBytes)
	if len(output) > maxArtisanOutputBytes {
		output = output[:maxArtisanOutputBytes] + "\n... (truncated to 4 KB)"
	}

	if err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("artisan command failed: %v", err),
			Data: map[string]any{
				"domain":  p.Domain,
				"command": p.Command,
				"output":  output,
			},
		}, err
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"domain":       p.Domain,
			"command":      p.Command,
			"laravel_root": laravelRoot,
			"output":       output,
		},
	}, nil
}

// ============================================================================
// 3. laravel.detect
// ============================================================================

type LaravelDetectAction struct {
	homeRoot string
}

func NewLaravelDetectAction() *LaravelDetectAction {
	return &LaravelDetectAction{homeRoot: "/home"}
}

func NewLaravelDetectActionForTest(homeRoot string) *LaravelDetectAction {
	return &LaravelDetectAction{homeRoot: homeRoot}
}

func (a *LaravelDetectAction) Name() string {
	return "laravel.detect"
}

func (a *LaravelDetectAction) Validate(payload []byte) error {
	var p LaravelDomainPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateDomain(p.Domain); err != nil {
		return err
	}
	return ValidateLinuxUsername(p.Username)
}

func (a *LaravelDetectAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p LaravelDomainPayload
	_ = json.Unmarshal(payload, &p)

	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"detected":        false,
				"min_php_version": "",
				"dry_run":         true,
			},
		}, nil
	}

	homeDir := filepath.Join(a.homeRoot, p.Username)
	laravelRoot, err := findLaravelRoot(homeDir)
	if err != nil {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"detected":        false,
				"min_php_version": "",
			},
		}, nil
	}

	minVersion := ""

	// Check composer.json require.php
	if data, err := os.ReadFile(filepath.Join(laravelRoot, "composer.json")); err == nil {
		var cj struct {
			Require map[string]string `json:"require"`
		}
		if json.Unmarshal(data, &cj) == nil {
			if phpReq, ok := cj.Require["php"]; ok {
				minVersion = parseMinPhpVersion(phpReq)
			}
		}
	}

	// Check composer.lock platform.php (takes precedence if higher)
	if data, err := os.ReadFile(filepath.Join(laravelRoot, "composer.lock")); err == nil {
		var cl struct {
			Platform map[string]string `json:"platform"`
		}
		if json.Unmarshal(data, &cl) == nil {
			if platPhp, ok := cl.Platform["php"]; ok {
				platMin := parseMinPhpVersion(platPhp)
				if comparePhpMinor(platMin, minVersion) > 0 {
					minVersion = platMin
				}
			}
		}
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"detected":        true,
			"laravel_root":    laravelRoot,
			"min_php_version": minVersion,
		},
	}, nil
}

func parseMinPhpVersion(constraint string) string {
	matches := phpConstraintRegex.FindAllString(constraint, -1)
	if len(matches) == 0 {
		return ""
	}
	// Return lowest 8.x in constraint (e.g. "^8.2 || ^8.3" -> "8.2")
	best := matches[0]
	for _, m := range matches[1:] {
		if comparePhpMinor(m, best) < 0 {
			best = m
		}
	}
	return best
}

func comparePhpMinor(a, b string) int {
	if a == b {
		return 0
	}
	if a == "" {
		return -1
	}
	if b == "" {
		return 1
	}
	pa := strings.Split(a, ".")
	pb := strings.Split(b, ".")
	for i := 0; i < 2 && i < len(pa) && i < len(pb); i++ {
		va, _ := strconv.Atoi(pa[i])
		vb, _ := strconv.Atoi(pb[i])
		if va < vb {
			return -1
		}
		if va > vb {
			return 1
		}
	}
	return 0
}
