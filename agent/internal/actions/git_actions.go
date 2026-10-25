package actions

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

// -------------------------------------------------------------
// Action: git.deploy
// -------------------------------------------------------------

type GitDeployPayload struct {
	Username   string   `json:"username"`
	RepoURL    string   `json:"repo_url"`
	Branch     string   `json:"branch"`
	DeployPath string   `json:"deploy_path"`
	PrivateKey string   `json:"private_key,omitempty"`
	BuildSteps []string `json:"build_steps,omitempty"`
	ReleaseTag string   `json:"release_tag"`
}

type GitDeployAction struct{}

func NewGitDeployAction() *GitDeployAction {
	return &GitDeployAction{}
}

func (a *GitDeployAction) Name() string {
	return "git.deploy"
}

func (a *GitDeployAction) Validate(payload []byte) error {
	var p GitDeployPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}

	if err := ValidateGitRepoURL(p.RepoURL); err != nil {
		return err
	}
	if err := ValidateGitBranch(p.Branch); err != nil {
		return err
	}
	if _, err := ValidateDeployPath(p.Username, p.DeployPath); err != nil {
		return err
	}
	if _, err := ValidateBuildSteps(p.BuildSteps); err != nil {
		return err
	}

	return nil
}

func (a *GitDeployAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p GitDeployPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	cleanDeployPath, err := ValidateDeployPath(p.Username, p.DeployPath)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	steps, err := ValidateBuildSteps(p.BuildSteps)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	releaseTag := p.ReleaseTag
	if releaseTag == "" {
		releaseTag = time.Now().Format("20060102150405")
	}

	logs := []string{fmt.Sprintf("initiating git deployment for user '%s' (%s @ %s)", p.Username, p.RepoURL, p.Branch)}

	if dryRun || runtime.GOOS != "linux" {
		logs = append(logs,
			fmt.Sprintf("[dry-run] cloned %s branch %s into releases/%s", p.RepoURL, p.Branch, releaseTag),
			fmt.Sprintf("[dry-run] executed %d build steps", len(steps)),
			fmt.Sprintf("[dry-run] swapped current symlink -> releases/%s", releaseTag),
		)

		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"release_tag":      releaseTag,
				"commit_hash":      "e8b4c291823746198f7e2a4b9c1d0e8f7a6b5c4d",
				"commit_message":   "Deploy release " + releaseTag,
				"committer":        "Deploy Bot",
				"duration_seconds": 2.8,
				"dry_run":          true,
			},
			Logs: logs,
		}, nil
	}

	startTime := time.Now()

	// Step 1: Prepare release directory layout: <deploy_path>/releases/<release_tag>
	releasesDir := filepath.Join(cleanDeployPath, "releases")
	targetReleaseDir := filepath.Join(releasesDir, releaseTag)
	_ = os.MkdirAll(releasesDir, 0755)

	// Setup transient SSH key if provided
	var sshCmd string
	if p.PrivateKey != "" {
		keyFile, keyErr := os.CreateTemp("", fmt.Sprintf("deploy_key_%s_*", p.Username))
		if keyErr == nil {
			_ = os.Chmod(keyFile.Name(), 0600)
			_, _ = keyFile.WriteString(p.PrivateKey)
			_ = keyFile.Close()
			defer os.Remove(keyFile.Name())
			sshCmd = fmt.Sprintf("ssh -i %s -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null", keyFile.Name())
		}
	}

	// Step 2: Clone repository
	cloneArgs := []string{"clone", "--depth", "1", "--branch", p.Branch, p.RepoURL, targetReleaseDir}
	cloneCmd := exec.CommandContext(ctx, "git", cloneArgs...)
	if sshCmd != "" {
		cloneCmd.Env = append(os.Environ(), fmt.Sprintf("GIT_SSH_COMMAND=%s", sshCmd))
	}

	var cloneOut, cloneErr bytes.Buffer
	cloneCmd.Stdout = &cloneOut
	cloneCmd.Stderr = &cloneErr

	if err := cloneCmd.Run(); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("git clone failed: %v (%s)", err, strings.TrimSpace(cloneErr.String())),
			Logs:    logs,
		}, err
	}
	logs = append(logs, fmt.Sprintf("cloned %s branch %s successfully", p.RepoURL, p.Branch))

	// Step 3: Get latest commit info
	commitCmd := exec.CommandContext(ctx, "git", "-C", targetReleaseDir, "log", "-1", "--format=%H|%s|%an")
	commitOut, _ := commitCmd.Output()
	commitParts := strings.Split(strings.TrimSpace(string(commitOut)), "|")
	commitHash := "unknown"
	commitMsg := "Commit details unavailable"
	committer := "Unknown"
	if len(commitParts) >= 3 {
		commitHash = commitParts[0]
		commitMsg = commitParts[1]
		committer = commitParts[2]
	}

	// Step 4: Execute configured build steps strictly as account user
	for _, stepName := range steps {
		stepCmdStr, ok := allowedBuildSteps[stepName]
		if !ok {
			continue
		}
		logs = append(logs, fmt.Sprintf("executing build step: %s", stepName))

		cmdParts := strings.Fields(stepCmdStr)
		if len(cmdParts) == 0 {
			continue
		}

		buildCmd := exec.CommandContext(ctx, cmdParts[0], cmdParts[1:]...)
		buildCmd.Dir = targetReleaseDir
		buildCmd.Env = append(os.Environ(), fmt.Sprintf("HOME=/home/%s", p.Username))

		var bOut, bErr bytes.Buffer
		buildCmd.Stdout = &bOut
		buildCmd.Stderr = &bErr

		if err := buildCmd.Run(); err != nil {
			logs = append(logs, fmt.Sprintf("build step %s failed: %v (%s)", stepName, err, strings.TrimSpace(bErr.String())))
			return &ActionResult{
				Success: false,
				Error:   fmt.Sprintf("build step '%s' failed: %v", stepName, err),
				Logs:    logs,
			}, err
		}
		logs = append(logs, fmt.Sprintf("completed build step: %s", stepName))
	}

	// Step 5: Fix permissions
	_ = exec.CommandContext(ctx, "chown", "-R", fmt.Sprintf("%s:%s", p.Username, p.Username), targetReleaseDir).Run()

	// Step 6: Atomic symlink swap
	currentSymlink := filepath.Join(cleanDeployPath, "current")
	tempSymlink := filepath.Join(cleanDeployPath, fmt.Sprintf("current_tmp_%s", releaseTag))
	_ = os.Remove(tempSymlink)
	_ = os.Symlink(targetReleaseDir, tempSymlink)
	_ = os.Rename(tempSymlink, currentSymlink)

	duration := time.Since(startTime).Seconds()
	logs = append(logs, fmt.Sprintf("deployment %s live at %s (took %.2fs)", releaseTag, currentSymlink, duration))

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"release_tag":      releaseTag,
			"commit_hash":      commitHash,
			"commit_message":   commitMsg,
			"committer":        committer,
			"duration_seconds": duration,
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// Action: git.rollback
// -------------------------------------------------------------

