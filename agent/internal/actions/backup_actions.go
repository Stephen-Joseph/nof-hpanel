package actions

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

type OffsiteConfig struct {
	Type            string `json:"type,omitempty"` // "s3" or "sftp"
	RepositoryURL   string `json:"repository_url,omitempty"`
	AccessKeyID     string `json:"access_key_id,omitempty"`
	SecretAccessKey string `json:"secret_access_key,omitempty"`
}

// -------------------------------------------------------------
// Action: backup.create
// -------------------------------------------------------------

type BackupCreatePayload struct {
	Username       string         `json:"username"`
	BackupType     string         `json:"backup_type,omitempty"` // "manual" or "scheduled"
	RepoPath       string         `json:"repo_path,omitempty"`
	OffsiteConfig  *OffsiteConfig `json:"offsite_config,omitempty"`
	Databases      []string       `json:"databases,omitempty"`
	RetentionCount int            `json:"retention_count,omitempty"`
}

type BackupCreateAction struct{}

func NewBackupCreateAction() *BackupCreateAction {
	return &BackupCreateAction{}
}

func (a *BackupCreateAction) Name() string {
	return "backup.create"
}

func (a *BackupCreateAction) Validate(payload []byte) error {
	var p BackupCreatePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}
	if !reLinuxUser.MatchString(p.Username) {
		return fmt.Errorf("invalid username: '%s'", p.Username)
	}
	return nil
}

