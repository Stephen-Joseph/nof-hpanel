package actions

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"runtime"
	"strings"
)

// -------------------------------------------------------------
// Action: cron.write
// -------------------------------------------------------------

type CronJobItem struct {
	ID          int64  `json:"id"`
	Schedule    string `json:"schedule"`
	Command     string `json:"command"`
	EmailOutput string `json:"email_output,omitempty"`
	IsEnabled   bool   `json:"is_enabled"`
}

type CronWritePayload struct {
	Username string        `json:"username"`
	Jobs     []CronJobItem `json:"jobs"`
}

type CronWriteAction struct{}

func NewCronWriteAction() *CronWriteAction {
	return &CronWriteAction{}
}

func (a *CronWriteAction) Name() string {
	return "cron.write"
}

func (a *CronWriteAction) Validate(payload []byte) error {
	var p CronWritePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}

	if !reLinuxUser.MatchString(p.Username) {
		return fmt.Errorf("invalid username: '%s'", p.Username)
	}

	for i, job := range p.Jobs {
		if err := ValidateCronSchedule(job.Schedule); err != nil {
			return fmt.Errorf("job[%d] (%d): %w", i, job.ID, err)
		}
		if err := ValidateCronCommand(job.Command); err != nil {
			return fmt.Errorf("job[%d] (%d): %w", i, job.ID, err)
		}
		if err := ValidateCronEmail(job.EmailOutput); err != nil {
			return fmt.Errorf("job[%d] (%d): %w", i, job.ID, err)
		}
	}

	return nil
}

func (a *CronWriteAction) RenderCrontab(username string, jobs []CronJobItem) (string, []string) {
	var buf strings.Builder
	var activeJobs []string

	buf.WriteString(fmt.Sprintf("# Managed by HPanel for user %s - DO NOT EDIT MANUALLY\n", username))
	buf.WriteString("SHELL=/bin/bash\n")
	buf.WriteString(fmt.Sprintf("PATH=/usr/local/bin:/usr/bin:/bin:/home/%s/bin\n\n", username))

	for _, job := range jobs {
		if !job.IsEnabled {
			continue
		}

		// Individual mailto if specified
		if job.EmailOutput != "" {
			buf.WriteString(fmt.Sprintf("MAILTO=\"%s\"\n", job.EmailOutput))
		} else {
			buf.WriteString("MAILTO=\"\"\n")
		}

		// Append stdout/stderr log output redirection to /home/<user>/logs/cron_<id>.log
		logPath := fmt.Sprintf("/home/%s/logs/cron_%d.log", username, job.ID)
		cronLine := fmt.Sprintf("%s %s >> %s 2>&1\n\n", strings.TrimSpace(job.Schedule), strings.TrimSpace(job.Command), logPath)
		buf.WriteString(fmt.Sprintf("# Job ID: %d\n", job.ID))
		buf.WriteString(cronLine)
		activeJobs = append(activeJobs, fmt.Sprintf("Job %d: %s %s", job.ID, job.Schedule, job.Command))
	}

	return buf.String(), activeJobs
}

