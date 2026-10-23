package actions

import (
	"context"
	"encoding/json"
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

