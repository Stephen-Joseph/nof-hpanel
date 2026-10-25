package actions

import (
	"errors"
	"fmt"
	"path/filepath"
	"regexp"
	"strings"
)

var (
	// Restic snapshot ID: 8 to 64 hex characters
	reSnapshotID = regexp.MustCompile(`^[a-f0-9]{8,64}$`)

	// Allowed restore types
	allowedRestoreTypes = map[string]bool{
		"full":           true,
		"files_only":     true,
		"databases_only": true,
		"single_db":      true,
		"single_path":    true,
	}
)

// ValidateSnapshotID validates that the restic snapshot identifier is a valid hex string.
func ValidateSnapshotID(snapshotID string) error {
	trimmed := strings.TrimSpace(snapshotID)
	if trimmed == "" {
		return errors.New("snapshot ID cannot be empty")
	}

	if !reSnapshotID.MatchString(trimmed) {
		return fmt.Errorf("invalid snapshot ID format '%s': must be 8-64 hex characters", trimmed)
	}

	return nil
}

// ValidateRestoreRequest validates the restore parameters and target path/database confinement.
func ValidateRestoreRequest(username string, snapshotID string, restoreType string, targetPath string, targetDB string) error {
	if !reLinuxUser.MatchString(username) {
		return fmt.Errorf("invalid username: '%s'", username)
	}

	if err := ValidateSnapshotID(snapshotID); err != nil {
		return err
	}

	rType := strings.ToLower(strings.TrimSpace(restoreType))
	if !allowedRestoreTypes[rType] {
		return fmt.Errorf("invalid restore type '%s': allowed types are full, files_only, databases_only, single_db, single_path", restoreType)
	}

	if rType == "single_db" {
		if targetDB == "" {
			return errors.New("target_db is required for single_db restore")
		}
		prefix := username + "_"
		if !strings.HasPrefix(targetDB, prefix) {
			return fmt.Errorf("target database '%s' must begin with '%s'", targetDB, prefix)
		}
	}

	if rType == "single_path" {
		if targetPath == "" {
			return errors.New("target_path is required for single_path restore")
		}
		userHome := fmt.Sprintf("/home/%s", username)
		clean := filepath.Clean(targetPath)
		if !strings.HasPrefix(clean, userHome) {
			return fmt.Errorf("target path '%s' must reside inside account home '%s'", clean, userHome)
		}
	}

	return nil
}