func (a *CronWriteAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p CronWritePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if err := a.Validate(payload); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	crontabContent, activeJobs := a.RenderCrontab(p.Username, p.Jobs)
	logs := []string{fmt.Sprintf("syncing crontab for user '%s' (%d total jobs, %d enabled)", p.Username, len(p.Jobs), len(activeJobs))}

	if dryRun || runtime.GOOS != "linux" {
		logs = append(logs, fmt.Sprintf("[dry-run] simulated crontab install for '%s'", p.Username))
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username":         p.Username,
				"active_job_count": len(activeJobs),
				"rendered_crontab": crontabContent,
				"dry_run":          true,
			},
			Logs: logs,
		}, nil
	}

	// Real Linux execution
	// If no enabled jobs, clear user crontab
	if len(activeJobs) == 0 {
		removeCmd := exec.CommandContext(ctx, "crontab", "-u", p.Username, "-r")
		_ = removeCmd.Run() // Ignore error if crontab was already empty
		logs = append(logs, fmt.Sprintf("cleared crontab for user '%s' (0 active jobs)", p.Username))
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username":         p.Username,
				"active_job_count": 0,
			},
			Logs: logs,
		}, nil
	}

	// Write to atomic temp file in user home directory (or TempDir if user home not found) and apply
	destDir := fmt.Sprintf("/home/%s", p.Username)
	if _, err := os.Stat(destDir); err != nil {
		destDir = os.TempDir()
	}
	tmpFile, err := os.CreateTemp(destDir, fmt.Sprintf(".crontab_%s.tmp-*", p.Username))
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("failed to create temp crontab file: %v", err), Logs: logs}, err
	}
	tmpPath := tmpFile.Name()
	defer os.Remove(tmpPath)

	if _, err := tmpFile.WriteString(crontabContent); err != nil {
		_ = tmpFile.Close()
		return &ActionResult{Success: false, Error: fmt.Sprintf("failed to write temp crontab file: %v", err), Logs: logs}, err
	}
	if err := tmpFile.Sync(); err != nil {
		_ = tmpFile.Close()
		return &ActionResult{Success: false, Error: fmt.Sprintf("failed to sync temp crontab file: %v", err), Logs: logs}, err
	}
	if err := tmpFile.Close(); err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("failed to close temp crontab file: %v", err), Logs: logs}, err
	}
	if err := os.Chmod(tmpPath, 0600); err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("failed to chmod temp crontab file: %v", err), Logs: logs}, err
	}

	cmd := exec.CommandContext(ctx, "crontab", "-u", p.Username, tmpPath)
	var stderr bytes.Buffer
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("crontab installation failed: %v: %s", err, strings.TrimSpace(stderr.String())),
			Logs:    logs,
		}, err
	}

	logs = append(logs, fmt.Sprintf("installed crontab with %d active jobs for '%s'", len(activeJobs), p.Username))
	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username":         p.Username,
			"active_job_count": len(activeJobs),
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// Action: cron.list
// -------------------------------------------------------------

type CronListPayload struct {
	Username string `json:"username"`
}

type CronListAction struct{}

func NewCronListAction() *CronListAction {
	return &CronListAction{}
}

func (a *CronListAction) Name() string {
	return "cron.list"
}

func (a *CronListAction) Validate(payload []byte) error {
	var p CronListPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}
	if !reLinuxUser.MatchString(p.Username) {
		return fmt.Errorf("invalid username: '%s'", p.Username)
	}
	return nil
}

func (a *CronListAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p CronListPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if !reLinuxUser.MatchString(p.Username) {
		return &ActionResult{Success: false, Error: "invalid username"}, fmt.Errorf("invalid username")
	}

	logs := []string{fmt.Sprintf("reading crontab for user '%s'", p.Username)}

	if dryRun || runtime.GOOS != "linux" {
		mockCrontab := fmt.Sprintf("# Simulated crontab for %s\n*/15 * * * * /usr/bin/php /home/%s/artisan schedule:run\n", p.Username, p.Username)
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username": p.Username,
				"crontab":  mockCrontab,
				"dry_run":  true,
			},
			Logs: logs,
		}, nil
	}

	cmd := exec.CommandContext(ctx, "crontab", "-u", p.Username, "-l")
	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		// Crontab -l exits with 1 when no crontab exists
		if strings.Contains(stderr.String(), "no crontab for") {
			return &ActionResult{
				Success: true,
				Data: map[string]any{
					"username": p.Username,
					"crontab":  "",
				},
				Logs: logs,
			}, nil
		}
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("failed to read crontab: %v: %s", err, strings.TrimSpace(stderr.String())),
			Logs:    logs,
		}, err
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username": p.Username,
			"crontab":  stdout.String(),
		},
		Logs: logs,
	}, nil
}
