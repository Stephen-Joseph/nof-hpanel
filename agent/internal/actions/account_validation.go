package actions

import (
	"errors"
	"fmt"
	"regexp"
	"strings"
)

var (
	usernameRegex = regexp.MustCompile(`^[a-z][a-z0-9]{2,15}$`)
	domainRegex   = regexp.MustCompile(`^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$`)
	phpVerRegex   = regexp.MustCompile(`^8\.[0-4]$`)

	reservedUsernames = map[string]struct{}{
		"root":             {},
		"daemon":           {},
		"bin":              {},
		"sys":              {},
		"sync":             {},
		"games":            {},
		"man":              {},
		"lp":               {},
		"mail":             {},
		"news":             {},
		"uucp":             {},
		"proxy":            {},
		"www-data":         {},
		"backup":           {},
		"list":             {},
		"irc":              {},
		"gnats":            {},
		"nobody":           {},
		"systemd-network":  {},
		"systemd-resolve":  {},
		"systemd-timesync": {},
		"messagebus":       {},
		"sshd":             {},
		"mysql":            {},
		"mariadb":          {},
		"nginx":            {},
		"redis":            {},
		"postgres":         {},
		"bind":             {},
		"named":            {},
		"postfix":          {},
		"dovecot":          {},
		"ftp":              {},
		"panel":            {},
		"hpanel":           {},
		"agent":            {},
		"admin":            {},
		"administrator":    {},
		"guest":            {},
		"test":             {},
	}
)

// ValidateLinuxUsername ensures username conforms to strict allow-list regex and is not reserved.
func ValidateLinuxUsername(username string) error {
	username = strings.TrimSpace(username)
	if username == "" {
		return errors.New("username cannot be empty")
	}

	if !usernameRegex.MatchString(username) {
		return fmt.Errorf("username '%s' must match pattern ^[a-z][a-z0-9]{2,15}$ (lowercase letters and numbers only, 3-16 chars)", username)
	}

	if _, reserved := reservedUsernames[username]; reserved {
		return fmt.Errorf("username '%s' is a reserved system or service name", username)
	}

	return nil
}

// ValidateDomain ensures domain is RFC-compliant lowercase.
func ValidateDomain(domain string) error {
	domain = strings.TrimSpace(domain)
	if domain == "" {
		return errors.New("domain cannot be empty")
	}

	if strings.ToLower(domain) != domain {
		return fmt.Errorf("domain '%s' must be strictly lowercase", domain)
	}

	if len(domain) > 253 {
		return errors.New("domain cannot exceed 253 characters")
	}

	if !domainRegex.MatchString(domain) {
		return fmt.Errorf("domain '%s' is not a valid RFC-compliant domain name", domain)
	}

	return nil
}

// ValidatePHPVersion ensures php version is in supported format (e.g. 8.1, 8.2, 8.3, 8.4).
func ValidatePHPVersion(version string) error {
	version = strings.TrimSpace(version)
	if version == "" {
		return errors.New("php_version cannot be empty")
	}

	if !phpVerRegex.MatchString(version) {
		return fmt.Errorf("php_version '%s' is unsupported (allowed: 8.0, 8.1, 8.2, 8.3, 8.4)", version)
	}

	return nil
}
