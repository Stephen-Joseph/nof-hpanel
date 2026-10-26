package actions

import (
	"bytes"
	"compress/gzip"
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"
)

func TestValidateDatabaseName(t *testing.T) {
	tests := []struct {
		name     string
		user     string
		db       string
		expError bool
	}{
		{"valid database", "alicecorp", "alicecorp_wp", false},
		{"valid with multiple underscores", "alicecorp", "alicecorp_blog_2026", false},
		{"missing prefix", "alicecorp", "otheruser_wp", true},
		{"missing suffix", "alicecorp", "alicecorp_", true},
		{"no underscore", "alicecorp", "alicecorpwp", true},
		{"invalid chars in suffix", "alicecorp", "alicecorp_wp-db", true},
		{"invalid chars in user", "alice-corp", "alice-corp_wp", true},
		{"too long db name", "alicecorp", "alicecorp_" + strings.Repeat("a", 60), true},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			err := ValidateDatabaseName(tt.user, tt.db)
			if (err != nil) != tt.expError {
				t.Errorf("ValidateDatabaseName(%q, %q) error = %v, expected error %v", tt.user, tt.db, err, tt.expError)
			}
		})
	}
}

func TestValidateDatabaseUsername(t *testing.T) {
	tests := []struct {
		name     string
		user     string
		dbUser   string
		expError bool
	}{
		{"valid db user", "alicecorp", "alicecorp_user1", false},
		{"valid short suffix", "alicecorp", "alicecorp_u", false},
		{"missing prefix", "alicecorp", "bob_user1", true},
		{"missing suffix", "alicecorp", "alicecorp_", true},
		{"too long suffix", "alicecorp", "alicecorp_verylongusername12345", true},
		{"invalid chars", "alicecorp", "alicecorp_u$er", true},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			err := ValidateDatabaseUsername(tt.user, tt.dbUser)
			if (err != nil) != tt.expError {
				t.Errorf("ValidateDatabaseUsername(%q, %q) error = %v, expected error %v", tt.user, tt.dbUser, err, tt.expError)
			}
		})
	}
}

func TestValidateRemoteHost(t *testing.T) {
	tests := []struct {
		name     string
		host     string
		expError bool
	}{
		{"localhost", "localhost", false},
		{"wildcard", "%", false},
		{"valid ipv4", "192.168.1.100", false},
		{"loopback ip", "127.0.0.1", false},
		{"valid hostname", "db.example.com", false},
		{"empty host", "", true},
		{"space in host", "local host", true},
		{"invalid chars", "192.168.1.1; DROP", true},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			err := ValidateRemoteHost(tt.host)
			if (err != nil) != tt.expError {
				t.Errorf("ValidateRemoteHost(%q) error = %v, expected error %v", tt.host, err, tt.expError)
			}
		})
	}
}

func TestValidatePrivileges(t *testing.T) {
	tests := []struct {
		name     string
		privs    []string
		expError bool
	}{
		{"all privileges", []string{"ALL PRIVILEGES"}, false},
		{"standard web app privs", []string{"SELECT", "INSERT", "UPDATE", "DELETE", "CREATE", "DROP", "INDEX", "ALTER"}, false},
		{"lowercase converted", []string{"select", "insert"}, false},
		{"empty list", []string{}, true},
		{"illegal privilege", []string{"SELECT", "SUPER", "FILE"}, true},
		{"sql injection attempt", []string{"ALL; DROP TABLE users;"}, true},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			sanitized, err := ValidatePrivileges(tt.privs)
			if (err != nil) != tt.expError {
				t.Errorf("ValidatePrivileges(%v) error = %v, expected error %v", tt.privs, err, tt.expError)
			}
			if err == nil && len(sanitized) != len(tt.privs) {
				t.Errorf("ValidatePrivileges length mismatch: got %d, want %d", len(sanitized), len(tt.privs))
			}
		})
	}
}