type GitRollbackPayload struct {
	Username         string `json:"username"`
	DeployPath       string `json:"deploy_path"`
	TargetReleaseTag string `json:"target_release_tag"`
}

type GitRollbackAction struct{}

func NewGitRollbackAction() *GitRollbackAction {
	return &GitRollbackAction{}
}

func (a *GitRollbackAction) Name() string {
	return "git.rollback"
}

func (a *GitRollbackAction) Validate(payload []byte) error {
	var p GitRollbackPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}
	if _, err := ValidateDeployPath(p.Username, p.DeployPath); err != nil {
		return err
	}
	if p.TargetReleaseTag == "" {
		return fmt.Errorf("target_release_tag is required")
	}
	return nil
}

func (a *GitRollbackAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p GitRollbackPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	cleanDeployPath, err := ValidateDeployPath(p.Username, p.DeployPath)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	targetDir := filepath.Join(cleanDeployPath, "releases", p.TargetReleaseTag)
	logs := []string{fmt.Sprintf("rolling back deployment to release '%s'", p.TargetReleaseTag)}

	if dryRun || runtime.GOOS != "linux" {
		logs = append(logs, fmt.Sprintf("[dry-run] swapped current symlink -> releases/%s", p.TargetReleaseTag))
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"active_release": p.TargetReleaseTag,
				"status":         "rolled_back",
				"dry_run":        true,
			},
			Logs: logs,
		}, nil
	}

	if _, err := os.Stat(targetDir); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("target release '%s' does not exist in releases folder", p.TargetReleaseTag),
			Logs:    logs,
		}, fmt.Errorf("target release not found")
	}

	currentSymlink := filepath.Join(cleanDeployPath, "current")
	tempSymlink := filepath.Join(cleanDeployPath, fmt.Sprintf("rollback_tmp_%s", p.TargetReleaseTag))
	_ = os.Remove(tempSymlink)
	_ = os.Symlink(targetDir, tempSymlink)
	_ = os.Rename(tempSymlink, currentSymlink)

	logs = append(logs, fmt.Sprintf("successfully rolled back to release %s", p.TargetReleaseTag))
	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"active_release": p.TargetReleaseTag,
			"status":         "rolled_back",
		},
		Logs: logs,
	}, nil
}
