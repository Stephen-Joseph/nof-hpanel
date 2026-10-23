package actions

import (
	"errors"
	"fmt"
	"path/filepath"
	"regexp"
	"strings"
)

var (
	// Allow-list for safe log filenames: e.g. domain.com.access.log, error.log, php_error.log, cron_1.log
	reLogFilename = regexp.MustCompile(`^[a-zA-Z0-9_\-\.]+\.log(\.[0-9]+)?(\.gz)?$`)
)

// ValidateLogFile validates that a requested log filename is strictly a base filename
// matching allowed log naming conventions without path traversal attempts.
func ValidateLogFile(username string, logFilename string) (string, error) {
	if !reLinuxUser.MatchString(username) {
		return "", fmt.Errorf("invalid system username: '%s'", username)
	}

	trimmed := strings.TrimSpace(logFilename)
	if trimmed == "" {
		return "", errors.New("log filename cannot be empty")
	}

	if strings.ContainsAny(trimmed, "/\\\x00") || strings.Contains(trimmed, "..") {
		return "", errors.New("log filename contains illegal path separator or traversal characters")
	}

	if !reLogFilename.MatchString(trimmed) {
		return "", fmt.Errorf("invalid log filename '%s': must end in .log or rotated .log.N", trimmed)
	}

	logsDir := fmt.Sprintf("/home/%s/logs", username)
	targetPath := filepath.Join(logsDir, trimmed)
	cleanPath := filepath.Clean(targetPath)

	if !strings.HasPrefix(cleanPath, logsDir) {
		return "", errors.New("resolved log path escapes account logs directory")
	}

	return cleanPath, nil
}