func (a *BackupCreateAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p BackupCreatePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if !reLinuxUser.MatchString(p.Username) {
		return &ActionResult{Success: false, Error: "invalid username"}, fmt.Errorf("invalid username")
	}

	bType := p.BackupType
	if bType == "" {
		bType = "manual"
	}

	repo := p.RepoPath
	if repo == "" {
		repo = fmt.Sprintf("/var/backups/hpanel/%s", p.Username)
	}

	logs := []string{fmt.Sprintf("initiating restic backup for user '%s' (type: %s, repo: %s)", p.Username, bType, repo)}

	if dryRun || runtime.GOOS != "linux" {
		randomBytes := make([]byte, 8)
		_, _ = rand.Read(randomBytes)
		simulatedSnapshotID := hex.EncodeToString(randomBytes)

		logs = append(logs,
			fmt.Sprintf("[dry-run] dumped %d databases to staging", len(p.Databases)),
			fmt.Sprintf("[dry-run] created restic snapshot %s", simulatedSnapshotID),
		)

		if p.RetentionCount > 0 && bType == "scheduled" {
			logs = append(logs, fmt.Sprintf("[dry-run] pruned snapshots keeping last %d", p.RetentionCount))
		}

		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"snapshot_id":        simulatedSnapshotID,
				"backup_type":        bType,
				"bytes_added":        15728640, // 15MB
				"file_count":         142,
				"duration_seconds":   2.5,
				"databases_included": p.Databases,
				"dry_run":            true,
			},
			Logs: logs,
		}, nil
	}

	startTime := time.Now()

	// Step 1: Dump databases into staging directory inside /home/<user>/tmp/backup_dbs
	stagingDir := fmt.Sprintf("/home/%s/tmp/backup_dbs", p.Username)
	_ = os.MkdirAll(stagingDir, 0700)
	defer os.RemoveAll(stagingDir)

	cnfPath := "/etc/mysql/debian.cnf"
	for _, db := range p.Databases {
		if !strings.HasPrefix(db, p.Username+"_") {
			continue
		}
		dumpFile := filepath.Join(stagingDir, fmt.Sprintf("%s.sql", db))
		outFile, err := os.Create(dumpFile)
		if err == nil {
			var dumpCmd *exec.Cmd
			if _, statErr := os.Stat(cnfPath); statErr == nil {
				dumpCmd = exec.CommandContext(ctx, "mysqldump", "--defaults-file="+cnfPath, "--single-transaction", "--quick", db)
			} else {
				dumpCmd = exec.CommandContext(ctx, "mysqldump", "--single-transaction", "--quick", db)
			}
			dumpCmd.Stdout = outFile
			_ = dumpCmd.Run()
			outFile.Close()
			logs = append(logs, fmt.Sprintf("staged database dump for '%s'", db))
		}
	}

	// Step 2: Initialize restic repo if it doesn't exist
	repoPassword := fmt.Sprintf("hpanel-sec-%s", p.Username)
	env := append(os.Environ(), fmt.Sprintf("RESTIC_PASSWORD=%s", repoPassword))

	if p.OffsiteConfig != nil && p.OffsiteConfig.RepositoryURL != "" {
		repo = p.OffsiteConfig.RepositoryURL
		if p.OffsiteConfig.AccessKeyID != "" {
			env = append(env, fmt.Sprintf("AWS_ACCESS_KEY_ID=%s", p.OffsiteConfig.AccessKeyID))
		}
		if p.OffsiteConfig.SecretAccessKey != "" {
			env = append(env, fmt.Sprintf("AWS_SECRET_ACCESS_KEY=%s", p.OffsiteConfig.SecretAccessKey))
		}
	} else {
		_ = os.MkdirAll(repo, 0700)
	}

	initCmd := exec.CommandContext(ctx, "restic", "-r", repo, "init")
	initCmd.Env = env
	_ = initCmd.Run() // Ignore error if already initialized

	// Step 3: Run restic backup
	homeDir := fmt.Sprintf("/home/%s", p.Username)
	backupCmd := exec.CommandContext(ctx, "restic", "-r", repo, "backup", homeDir,
		"--exclude", fmt.Sprintf("/home/%s/logs", p.Username),
		"--exclude", fmt.Sprintf("/home/%s/.cache", p.Username),
		"--tag", bType,
		"--json",
	)
	backupCmd.Env = env
	var stdout, stderr bytes.Buffer
	backupCmd.Stdout = &stdout
	backupCmd.Stderr = &stderr

	if err := backupCmd.Run(); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("restic backup failed: %v: %s", err, strings.TrimSpace(stderr.String())),
			Logs:    logs,
		}, err
	}

	duration := time.Since(startTime).Seconds()
	snapshotID := "unknown"
	var bytesAdded int64 = 0
	var fileCount int = 0

	// Parse JSON lines output from restic to extract snapshot_id
	lines := strings.Split(stdout.String(), "\n")
	for _, line := range lines {
		var summary struct {
			MessageType string `json:"message_type"`
			SnapshotID  string `json:"snapshot_id"`
			DataAdded   int64  `json:"data_added"`
			FilesNew    int    `json:"files_new"`
		}
		if err := json.Unmarshal([]byte(line), &summary); err == nil && summary.SnapshotID != "" {
			snapshotID = summary.SnapshotID
			bytesAdded = summary.DataAdded
			fileCount = summary.FilesNew
		}
	}

	logs = append(logs, fmt.Sprintf("restic snapshot %s created (%d files, %d bytes added, %.2fs)", snapshotID, fileCount, bytesAdded, duration))

	// Step 4: Prune older snapshots if retention count is configured
	if p.RetentionCount > 0 && bType == "scheduled" {
		forgetCmd := exec.CommandContext(ctx, "restic", "-r", repo, "forget",
			"--keep-last", fmt.Sprintf("%d", p.RetentionCount),
			"--prune",
			"--tag", "scheduled",
		)
		forgetCmd.Env = env
		_ = forgetCmd.Run()
		logs = append(logs, fmt.Sprintf("pruned automated snapshots keeping last %d", p.RetentionCount))
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"snapshot_id":        snapshotID,
			"backup_type":        bType,
			"bytes_added":        bytesAdded,
			"file_count":         fileCount,
			"duration_seconds":   duration,
			"databases_included": p.Databases,
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// Action: backup.list
// -------------------------------------------------------------

type BackupListPayload struct {
	Username string `json:"username"`
	RepoPath string `json:"repo_path,omitempty"`
}

type SnapshotItem struct {
	ID        string    `json:"id"`
	ShortID   string    `json:"short_id"`
	Time      time.Time `json:"time"`
	Tags      []string  `json:"tags"`
	Paths     []string  `json:"paths"`
	Hostname  string    `json:"hostname"`
	Username  string    `json:"username"`
	SizeBytes int64     `json:"size_bytes,omitempty"`
}

type BackupListAction struct{}

func NewBackupListAction() *BackupListAction {
	return &BackupListAction{}
}

func (a *BackupListAction) Name() string {
	return "backup.list"
}

func (a *BackupListAction) Validate(payload []byte) error {
	var p BackupListPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}
	if !reLinuxUser.MatchString(p.Username) {
		return fmt.Errorf("invalid username: '%s'", p.Username)
	}
	return nil
}

