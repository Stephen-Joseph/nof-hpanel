package actions

import (
	"errors"
	"fmt"
	"net"
	"regexp"
	"strings"
)

var (
	// Linux usernames: 3-16 chars, lowercase alphanumeric, starts with letter
	reLinuxUser = regexp.MustCompile(`^[a-z][a-z0-9]{2,15}$`)

	// DB suffix: 1-40 chars, lowercase alphanumeric and underscore
	reDbSuffix = regexp.MustCompile(`^[a-z0-9_]{1,40}$`)

	// DB User suffix: 1-16 chars, lowercase alphanumeric and underscore
	reDbUserSuffix = regexp.MustCompile(`^[a-z0-9_]{1,16}$`)

	// DB User Host allow-list pattern (localhost, %, IPv4, IPv6, or valid hostname)
	reHostPattern = regexp.MustCompile(`^[a-zA-Z0-9_.\-%]{1,64}$`)

	// Allowed database privileges
	allowedPrivileges = map[string]bool{
		"ALL":                     true,
		"ALL PRIVILEGES":          true,
		"SELECT":                  true,
		"INSERT":                  true,
		"UPDATE":                  true,
		"DELETE":                  true,
		"CREATE":                  true,
		"DROP":                    true,
		"REFERENCES":              true,
		"INDEX":                   true,
		"ALTER":                   true,
		"CREATE TEMPORARY TABLES": true,
		"LOCK TABLES":             true,
		"EXECUTE":                 true,
		"CREATE VIEW":             true,
		"SHOW VIEW":               true,
		"CREATE ROUTINE":          true,
		"ALTER ROUTINE":           true,
		"EVENT":                   true,
		"TRIGGER":                 true,
	}
)

// ValidateDatabaseName verifies that a database name begins with <username>_
// and matches strict alphanumeric/underscore rules with max 64 chars total.
func ValidateDatabaseName(username string, dbName string) error {
	if !reLinuxUser.MatchString(username) {
		return fmt.Errorf("invalid system username: '%s'", username)
	}

	prefix := username + "_"
	if !strings.HasPrefix(dbName, prefix) {
		return fmt.Errorf("database name '%s' must begin with prefix '%s'", dbName, prefix)
	}

	suffix := strings.TrimPrefix(dbName, prefix)
	if suffix == "" {
		return fmt.Errorf("database name suffix cannot be empty")
	}

	if !reDbSuffix.MatchString(suffix) {
		return fmt.Errorf("invalid database suffix '%s': must be lowercase alphanumeric and underscore (1-40 chars)", suffix)
	}

	if len(dbName) > 64 {
		return fmt.Errorf("database name '%s' exceeds maximum length of 64 characters", dbName)
	}

	return nil
}

// ValidateDatabaseUsername verifies that a database username begins with <username>_
// and conforms to MariaDB user length constraints (max 32 chars).
func ValidateDatabaseUsername(username string, dbUser string) error {
	if !reLinuxUser.MatchString(username) {
		return fmt.Errorf("invalid system username: '%s'", username)
	}

	prefix := username + "_"
	if !strings.HasPrefix(dbUser, prefix) {
		return fmt.Errorf("database username '%s' must begin with prefix '%s'", dbUser, prefix)
	}

	suffix := strings.TrimPrefix(dbUser, prefix)
	if suffix == "" {
		return fmt.Errorf("database username suffix cannot be empty")
	}

	if !reDbUserSuffix.MatchString(suffix) {
		return fmt.Errorf("invalid database user suffix '%s': must be lowercase alphanumeric and underscore (1-16 chars)", suffix)
	}

	if len(dbUser) > 32 {
		return fmt.Errorf("database username '%s' exceeds maximum length of 32 characters", dbUser)
	}

	return nil
}

// ValidateRemoteHost validates the client host for MariaDB users.
// Allows 'localhost', '%', or a valid IP/hostname up to 64 chars.
func ValidateRemoteHost(host string) error {
	if host == "" {
		return errors.New("remote host cannot be empty")
	}

	if host == "localhost" || host == "%" {
		return nil
	}

	if !reHostPattern.MatchString(host) {
		return fmt.Errorf("invalid remote host format: '%s'", host)
	}

	// If it looks like an IP, verify valid IP address format
	if ip := net.ParseIP(host); ip != nil {
		return nil
	}

	// Hostname validation
	if strings.Contains(host, "..") || strings.HasPrefix(host, "-") || strings.HasSuffix(host, "-") {
		return fmt.Errorf("invalid host domain format: '%s'", host)
	}

	return nil
}

// ValidatePrivileges checks a list of privileges against the strict allow-list.
func ValidatePrivileges(privileges []string) ([]string, error) {
	if len(privileges) == 0 {
		return nil, errors.New("privileges list cannot be empty")
	}

	sanitized := make([]string, 0, len(privileges))
	for _, priv := range privileges {
		upper := strings.ToUpper(strings.TrimSpace(priv))
		if !allowedPrivileges[upper] {
			return nil, fmt.Errorf("unauthorized or unknown database privilege: '%s'", priv)
		}
		sanitized = append(sanitized, upper)
	}

	return sanitized, nil
}
