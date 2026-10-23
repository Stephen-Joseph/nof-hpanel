package actions

import (
	"errors"
	"fmt"
)

// ValidateUsageCollectRequest validates the parameters for usage collection.
func ValidateUsageCollectRequest(username string, domains []string, databases []string) error {
	if err := ValidateLinuxUsername(username); err != nil {
		return err
	}

	for _, domain := range domains {
		if err := ValidateDomain(domain); err != nil {
			return fmt.Errorf("invalid domain '%s' in usage collection: %w", domain, err)
		}
	}

	for _, db := range databases {
		if err := ValidateDatabaseName(db); err != nil {
			return fmt.Errorf("invalid database name '%s' in usage collection: %w", db, err)
		}
	}

	return nil
}

// ValidateLogOffset verifies that log byte offset is non-negative.
func ValidateLogOffset(offset int64) error {
	if offset < 0 {
		return errors.New("log offset cannot be negative")
	}
	return nil
}