func (a *BackupListAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p BackupListPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	repo := p.RepoPath
	if repo == "" {
		repo = fmt.Sprintf("/var/backups/hpanel/%s", p.Username)
	}

	logs := []string{fmt.Sprintf("listing restic snapshots for user '%s'", p.Username)}

	if dryRun || runtime.GOOS != "linux" {
		mockSnapshots := []SnapshotItem{
			{
				ID:        "a1b2c3d4e5f67890123456789abcdef012345678",
				ShortID:   "a1b2c3d4",
				Time:      time.Now().Add(-2 * time.Hour),
				Tags:      []string{"manual"},
				Paths:     []string{fmt.Sprintf("/home/%s", p.Username)},
				Hostname:  "node1.example.com",
				Username:  p.Username,
				SizeBytes: 12582912,
			},
		}
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username":  p.Username,
				"snapshots": mockSnapshots,
				"dry_run":   true,
			},
			Logs: logs,
		}, nil
	}

	repoPassword := fmt.Sprintf("hpanel-sec-%s", p.Username)
	cmd := exec.CommandContext(ctx, "restic", "-r", repo, "snapshots", "--json")
	cmd.Env = append(os.Environ(), fmt.Sprintf("RESTIC_PASSWORD=%s", repoPassword))

	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed to list snapshots: %v: %s", err, strings.TrimSpace(stderr.String())),
			Logs:    logs,
		}, err
	}

	var snapshots []SnapshotItem
	if err := json.Unmarshal(stdout.Bytes(), &snapshots); err != nil {
		snapshots = []SnapshotItem{}
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username":  p.Username,
			"snapshots": snapshots,
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// Action: backup.restore
// -------------------------------------------------------------

type BackupRestorePayload struct {
	Username    string `json:"username"`
	SnapshotID  string `json:"snapshot_id"`
	RestoreType string `json:"restore_type"` // "full", "files_only", "databases_only", "single_db", "single_path"
	TargetPath  string `json:"target_path,omitempty"`
	TargetDB    string `json:"target_db,omitempty"`
}

type BackupRestoreAction struct{}

func NewBackupRestoreAction() *BackupRestoreAction {
	return &BackupRestoreAction{}
}

func (a *BackupRestoreAction) Name() string {
	return "backup.restore"
}

func (a *BackupRestoreAction) Validate(payload []byte) error {
	var p BackupRestorePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}
	return ValidateRestoreRequest(p.Username, p.SnapshotID, p.RestoreType, p.TargetPath, p.TargetDB)
}

func (a *BackupRestoreAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p BackupRestorePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if err := ValidateRestoreRequest(p.Username, p.SnapshotID, p.RestoreType, p.TargetPath, p.TargetDB); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("initiating restic restore for user '%s' (snapshot: %s, type: %s)", p.Username, p.SnapshotID, p.RestoreType)}

	if dryRun || runtime.GOOS != "linux" {
		logs = append(logs, fmt.Sprintf("[dry-run] simulated restore of %s", p.RestoreType))
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"snapshot_id":  p.SnapshotID,
				"restore_type": p.RestoreType,
				"status":       "restored",
				"dry_run":      true,
			},
			Logs: logs,
		}, nil
	}

	repo := fmt.Sprintf("/var/backups/hpanel/%s", p.Username)
	repoPassword := fmt.Sprintf("hpanel-sec-%s", p.Username)
	env := append(os.Environ(), fmt.Sprintf("RESTIC_PASSWORD=%s", repoPassword))

	targetDir := "/"
	if p.RestoreType == "single_path" && p.TargetPath != "" {
		targetDir = filepath.Dir(p.TargetPath)
	}

	restoreCmd := exec.CommandContext(ctx, "restic", "-r", repo, "restore", p.SnapshotID, "--target", targetDir)
	restoreCmd.Env = env
	var stderr bytes.Buffer
	restoreCmd.Stderr = &stderr

	if err := restoreCmd.Run(); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("restic restore failed: %v: %s", err, strings.TrimSpace(stderr.String())),
			Logs:    logs,
		}, err
	}

	// If restoring database(s), pipe the restored SQL file into MariaDB
	if p.RestoreType == "databases_only" || p.RestoreType == "full" || p.RestoreType == "single_db" {
		stagingDir := fmt.Sprintf("/home/%s/tmp/backup_dbs", p.Username)
		if entries, err := os.ReadDir(stagingDir); err == nil {
			for _, entry := range entries {
				if !strings.HasSuffix(entry.Name(), ".sql") {
					continue
				}
				dbName := strings.TrimSuffix(entry.Name(), ".sql")
				if p.RestoreType == "single_db" && dbName != p.TargetDB {
					continue
				}

				sqlPath := filepath.Join(stagingDir, entry.Name())
				inFile, openErr := os.Open(sqlPath)
				if openErr == nil {
					mysqlCmd := exec.CommandContext(ctx, "mysql", "--defaults-file=/etc/mysql/debian.cnf", dbName)
					mysqlCmd.Stdin = inFile
					_ = mysqlCmd.Run()
					inFile.Close()
					logs = append(logs, fmt.Sprintf("restored MariaDB database '%s'", dbName))
				}
			}
		}
		_ = os.RemoveAll(stagingDir)
	}

	// Fix file ownership
	_ = exec.CommandContext(ctx, "chown", "-R", fmt.Sprintf("%s:%s", p.Username, p.Username), fmt.Sprintf("/home/%s", p.Username)).Run()

	logs = append(logs, "restore completed successfully")
	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"snapshot_id":  p.SnapshotID,
			"restore_type": p.RestoreType,
			"status":       "restored",
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// Action: backup.delete
// -------------------------------------------------------------

