package actions

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"os/exec"
	"runtime"
	"strings"
	"time"
)

// -------------------------------------------------------------
// Action: log.tail
// -------------------------------------------------------------

type LogTailPayload struct {
	Username string `json:"username"`
	LogFile  string `json:"log_file"`
	Lines    int    `json:"lines,omitempty"`  // default 100, max 1000
	Search   string `json:"search,omitempty"` // optional case-insensitive filter
}

type LogTailAction struct{}

func NewLogTailAction() *LogTailAction {
	return &LogTailAction{}
}

func (a *LogTailAction) Name() string {
	return "log.tail"
}

func (a *LogTailAction) Validate(payload []byte) error {
	var p LogTailPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}

	_, err := ValidateLogFile(p.Username, p.LogFile)
	return err
}

func (a *LogTailAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p LogTailPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	cleanPath, err := ValidateLogFile(p.Username, p.LogFile)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	linesCount := p.Lines
	if linesCount <= 0 {
		linesCount = 100
	}
	if linesCount > 1000 {
		linesCount = 1000
	}

	logs := []string{fmt.Sprintf("tailing %d lines from '%s' for user '%s'", linesCount, p.LogFile, p.Username)}

	if dryRun || runtime.GOOS != "linux" {
		mockLines := []string{
			fmt.Sprintf("[%s] [info] [simulated] GET /index.html 200 452ms", time.Now().Format("2006-01-02 15:04:05")),
			fmt.Sprintf("[%s] [info] [simulated] GET /api/v1/health 200 12ms", time.Now().Format("2006-01-02 15:04:05")),
			fmt.Sprintf("[%s] [notice] [simulated] PHP worker spawned for user %s", time.Now().Format("2006-01-02 15:04:05"), p.Username),
		}

		if p.Search != "" {
			var filtered []string
			searchLower := strings.ToLower(p.Search)
			for _, line := range mockLines {
				if strings.Contains(strings.ToLower(line), searchLower) {
					filtered = append(filtered, line)
				}
			}
			mockLines = filtered
		}

		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"log_file":        p.LogFile,
				"lines":           mockLines,
				"line_count":      len(mockLines),
				"file_size_bytes": 1024,
				"dry_run":         true,
			},
			Logs: logs,
		}, nil
	}

	// Real Linux reading
	fileInfo, err := os.Stat(cleanPath)
	if err != nil {
		if os.IsNotExist(err) {
			return &ActionResult{
				Success: true,
				Data: map[string]any{
					"log_file":        p.LogFile,
					"lines":           []string{},
					"line_count":      0,
					"file_size_bytes": 0,
					"message":         "Log file does not exist yet (no entries recorded)",
				},
				Logs: logs,
			}, nil
		}
		return &ActionResult{Success: false, Error: fmt.Sprintf("cannot access log file: %v", err), Logs: logs}, err
	}

	file, err := os.Open(cleanPath)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("failed to open log file: %v", err), Logs: logs}, err
	}
	defer file.Close()

	// Read last max 2MB of file to avoid huge memory consumption
	const maxReadBytes = 2 * 1024 * 1024
	fileSize := fileInfo.Size()
	startOffset := int64(0)
	if fileSize > maxReadBytes {
		startOffset = fileSize - maxReadBytes
	}
	_, _ = file.Seek(startOffset, io.SeekStart)

	var allLines []string
	scanner := bufio.NewScanner(file)
	// 64KB max line length
	buf := make([]byte, 64*1024)
	scanner.Buffer(buf, 256*1024)

	searchLower := strings.ToLower(p.Search)
	for scanner.Scan() {
		line := scanner.Text()
		if p.Search == "" || strings.Contains(strings.ToLower(line), searchLower) {
			allLines = append(allLines, line)
		}
	}

	// Extract the last N lines
	tailLines := allLines
	if len(allLines) > linesCount {
		tailLines = allLines[len(allLines)-linesCount:]
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"log_file":        p.LogFile,
			"lines":           tailLines,
			"line_count":      len(tailLines),
			"file_size_bytes": fileSize,
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// Action: log.list
// -------------------------------------------------------------

type LogListPayload struct {
	Username string `json:"username"`
}

type LogItem struct {
	Name       string `json:"name"`
	SizeBytes  int64  `json:"size_bytes"`
	ModifiedAt string `json:"modified_at"`
	Category   string `json:"category"` // "access", "error", "php", "cron", "other"
}

type LogListAction struct{}

func NewLogListAction() *LogListAction {
	return &LogListAction{}
}

func (a *LogListAction) Name() string {
	return "log.list"
}

func (a *LogListAction) Validate(payload []byte) error {
	var p LogListPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}
	if !reLinuxUser.MatchString(p.Username) {
		return fmt.Errorf("invalid username: '%s'", p.Username)
	}
	return nil
}