func TestDbActionsDryRun(t *testing.T) {
	ctx := context.Background()

	// 1. db.create
	createAction := NewDbCreateAction()
	createPayload, _ := json.Marshal(DbCreatePayload{
		Username: "alicecorp",
		DbName:   "alicecorp_blog",
	})
	if err := createAction.Validate(createPayload); err != nil {
		t.Fatalf("DbCreate validation failed: %v", err)
	}
	res, err := createAction.Execute(ctx, createPayload, true)
	if err != nil || !res.Success {
		t.Fatalf("DbCreate execute failed: %v, res: %+v", err, res)
	}

	// 2. dbuser.create
	userCreateAction := NewDbUserCreateAction()
	userCreatePayload, _ := json.Marshal(DbUserCreatePayload{
		Username:           "alicecorp",
		DbUser:             "alicecorp_blogusr",
		Password:           "SecretStrongPassword2026!",
		RemoteHost:         "localhost",
		MaxUserConnections: 25,
	})
	if err := userCreateAction.Validate(userCreatePayload); err != nil {
		t.Fatalf("DbUserCreate validation failed: %v", err)
	}
	res, err = userCreateAction.Execute(ctx, userCreatePayload, true)
	if err != nil || !res.Success {
		t.Fatalf("DbUserCreate execute failed: %v, res: %+v", err, res)
	}

	// 3. dbuser.grant
	grantAction := NewDbUserGrantAction()
	grantPayload, _ := json.Marshal(DbUserGrantPayload{
		Username:   "alicecorp",
		DbUser:     "alicecorp_blogusr",
		DbName:     "alicecorp_blog",
		Privileges: []string{"SELECT", "INSERT", "UPDATE", "DELETE"},
		RemoteHost: "localhost",
	})
	if err := grantAction.Validate(grantPayload); err != nil {
		t.Fatalf("DbUserGrant validation failed: %v", err)
	}
	res, err = grantAction.Execute(ctx, grantPayload, true)
	if err != nil || !res.Success {
		t.Fatalf("DbUserGrant execute failed: %v, res: %+v", err, res)
	}

	// 4. dbuser.revoke
	revokeAction := NewDbUserRevokeAction()
	revokePayload, _ := json.Marshal(DbUserRevokePayload{
		Username:   "alicecorp",
		DbUser:     "alicecorp_blogusr",
		DbName:     "alicecorp_blog",
		RemoteHost: "localhost",
	})
	if err := revokeAction.Validate(revokePayload); err != nil {
		t.Fatalf("DbUserRevoke validation failed: %v", err)
	}
	res, err = revokeAction.Execute(ctx, revokePayload, true)
	if err != nil || !res.Success {
		t.Fatalf("DbUserRevoke execute failed: %v, res: %+v", err, res)
	}

	// 5. dbuser.set_password
	setPassAction := NewDbUserSetPasswordAction()
	setPassPayload, _ := json.Marshal(DbUserSetPasswordPayload{
		Username:   "alicecorp",
		DbUser:     "alicecorp_blogusr",
		Password:   "NewSecretStrongPassword2026!",
		RemoteHost: "localhost",
	})
	if err := setPassAction.Validate(setPassPayload); err != nil {
		t.Fatalf("DbUserSetPassword validation failed: %v", err)
	}
	res, err = setPassAction.Execute(ctx, setPassPayload, true)
	if err != nil || !res.Success {
		t.Fatalf("DbUserSetPassword execute failed: %v, res: %+v", err, res)
	}

	// 6. db.dump
	dumpAction := NewDbDumpAction()
	dumpPayload, _ := json.Marshal(DbDumpPayload{
		Username: "alicecorp",
		DbName:   "alicecorp_blog",
	})
	if err := dumpAction.Validate(dumpPayload); err != nil {
		t.Fatalf("DbDump validation failed: %v", err)
	}
	res, err = dumpAction.Execute(ctx, dumpPayload, true)
	if err != nil || !res.Success {
		t.Fatalf("DbDump execute failed: %v, res: %+v", err, res)
	}

	// 7. db.list_usage
	usageAction := NewDbListUsageAction()
	usagePayload, _ := json.Marshal(DbListUsagePayload{
		Username: "alicecorp",
	})
	if err := usageAction.Validate(usagePayload); err != nil {
		t.Fatalf("DbListUsage validation failed: %v", err)
	}
	res, err = usageAction.Execute(ctx, usagePayload, true)
	if err != nil || !res.Success {
		t.Fatalf("DbListUsage execute failed: %v, res: %+v", err, res)
	}

	// 8. dbuser.drop
	dropUserAction := NewDbUserDropAction()
	dropUserPayload, _ := json.Marshal(DbUserDropPayload{
		Username:   "alicecorp",
		DbUser:     "alicecorp_blogusr",
		RemoteHost: "localhost",
	})
	if err := dropUserAction.Validate(dropUserPayload); err != nil {
		t.Fatalf("DbUserDrop validation failed: %v", err)
	}
	res, err = dropUserAction.Execute(ctx, dropUserPayload, true)
	if err != nil || !res.Success {
		t.Fatalf("DbUserDrop execute failed: %v, res: %+v", err, res)
	}

	// 9. db.drop
	dropAction := NewDbDropAction()
	dropPayload, _ := json.Marshal(DbDropPayload{
		Username: "alicecorp",
		DbName:   "alicecorp_blog",
	})
	if err := dropAction.Validate(dropPayload); err != nil {
		t.Fatalf("DbDrop validation failed: %v", err)
	}
	res, err = dropAction.Execute(ctx, dropPayload, true)
	if err != nil || !res.Success {
		t.Fatalf("DbDrop execute failed: %v, res: %+v", err, res)
	}
}

