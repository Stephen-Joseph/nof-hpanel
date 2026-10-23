package actions

import (
	"context"
	"encoding/json"
	"testing"
)

func TestValidateGitRepoURL(t *testing.T) {
	tests := []struct {
		url     string
		wantErr bool
	}{
		{"https://github.com/example/repo.git", false},
		{"http://gitlab.com/group/project.git", false},
		{"git@github.com:example/repo.git", false},
		{"ssh://git@git.internal.net:2222/org/app.git", false},
		{"", true},
		{"ftp://example.com/repo.git", true},
		{"https://github.com/repo; rm -rf /", true},
		{"https://github.com/repo`touch test`", true},
	}

	for _, tt := range tests {
		t.Run(tt.url, func(t *testing.T) {
			err := ValidateGitRepoURL(tt.url)
			if (err != nil) != tt.wantErr {
				t.Errorf("ValidateGitRepoURL(%q) err = %v, wantErr %v", tt.url, err, tt.wantErr)
			}
		})
	}
}

func TestValidateGitBranch(t *testing.T) {
	tests := []struct {
		branch  string
		wantErr bool
	}{
		{"main", false},
		{"master", false},
		{"feature/user-auth-2.0", false},
		{"release-v1.0.1", false},
		{"", true},
		{"/root-branch", true},
		{"end-slash/", true},
		{"branch..traversal", true},
		{"branch with spaces", true},
		{"branch;evil", true},
	}

	for _, tt := range tests {
		t.Run(tt.branch, func(t *testing.T) {
			err := ValidateGitBranch(tt.branch)
			if (err != nil) != tt.wantErr {
				t.Errorf("ValidateGitBranch(%q) err = %v, wantErr %v", tt.branch, err, tt.wantErr)
			}
		})
	}
}

func TestValidateDeployPath(t *testing.T) {
	tests := []struct {
		user    string
		path    string
		wantErr bool
	}{
		{"alicecorp", "/home/alicecorp/deployments/app", false},
		{"alicecorp", "/home/alicecorp/public_html", false},
		{"alicecorp", "/home/alicecorp", true}, // cannot deploy directly to user root
		{"alicecorp", "/home/bobcorp/app", true}, // outside user home
		{"alicecorp", "/var/www/html", true},
		{"invalid-user!", "/home/invalid-user!/app", true},
	}

	for _, tt := range tests {
		t.Run(tt.path, func(t *testing.T) {
			_, err := ValidateDeployPath(tt.user, tt.path)
			if (err != nil) != tt.wantErr {
				t.Errorf("ValidateDeployPath(%q, %q) err = %v, wantErr %v", tt.user, tt.path, err, tt.wantErr)
			}
		})
	}
}

func TestValidateBuildSteps(t *testing.T) {
	validSteps := []string{"composer_install", "npm_build", "artisan_migrate"}
	sanitized, err := ValidateBuildSteps(validSteps)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(sanitized) != 3 {
		t.Fatalf("expected 3 steps, got %d", len(sanitized))
	}

	invalidSteps := []string{"composer_install", "arbitrary_script.sh"}
	if _, err := ValidateBuildSteps(invalidSteps); err == nil {
		t.Fatal("expected error for disallowed build step, got nil")
	}
}

func TestGitDeployAndRollbackDryRun(t *testing.T) {
	ctx := context.Background()

	deployAction := NewGitDeployAction()
	payload, _ := json.Marshal(GitDeployPayload{
		Username:   "alicecorp",
		RepoURL:    "https://github.com/example/sample-app.git",
		Branch:     "main",
		DeployPath: "/home/alicecorp/apps/sample",
		BuildSteps: []string{"composer_install"},
		ReleaseTag: "20261021120000",
	})

	if err := deployAction.Validate(payload); err != nil {
		t.Fatalf("validation failed: %v", err)
	}

	res, err := deployAction.Execute(ctx, payload, true)
	if err != nil || !res.Success {
		t.Fatalf("execute failed: %v, res: %+v", err, res)
	}

	if res.Data["release_tag"] != "20261021120000" {
		t.Fatalf("unexpected release tag: %v", res.Data["release_tag"])
	}

	rollbackAction := NewGitRollbackAction()
	rbPayload, _ := json.Marshal(GitRollbackPayload{
		Username:         "alicecorp",
		DeployPath:       "/home/alicecorp/apps/sample",
		TargetReleaseTag: "20261020110000",
	})

	if err := rollbackAction.Validate(rbPayload); err != nil {
		t.Fatalf("rollback validation failed: %v", err)
	}

	rbRes, err := rollbackAction.Execute(ctx, rbPayload, true)
	if err != nil || !rbRes.Success {
		t.Fatalf("rollback execute failed: %v, res: %+v", err, rbRes)
	}
}

