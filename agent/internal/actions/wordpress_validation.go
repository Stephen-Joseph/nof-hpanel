package actions

import (
	"errors"
	"fmt"
	"net/mail"
	"path/filepath"
	"regexp"
	"strings"
)

var (
	reWpAdminUser = regexp.MustCompile(`^[a-zA-Z0-9_\-\.]{3,60}$`)
	reWpPrefix    = regexp.MustCompile(`^[a-zA-Z0-9_]{1,20}$`)
)

// ValidateWordPressAdminUser validates the WP admin username.
func ValidateWordPressAdminUser(user string) error {
	trimmed := strings.TrimSpace(user)
	if trimmed == "" {
		return errors.New("wordpress admin user cannot be empty")
	}
	if !reWpAdminUser.MatchString(trimmed) {
		return fmt.Errorf("invalid wordpress admin username: '%s'", trimmed)
	}
	return nil
}

// ValidateWordPressEmail validates the WP admin email.
func ValidateWordPressEmail(email string) error {
	trimmed := strings.TrimSpace(email)
	if trimmed == "" {
		return errors.New("admin email cannot be empty")
	}
	if _, err := mail.ParseAddress(trimmed); err != nil {
		return fmt.Errorf("invalid admin email address: '%s'", trimmed)
	}
	return nil
}

// ValidateWordPressTablePrefix validates the WordPress database table prefix.
func ValidateWordPressTablePrefix(prefix string) error {
	trimmed := strings.TrimSpace(prefix)
	if trimmed == "" {
		return errors.New("table prefix cannot be empty")
	}
	if !reWpPrefix.MatchString(trimmed) {
		return fmt.Errorf("invalid wordpress table prefix: '%s'", trimmed)
	}
	return nil
}

// ValidateWordPressInstallPath verifies that the install directory resides inside /home/<username>/.
func ValidateWordPressInstallPath(username, installPath string) (string, error) {
	if !reLinuxUser.MatchString(username) {
		return "", fmt.Errorf("invalid username: '%s'", username)
	}

	clean := filepath.Clean(installPath)
	homeDir := fmt.Sprintf("/home/%s", username)

	if !strings.HasPrefix(clean, homeDir) {
		return "", fmt.Errorf("install path '%s' must reside inside account home '%s'", clean, homeDir)
	}

	if clean == homeDir {
		return "", errors.New("cannot install wordpress directly to account home root directory")
	}

	return clean, nil
}