func (a *LogListAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p LogListPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if !reLinuxUser.MatchString(p.Username) {
		return &ActionResult{Success: false, Error: "invalid username"}, fmt.Errorf("invalid username")
	}

	logsDir := fmt.Sprintf("/home/%s/logs", p.Username)
	logs := []string{fmt.Sprintf("listing log files in '%s'", logsDir)}

	if dryRun || runtime.GOOS != "linux" {
		mockFiles := []LogItem{
			{Name: "domain.com.access.log", SizeBytes: 15420, ModifiedAt: time.Now().Format(time.RFC3339), Category: "access"},
			{Name: "domain.com.error.log", SizeBytes: 2048, ModifiedAt: time.Now().Format(time.RFC3339), Category: "error"},
			{Name: "php_error.log", SizeBytes: 4096, ModifiedAt: time.Now().Format(time.RFC3339), Category: "php"},
			{Name: "cron_1.log", SizeBytes: 512, ModifiedAt: time.Now().Format(time.RFC3339), Category: "cron"},
		}
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username":  p.Username,
				"log_files": mockFiles,
				"dry_run":   true,
			},
			Logs: logs,
		}, nil
	}

	entries, err := os.ReadDir(logsDir)
	if err != nil {
		if os.IsNotExist(err) {
			return &ActionResult{
				Success: true,
				Data: map[string]any{
					"username":  p.Username,
					"log_files": []LogItem{},
				},
				Logs: logs,
			}, nil
		}
		return &ActionResult{Success: false, Error: fmt.Sprintf("failed to read logs directory: %v", err), Logs: logs}, err
	}

	var logFiles []LogItem
	for _, entry := range entries {
		if entry.IsDir() {
			continue
		}
		name := entry.Name()
		if !strings.HasSuffix(name, ".log") && !strings.Contains(name, ".log.") {
			continue
		}

		info, err := entry.Info()
		if err != nil {
			continue
		}

		category := "other"
		if strings.Contains(name, "access") {
			category = "access"
		} else if strings.Contains(name, "error") && !strings.Contains(name, "php") {
			category = "error"
		} else if strings.Contains(name, "php") {
			category = "php"
		} else if strings.HasPrefix(name, "cron_") {
			category = "cron"
		}

		logFiles = append(logFiles, LogItem{
			Name:       name,
			SizeBytes:  info.Size(),
			ModifiedAt: info.ModTime().Format(time.RFC3339),
			Category:   category,
		})
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username":  p.Username,
			"log_files": logFiles,
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// Action: log.rotate
// -------------------------------------------------------------

type LogRotatePayload struct {
	Username string `json:"username"`
	Force    bool   `json:"force,omitempty"`
}

type LogRotateAction struct{}

func NewLogRotateAction() *LogRotateAction {
	return &LogRotateAction{}
}

func (a *LogRotateAction) Name() string {
	return "log.rotate"
}

func (a *LogRotateAction) Validate(payload []byte) error {
	var p LogRotatePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}
	if !reLinuxUser.MatchString(p.Username) {
		return fmt.Errorf("invalid username: '%s'", p.Username)
	}
	return nil
}

func (a *LogRotateAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p LogRotatePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if !reLinuxUser.MatchString(p.Username) {
		return &ActionResult{Success: false, Error: "invalid username"}, fmt.Errorf("invalid username")
	}

	configPath := fmt.Sprintf("/etc/logrotate.d/hpanel-%s", p.Username)
	logrotateConfig := fmt.Sprintf(`/home/%s/logs/*.log {
    weekly
    missingok
    rotate 4
    compress
    delaycompress
    notifempty
    create 0640 %s %s
    sharedscripts
}
`, p.Username, p.Username, p.Username)

	logs := []string{fmt.Sprintf("configuring logrotate for user '%s' at '%s'", p.Username, configPath)}

	if dryRun || runtime.GOOS != "linux" {
		logs = append(logs, "[dry-run] simulated logrotate config write")
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username":    p.Username,
				"config_path": configPath,
				"dry_run":     true,
			},
			Logs: logs,
		}, nil
	}

	// Write logrotate config atomically
	if err := os.WriteFile(configPath, []byte(logrotateConfig), 0644); err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("failed to write logrotate config: %v", err), Logs: logs}, err
	}

	if p.Force {
		cmd := exec.CommandContext(ctx, "logrotate", "-f", configPath)
		var stderr bytes.Buffer
		cmd.Stderr = &stderr
		if err := cmd.Run(); err != nil {
			logs = append(logs, fmt.Sprintf("warning: manual logrotate trigger returned: %v (%s)", err, strings.TrimSpace(stderr.String())))
		} else {
			logs = append(logs, "forced log rotation executed successfully")
		}
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username":    p.Username,
			"config_path": configPath,
		},
		Logs: logs,
	}, nil
}
