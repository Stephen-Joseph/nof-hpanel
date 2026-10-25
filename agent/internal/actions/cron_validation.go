package actions

import (
	"errors"
	"fmt"
	"net/mail"
	"regexp"
	"strconv"
	"strings"
)

var (
	// Cron schedule field allowed chars: digits, *, /, -, ,
	reCronField = regexp.MustCompile(`^[0-9*,\/\-]+$`)
)

// ValidateCronSchedule validates a standard 5-part cron expression:
// minute (0-59), hour (0-23), day of month (1-31), month (1-12), day of week (0-7).
func ValidateCronSchedule(schedule string) error {
	trimmed := strings.TrimSpace(schedule)
	if trimmed == "" {
		return errors.New("cron schedule cannot be empty")
	}

	fields := strings.Fields(trimmed)
	if len(fields) != 5 {
		return fmt.Errorf("invalid cron schedule: must contain exactly 5 fields (minute, hour, dom, month, dow), got %d fields", len(fields))
	}

	// Validate each field character pattern and bounds
	bounds := []struct {
		name string
		min  int
		max  int
	}{
		{"minute", 0, 59},
		{"hour", 0, 23},
		{"day of month", 1, 31},
		{"month", 1, 12},
		{"day of week", 0, 7},
	}

	for i, f := range fields {
		if !reCronField.MatchString(f) {
			return fmt.Errorf("invalid characters in cron %s field: '%s'", bounds[i].name, f)
		}

		if err := validateFieldRange(f, bounds[i].min, bounds[i].max, bounds[i].name); err != nil {
			return err
		}
	}

	return nil
}

func validateFieldRange(field string, minVal, maxVal int, fieldName string) error {
	// If it's a wildcard or step with wildcard
	if field == "*" {
		return nil
	}

	// Split by comma for multiple values
	parts := strings.Split(field, ",")
	for _, part := range parts {
		// Handle step: e.g. */15 or 1-5/2
		if strings.Contains(part, "/") {
			subParts := strings.Split(part, "/")
			if len(subParts) != 2 {
				return fmt.Errorf("invalid step expression in %s: '%s'", fieldName, part)
			}
			step, err := strconv.Atoi(subParts[1])
			if err != nil || step <= 0 || step > maxVal {
				return fmt.Errorf("invalid step value in %s: '%s'", fieldName, subParts[1])
			}
			if subParts[0] == "*" {
				continue
			}
			part = subParts[0]
		}

		// Handle range: e.g. 1-5
		if strings.Contains(part, "-") {
			rangeParts := strings.Split(part, "-")
			if len(rangeParts) != 2 {
				return fmt.Errorf("invalid range expression in %s: '%s'", fieldName, part)
			}
			start, err1 := strconv.Atoi(rangeParts[0])
			end, err2 := strconv.Atoi(rangeParts[1])
			if err1 != nil || err2 != nil || start < minVal || end > maxVal || start > end {
				return fmt.Errorf("range out of bounds (%d-%d) in %s: '%s'", minVal, maxVal, fieldName, part)
			}
			continue
		}

		// Single integer
		val, err := strconv.Atoi(part)
		if err != nil || val < minVal || val > maxVal {
			return fmt.Errorf("value out of bounds (%d-%d) in %s: '%s'", minVal, maxVal, fieldName, part)
		}
	}

	return nil
}

// ValidateCronCommand checks that the command is safe to put in crontab.
// Disallows newlines (crontab injection), null bytes, and dangerous privilege escalation.
func ValidateCronCommand(command string) error {
	trimmed := strings.TrimSpace(command)
	if trimmed == "" {
		return errors.New("cron command cannot be empty")
	}

	if len(trimmed) > 1000 {
		return errors.New("cron command exceeds maximum length of 1000 characters")
	}

	if strings.ContainsAny(trimmed, "\r\n\x00") {
		return errors.New("cron command contains illegal newline or null characters")
	}

	// Disallow sudo, su, doas attempts
	lower := strings.ToLower(trimmed)
	disallowedPrefixes := []string{"sudo ", "su ", "doas "}
	for _, dis := range disallowedPrefixes {
		if strings.HasPrefix(lower, dis) || strings.Contains(lower, " "+dis) || strings.Contains(lower, ";"+dis) || strings.Contains(lower, "&"+dis) {
			return fmt.Errorf("privilege escalation command '%s' is prohibited", strings.TrimSpace(dis))
		}
	}

	return nil
}

// ValidateCronEmail validates the optional notification email address.
func ValidateCronEmail(email string) error {
	trimmed := strings.TrimSpace(email)
	if trimmed == "" {
		return nil
	}

	if strings.ContainsAny(trimmed, "\r\n\x00\"'") {
		return errors.New("invalid characters in notification email")
	}

	_, err := mail.ParseAddress(trimmed)
	if err != nil {
		return fmt.Errorf("invalid email address format: '%s'", trimmed)
	}

	return nil
}