func TestDbSsoCreateAndRevoke(t *testing.T) {
	ctx := context.Background()

	// 1. db.sso_create validation & execution
	ssoCreateAction := NewDbSsoCreateAction()

	// Validation tests
	invalidPayloads := []DbSsoCreatePayload{
		{AccountUsername: "Invalid-User!"},
		{AccountUsername: "alicecorp", Database: "otheruser_db"},
	}
	for _, p := range invalidPayloads {
		b, _ := json.Marshal(p)
		if err := ssoCreateAction.Validate(b); err == nil {
			t.Errorf("expected validation error for sso_create payload %+v, got nil", p)
		}
	}

	validCreatePayload, _ := json.Marshal(DbSsoCreatePayload{
		AccountUsername: "alicecorp",
		Database:        "alicecorp_blog",
	})
	if err := ssoCreateAction.Validate(validCreatePayload); err != nil {
		t.Fatalf("sso_create validation failed: %v", err)
	}

	res, err := ssoCreateAction.Execute(ctx, validCreatePayload, true)
	if err != nil || !res.Success {
		t.Fatalf("sso_create execution failed: %v, res: %+v", err, res)
	}

	dbUser, ok := res.Data["db_user"].(string)
	if !ok || !regexp.MustCompile(`^pma_[a-z0-9]{10}$`).MatchString(dbUser) {
		t.Fatalf("unexpected db_user format: %v", res.Data["db_user"])
	}

	dbPass, ok := res.Data["db_password"].(string)
	if !ok || len(dbPass) != 24 {
		t.Fatalf("unexpected db_password length: %v", res.Data["db_password"])
	}

	// Verify grant is restricted strictly to account pattern `<account>\_%`.* and no global privileges
	foundIsolatedGrantLog := false
	for _, logLine := range res.Logs {
		if strings.Contains(logLine, "*.*") {
			t.Fatalf("security violation: found global privileges *.* in grant log: %s", logLine)
		}
		if strings.Contains(logLine, "`alicecorp\\_%`.*") {
			foundIsolatedGrantLog = true
		}
	}
	if !foundIsolatedGrantLog {
		t.Fatalf("expected grant on `alicecorp\\_%%`.*, but logs were: %v", res.Logs)
	}

	// 2. db.sso_revoke validation & execution
	ssoRevokeAction := NewDbSsoRevokeAction()

	invalidRevokes := []string{
		"root",
		"admin",
		"pma_user; DROP TABLE--",
		"alicecorp_user",
	}
	for _, u := range invalidRevokes {
		b, _ := json.Marshal(DbSsoRevokePayload{DbUser: u})
		if err := ssoRevokeAction.Validate(b); err == nil {
			t.Errorf("expected validation error for invalid sso_revoke user %q, got nil", u)
		}
	}

	validRevokePayload, _ := json.Marshal(DbSsoRevokePayload{DbUser: dbUser})
	if err := ssoRevokeAction.Validate(validRevokePayload); err != nil {
		t.Fatalf("sso_revoke validation failed for user %q: %v", dbUser, err)
	}

	resRevoke, err := ssoRevokeAction.Execute(ctx, validRevokePayload, true)
	if err != nil || !resRevoke.Success {
		t.Fatalf("sso_revoke execution failed: %v, res: %+v", err, resRevoke)
	}
}

