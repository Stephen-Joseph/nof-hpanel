package actions

import (
	"errors"
	"fmt"
	"path/filepath"
	"regexp"
	"strings"
)

var (
	// Git branch name: alphanumeric, -, _, /, .
	reGitBranch = regexp.MustCompile(`^[a-zA-Z0-9_\-\.\/]{1,100}$`)

	// Allowed predefined build steps
	allowedBuildSteps = map[string]string{
		"composer_install": "composer install --no-dev --optimize-autoloader --no-interaction",
		"npm_build":        "npm ci && npm run build",
		"artisan_migrate":  "php artisan migrate --force",
		"artisan_cache":    "php artisan config:cache && php artisan route:cache",
	}
)

// ValidateGitRepoURL validates that a repository URL conforms to safe Git URL schemes.
func ValidateGitRepoURL(repoURL string) error {
	trimmed := strings.TrimSpace(repoURL)
	if trimmed == "" {
		return errors.New("repository URL cannot be empty")
	}

	if strings.ContainsAny(trimmed, "\r\n\x00;`$|&<>") {
		return errors.New("repository URL contains illegal characters")
	}

	isHTTPS := strings.HasPrefix(trimmed, "https://") || strings.HasPrefix(trimmed, "http://")
	isSSH := strings.HasPrefix(trimmed, "git@") || strings.HasPrefix(trimmed, "ssh://")

	if !isHTTPS && !isSSH {
		return fmt.Errorf("invalid repository URL protocol: must start with https://, git@, or ssh://")
	}

	return nil
}

// ValidateGitBranch validates the branch name format.
func ValidateGitBranch(branch string) error {
	trimmed := strings.TrimSpace(branch)
	if trimmed == "" {
		return errors.New("branch name cannot be empty")
	}

	if strings.Contains(trimmed, "..") || strings.HasPrefix(trimmed, "/") || strings.HasSuffix(trimmed, "/") {
		return errors.New("invalid branch path format")
	}

	if !reGitBranch.MatchString(trimmed) {
		return fmt.Errorf("invalid branch format: '%s'", trimmed)
	}

	return nil
}

// ValidateDeployPath verifies that the deployment path resides strictly inside /home/<username>/.
func ValidateDeployPath(username string, deployPath string) (string, error) {
	if !reLinuxUser.MatchString(username) {
		return "", fmt.Errorf("invalid username: '%s'", username)
	}

	clean := filepath.Clean(deployPath)
	homeDir := fmt.Sprintf("/home/%s", username)

	if !strings.HasPrefix(clean, homeDir) {
		return "", fmt.Errorf("deployment path '%s' must reside inside account home '%s'", clean, homeDir)
	}

	if clean == homeDir {
		return "", errors.New("cannot deploy directly to account home root directory")
	}

	return clean, nil
}

// ValidateBuildSteps checks build steps against the safe allow-list.
func ValidateBuildSteps(steps []string) ([]string, error) {
	sanitized := make([]string, 0, len(steps))
	for _, step := range steps {
		trimmed := strings.ToLower(strings.TrimSpace(step))
		if trimmed == "" {
			continue
		}
		if _, ok := allowedBuildSteps[trimmed]; !ok {
			return nil, fmt.Errorf("unrecognized or disallowed build step: '%s'", step)
		}
		sanitized = append(sanitized, trimmed)
	}
	return sanitized, nil
}