type BackupDeletePayload struct {
	Username   string `json:"username"`
	SnapshotID string `json:"snapshot_id"`
}

type BackupDeleteAction struct{}

func NewBackupDeleteAction() *BackupDeleteAction {
	return &BackupDeleteAction{}
}

func (a *BackupDeleteAction) Name() string {
	return "backup.delete"
}

func (a *BackupDeleteAction) Validate(payload []byte) error {
	var p BackupDeletePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}
	if !reLinuxUser.MatchString(p.Username) {
		return fmt.Errorf("invalid username: '%s'", p.Username)
	}
	return ValidateSnapshotID(p.SnapshotID)
}

func (a *BackupDeleteAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p BackupDeletePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if err := a.Validate(payload); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("forgetting restic snapshot '%s' for user '%s'", p.SnapshotID, p.Username)}

	if dryRun || runtime.GOOS != "linux" {
		logs = append(logs, "[dry-run] snapshot forgotten and repo pruned")
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"snapshot_id": p.SnapshotID,
				"status":      "deleted",
				"dry_run":     true,
			},
			Logs: logs,
		}, nil
	}

	repo := fmt.Sprintf("/var/backups/hpanel/%s", p.Username)
	repoPassword := fmt.Sprintf("hpanel-sec-%s", p.Username)

	cmd := exec.CommandContext(ctx, "restic", "-r", repo, "forget", p.SnapshotID, "--prune")
	cmd.Env = append(os.Environ(), fmt.Sprintf("RESTIC_PASSWORD=%s", repoPassword))

	var stderr bytes.Buffer
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed to delete snapshot: %v: %s", err, strings.TrimSpace(stderr.String())),
			Logs:    logs,
		}, err
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"snapshot_id": p.SnapshotID,
			"status":      "deleted",
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// Action: backup.verify
// -------------------------------------------------------------

type BackupVerifyPayload struct {
	Username string `json:"username"`
}

type BackupVerifyAction struct{}

func NewBackupVerifyAction() *BackupVerifyAction {
	return &BackupVerifyAction{}
}

func (a *BackupVerifyAction) Name() string {
	return "backup.verify"
}

func (a *BackupVerifyAction) Validate(payload []byte) error {
	var p BackupVerifyPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}
	if !reLinuxUser.MatchString(p.Username) {
		return fmt.Errorf("invalid username: '%s'", p.Username)
	}
	return nil
}

func (a *BackupVerifyAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p BackupVerifyPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if !reLinuxUser.MatchString(p.Username) {
		return &ActionResult{Success: false, Error: "invalid username"}, fmt.Errorf("invalid username")
	}

	logs := []string{fmt.Sprintf("verifying restic repository integrity for user '%s'", p.Username)}

	if dryRun || runtime.GOOS != "linux" {
		logs = append(logs, "[dry-run] simulated repository check: OK")
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"status":  "verified",
				"dry_run": true,
			},
			Logs: logs,
		}, nil
	}

	repo := fmt.Sprintf("/var/backups/hpanel/%s", p.Username)
	repoPassword := fmt.Sprintf("hpanel-sec-%s", p.Username)

	cmd := exec.CommandContext(ctx, "restic", "-r", repo, "check")
	cmd.Env = append(os.Environ(), fmt.Sprintf("RESTIC_PASSWORD=%s", repoPassword))

	var stderr bytes.Buffer
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("restic integrity check failed: %v: %s", err, strings.TrimSpace(stderr.String())),
			Logs:    logs,
		}, err
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"status": "verified",
		},
		Logs: logs,
	}, nil
}