func TestDbImportActionSecurityAndStreaming(t *testing.T) {
	ctx := context.Background()
	importAction := NewDbImportAction()

	tmpHome := t.TempDir()
	// Fake user home /home/alicecorp mapped via a temp directory
	userHome := filepath.Join(tmpHome, "alicecorp")
	if err := os.MkdirAll(userHome, 0755); err != nil {
		t.Fatal(err)
	}

	// 1. Path traversal check: must reject paths outside customer home
	traversalPayload, _ := json.Marshal(DbImportPayload{
		Username:  "alicecorp",
		DbName:    "alicecorp_blog",
		InputFile: "/etc/passwd",
	})
	res, err := importAction.Execute(ctx, traversalPayload, true)
	if err == nil || (res != nil && res.Success) {
		t.Fatalf("expected path traversal rejection for /etc/passwd, got err=%v", err)
	}

	// Traversal with ..
	dotDotPayload, _ := json.Marshal(DbImportPayload{
		Username:  "alicecorp",
		DbName:    "alicecorp_blog",
		InputFile: "/home/alicecorp/../../etc/shadow",
	})
	res, err = importAction.Execute(ctx, dotDotPayload, true)
	if err == nil || (res != nil && res.Success) {
		t.Fatalf("expected path traversal rejection for dot-dot path, got err=%v", err)
	}

	// 2. Forbidden SQL statements check in stream
	forbiddenTests := []struct {
		name    string
		sql     string
		errFrag string
	}{
		{
			name:    "CREATE USER injection",
			sql:     "CREATE TABLE test (id int);\nCREATE USER 'hacker'@'localhost' IDENTIFIED BY 'pass';\n",
			errFrag: "CREATE USER is not allowed",
		},
		{
			name:    "GRANT injection",
			sql:     "GRANT ALL PRIVILEGES ON *.* TO 'hacker'@'localhost';\n",
			errFrag: "GRANT is not allowed",
		},
		{
			name:    "DROP DATABASE of unauthorized database",
			sql:     "DROP DATABASE other_account_db;\n",
			errFrag: "DROP DATABASE on unauthorized database",
		},
		{
			name:    "USE of unauthorized database",
			sql:     "USE `other_account_db`;\n",
			errFrag: "USE on unauthorized database",
		},
	}

	for _, tt := range forbiddenTests {
		t.Run(tt.name, func(t *testing.T) {
			var buf bytes.Buffer
			var out bytes.Buffer
			buf.WriteString(tt.sql)
			err := streamAndValidateSql(ctx, &buf, &out, "alicecorp_blog", MaxImportSizeBytes)
			if err == nil {
				t.Fatalf("expected stream error for %s, got nil", tt.name)
			}
			if !strings.Contains(err.Error(), tt.errFrag) {
				t.Fatalf("expected error containing %q, got: %v", tt.errFrag, err)
			}
		})
	}

	// 3. Allowed statements (own database, DDL, DML)
	validSql := "CREATE TABLE `posts` (`id` int(11) NOT NULL, `title` varchar(255));\n" +
		"USE `alicecorp_blog`;\n" +
		"DROP DATABASE IF EXISTS `alicecorp_blog`;\n" +
		"INSERT INTO `posts` VALUES (1, 'Hello World');\n"

	var validBuf bytes.Buffer
	var validOut bytes.Buffer
	validBuf.WriteString(validSql)
	if err := streamAndValidateSql(ctx, &validBuf, &validOut, "alicecorp_blog", MaxImportSizeBytes); err != nil {
		t.Fatalf("streamAndValidateSql failed for valid statements: %v", err)
	}

	// 4. Oversized limit check
	smallLimit := int64(20)
	var overLimitBuf bytes.Buffer
	overLimitBuf.WriteString("INSERT INTO posts VALUES (1, 'Extra long line that exceeds limit');\n")
	var overLimitOut bytes.Buffer
	if err := streamAndValidateSql(ctx, &overLimitBuf, &overLimitOut, "alicecorp_blog", smallLimit); err == nil {
		t.Fatal("expected size limit error, got nil")
	}

	// 5. Gzip stream handling
	var gzBuf bytes.Buffer
	gw := gzip.NewWriter(&gzBuf)
	_, _ = gw.Write([]byte("CREATE TABLE gz_test (id int);\nINSERT INTO gz_test VALUES (42);\n"))
	_ = gw.Close()

	gzReader, err := gzip.NewReader(&gzBuf)
	if err != nil {
		t.Fatalf("failed to create gzip reader: %v", err)
	}
	defer gzReader.Close()

	var decompressedOut bytes.Buffer
	if err := streamAndValidateSql(ctx, gzReader, &decompressedOut, "alicecorp_blog", MaxImportSizeBytes); err != nil {
		t.Fatalf("failed to stream gzipped SQL: %v", err)
	}
	if !strings.Contains(decompressedOut.String(), "gz_test") {
		t.Fatalf("decompressed stream missing content: %s", decompressedOut.String())
	}
}
