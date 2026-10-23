package actions

import (
	"strings"
	"testing"
)

// TestUsernameValidationFuzz tests aggressive malicious and malformed usernames against security regex.
func TestUsernameValidationFuzz(t *testing.T) {
	invalidUsernames := []string{
		"",                          // empty
		"ab",                        // too short (< 3)
		"1user",                     // leading number
		"-user",                     // leading symbol
		"_user",                     // leading underscore
		"User",                      // uppercase
		"USER",                      // all uppercase
		"user_name",                 // underscore not allowed in linux username regex
		"user-name",                 // dash not allowed
		"user name",                 // space
		"user$name",                 // shell char
		"user;rm -rf /",             // command injection
		"user$(id)",                 // subshell injection
		"user`whoami`",              // backtick injection
		"user\x00injection",         // null byte injection
		"../../etc/passwd",          // path traversal
		"/root",                     // system user
		"a1234567890123456",         // too long (17 chars, max 16)
		"verylongusernamethatexceeds", // far too long
	}

	for _, username := range invalidUsernames {
		err := validateUsername(username)
		if err == nil {
			t.Errorf("Expected username '%s' to be rejected by security validation, but it passed", username)
		}
	}

	validUsernames := []string{
		"usr",
		"alice",
		"bob123",
		"customer1",
		"a12345678901234", // 15 chars
		"a123456789012345", // 16 chars
	}

	for _, username := range validUsernames {
		err := validateUsername(username)
		if err != nil {
			t.Errorf("Expected username '%s' to be valid, but got error: %v", username, err)
		}
	}
}

// TestDomainValidationFuzz tests malformed, malicious, and invalid domain names.
func TestDomainValidationFuzz(t *testing.T) {
	invalidDomains := []string{
		"",
		"   ",
		"-bad.com",
		"bad-.com",
		"http://example.com",
		"https://example.com/path",
		"example.com:8080",
		"example..com",
		"example.com;rm",
		"example.com$(whoami)",
		"example.com`id`",
		"*.example.com",
		"domain\x00.com",
		"../../evil.com",
		strings.Repeat("a", 70) + ".com", // label too long
		"EXAMPLE.COM",                     // lowercase RFC required
	}

	for _, domain := range invalidDomains {
		err := validateDomain(domain)
		if err == nil {
			t.Errorf("Expected domain '%s' to be rejected, but it was accepted", domain)
		}
	}

	validDomains := []string{
		"example.com",
		"sub.example.com",
		"deep.sub.domain.org",
		"my-site.co.uk",
		"app123.io",
	}

	for _, domain := range validDomains {
		err := validateDomain(domain)
		if err != nil {
			t.Errorf("Expected domain '%s' to be valid, but got: %v", domain)
		}
	}
}

// TestDocumentRootTraversalFuzz verifies that paths attempting to escape /home/<user> are rejected.
func TestDocumentRootTraversalFuzz(t *testing.T) {
	username := "alice"

	traversalPaths := []string{
		"/",
		"/etc",
		"/etc/passwd",
		"/var/www",
		"/home",
		"/home/bob",
		"/home/bob/public_html",
		"/home/alice/../../etc/shadow",
		"/home/alice/../bob",
		"/home/alice/public_html/../../../etc",
		"/home/alice/public_html/\x00/etc",
	}

	for _, path := range traversalPaths {
		err := validateDocumentRoot(username, path)
		if err == nil {
			t.Errorf("Expected path traversal '%s' for user '%s' to be rejected, but passed", path, username)
		}
	}

	validPaths := []string{
		"/home/alice",
		"/home/alice/public_html",
		"/home/alice/public_html/mysite",
		"/home/alice/subdomains/blog/public",
	}

	for _, path := range validPaths {
		err := validateDocumentRoot(username, path)
		if err != nil {
			t.Errorf("Expected path '%s' for user '%s' to be valid, but got: %v", path, username, err)
		}
	}
}

// TestDatabaseNamingSecurityFuzz verifies database and database user naming conventions.
func TestDatabaseNamingSecurityFuzz(t *testing.T) {
	username := "alice"

	invalidDbNames := []string{
		"bob_db",             // prefix mismatch
		"alice_",              // empty suffix
		"alice_db; DROP TABLE", // SQL injection attempt
		"alice_db'--",         // SQL comment
		"alice_db$(id)",       // command injection
		"alice_my-database",   // dashes not allowed
		"alice_" + strings.Repeat("a", 60), // exceeding length
	}

	for _, db := range invalidDbNames {
		err := validateDatabaseName(username, db)
		if err == nil {
			t.Errorf("Expected database '%s' to be rejected for user '%s', but passed", db, username)
		}
	}

	validDbNames := []string{
		"alice_wp",
		"alice_app1",
		"alice_production2026",
	}

	for _, db := range validDbNames {
		err := validateDatabaseName(username, db)
		if err != nil {
			t.Errorf("Expected database '%s' to be valid, but got: %v", db, err)
		}
	}
}

// TestGitRepoSecurityFuzz verifies Git repository URL validation preventing SSRF and argument injection.
func TestGitRepoSecurityFuzz(t *testing.T) {
	invalidRepos := []string{
		"file:///etc/passwd",
		"file://localhost/etc/shadow",
		"--upload-pack=evil",
		"-oProxyCommand=calc.exe",
		"ftp://evil.com/repo.git",
		"gopher://evil.com/repo",
		"git://evil.com/repo.git",
		"javascript:alert(1)",
	}

	for _, repo := range invalidRepos {
		err := validateGitRepoURL(repo)
		if err == nil {
			t.Errorf("Expected Git repo URL '%s' to be rejected, but passed", repo)
		}
	}

	validRepos := []string{
		"https://github.com/org/repo.git",
		"https://gitlab.com/group/subgroup/project.git",
		"git@github.com:user/repository.git",
	}

	for _, repo := range validRepos {
		err := validateGitRepoURL(repo)
		if err != nil {
			t.Errorf("Expected Git repo URL '%s' to be valid, but got: %v", repo, err)
		}
	}
}

