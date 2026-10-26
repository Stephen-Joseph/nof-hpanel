package actions

import (
	"bufio"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/rand"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
	"time"
)

// Helper to execute SQL statement safely via mysql stdin.
// Using stdin ensures query text and credentials do not appear in process tables (ps aux).
func runMariaDbSql(ctx context.Context, sqlQuery string, dryRun bool) ([]byte, error) {
	if dryRun || runtime.GOOS != "linux" {
		return []byte("simulated execution"), nil
	}

	// Locate debian.cnf or default mysql client config
	cnfPath := "/etc/mysql/debian.cnf"
	var cmd *exec.Cmd
	if _, err := os.Stat(cnfPath); err == nil {
		cmd = exec.CommandContext(ctx, "mysql", "--defaults-file="+cnfPath, "--batch", "--skip-column-names")
	} else {
		cmd = exec.CommandContext(ctx, "mysql", "--batch", "--skip-column-names")
	}

	cmd.Stdin = strings.NewReader(sqlQuery)
	var stdout bytes.Buffer
	var stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return nil, fmt.Errorf("mariadb query error: %v: %s", err, strings.TrimSpace(stderr.String()))
	}

	return stdout.Bytes(), nil
}

// -------------------------------------------------------------
// Action: db.create
// -------------------------------------------------------------

type DbCreatePayload struct {
	Username string `json:"username"`
	DbName   string `json:"db_name"`
	Charset  string `json:"charset,omitempty"`
	Collate  string `json:"collate,omitempty"`
}

type DbCreateAction struct{}

func NewDbCreateAction() *DbCreateAction {
	return &DbCreateAction{}
}

func (a *DbCreateAction) Name() string {
	return "db.create"
}

func (a *DbCreateAction) Validate(payload []byte) error {
	var p DbCreatePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}
	return ValidateDatabaseName(p.Username, p.DbName)
}

func (a *DbCreateAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p DbCreatePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if err := ValidateDatabaseName(p.Username, p.DbName); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	charset := "utf8mb4"
	if p.Charset != "" {
		charset = p.Charset
	}
	collate := "utf8mb4_unicode_ci"
	if p.Collate != "" {
		collate = p.Collate
	}

	logs := []string{fmt.Sprintf("creating database '%s' for user '%s'", p.DbName, p.Username)}

	sql := fmt.Sprintf("CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s;", p.DbName, charset, collate)
	if _, err := runMariaDbSql(ctx, sql, dryRun); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	logs = append(logs, fmt.Sprintf("successfully provisioned database '%s'", p.DbName))
	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"db_name": p.DbName,
			"charset": charset,
			"collate": collate,
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// Action: db.drop
// -------------------------------------------------------------

type DbDropPayload struct {
	Username string `json:"username"`
	DbName   string `json:"db_name"`
}

type DbDropAction struct{}

func NewDbDropAction() *DbDropAction {
	return &DbDropAction{}
}

func (a *DbDropAction) Name() string {
	return "db.drop"
}

func (a *DbDropAction) Validate(payload []byte) error {
	var p DbDropPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}
	return ValidateDatabaseName(p.Username, p.DbName)
}

func (a *DbDropAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p DbDropPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if err := ValidateDatabaseName(p.Username, p.DbName); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("dropping database '%s' for user '%s'", p.DbName, p.Username)}

	sql := fmt.Sprintf("DROP DATABASE IF EXISTS `%s`;", p.DbName)
	if _, err := runMariaDbSql(ctx, sql, dryRun); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	logs = append(logs, fmt.Sprintf("successfully dropped database '%s'", p.DbName))
	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"db_name": p.DbName,
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// Action: dbuser.create
// -------------------------------------------------------------

type DbUserCreatePayload struct {
	Username           string `json:"username"`
	DbUser             string `json:"db_user"`
	Password           string `json:"password"`
	RemoteHost         string `json:"remote_host,omitempty"`
	MaxUserConnections int    `json:"max_user_connections,omitempty"`
}

type DbUserCreateAction struct{}

func NewDbUserCreateAction() *DbUserCreateAction {
	return &DbUserCreateAction{}
}

func (a *DbUserCreateAction) Name() string {
	return "dbuser.create"
}

func (a *DbUserCreateAction) Validate(payload []byte) error {
	var p DbUserCreatePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}

	if err := ValidateDatabaseUsername(p.Username, p.DbUser); err != nil {
		return err
	}

	host := "localhost"
	if p.RemoteHost != "" {
		host = p.RemoteHost
	}
	if err := ValidateRemoteHost(host); err != nil {
		return err
	}

	if len(p.Password) < 8 {
		return errors.New("database user password must be at least 8 characters")
	}

	return nil
}

func (a *DbUserCreateAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p DbUserCreatePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	host := "localhost"
	if p.RemoteHost != "" {
		host = p.RemoteHost
	}

	if err := ValidateDatabaseUsername(p.Username, p.DbUser); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	if err := ValidateRemoteHost(host); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	maxConn := 20
	if p.MaxUserConnections > 0 {
		maxConn = p.MaxUserConnections
	}

	logs := []string{fmt.Sprintf("creating database user '%s'@'%s' (max_conn: %d)", p.DbUser, host, maxConn)}

	// Escape single quotes in password for safety
	escapedPassword := strings.ReplaceAll(p.Password, "'", "''")
	sql := fmt.Sprintf("CREATE USER IF NOT EXISTS '%s'@'%s' IDENTIFIED BY '%s' WITH MAX_USER_CONNECTIONS %d; FLUSH PRIVILEGES;",
		p.DbUser, host, escapedPassword, maxConn)

	if _, err := runMariaDbSql(ctx, sql, dryRun); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	logs = append(logs, fmt.Sprintf("successfully created database user '%s'@'%s'", p.DbUser, host))
	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"db_user":              p.DbUser,
			"host":                 host,
			"max_user_connections": maxConn,
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// Action: dbuser.set_password
// -------------------------------------------------------------

type DbUserSetPasswordPayload struct {
	Username   string `json:"username"`
	DbUser     string `json:"db_user"`
	Password   string `json:"password"`
	RemoteHost string `json:"remote_host,omitempty"`
}

type DbUserSetPasswordAction struct{}

func NewDbUserSetPasswordAction() *DbUserSetPasswordAction {
	return &DbUserSetPasswordAction{}
}

func (a *DbUserSetPasswordAction) Name() string {
	return "dbuser.set_password"
}

func (a *DbUserSetPasswordAction) Validate(payload []byte) error {
	var p DbUserSetPasswordPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}

	if err := ValidateDatabaseUsername(p.Username, p.DbUser); err != nil {
		return err
	}

	host := "localhost"
	if p.RemoteHost != "" {
		host = p.RemoteHost
	}
	if err := ValidateRemoteHost(host); err != nil {
		return err
	}

	if len(p.Password) < 8 {
		return errors.New("database password must be at least 8 characters")
	}

	return nil
}

func (a *DbUserSetPasswordAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p DbUserSetPasswordPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	host := "localhost"
	if p.RemoteHost != "" {
		host = p.RemoteHost
	}

	if err := ValidateDatabaseUsername(p.Username, p.DbUser); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	if err := ValidateRemoteHost(host); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("updating password for database user '%s'@'%s'", p.DbUser, host)}

	escapedPassword := strings.ReplaceAll(p.Password, "'", "''")
	sql := fmt.Sprintf("ALTER USER '%s'@'%s' IDENTIFIED BY '%s'; FLUSH PRIVILEGES;", p.DbUser, host, escapedPassword)

	if _, err := runMariaDbSql(ctx, sql, dryRun); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	logs = append(logs, fmt.Sprintf("successfully updated password for database user '%s'@'%s'", p.DbUser, host))
	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"db_user": p.DbUser,
			"host":    host,
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// Action: dbuser.grant
// -------------------------------------------------------------

type DbUserGrantPayload struct {
	Username   string   `json:"username"`
	DbUser     string   `json:"db_user"`
	DbName     string   `json:"db_name"`
	Privileges []string `json:"privileges"`
	RemoteHost string   `json:"remote_host,omitempty"`
}

type DbUserGrantAction struct{}

func NewDbUserGrantAction() *DbUserGrantAction {
	return &DbUserGrantAction{}
}

func (a *DbUserGrantAction) Name() string {
	return "dbuser.grant"
}

func (a *DbUserGrantAction) Validate(payload []byte) error {
	var p DbUserGrantPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}

	if err := ValidateDatabaseName(p.Username, p.DbName); err != nil {
		return err
	}
	if err := ValidateDatabaseUsername(p.Username, p.DbUser); err != nil {
		return err
	}

	host := "localhost"
	if p.RemoteHost != "" {
		host = p.RemoteHost
	}
	if err := ValidateRemoteHost(host); err != nil {
		return err
	}

	_, err := ValidatePrivileges(p.Privileges)
	return err
}

func (a *DbUserGrantAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p DbUserGrantPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	host := "localhost"
	if p.RemoteHost != "" {
		host = p.RemoteHost
	}

	if err := ValidateDatabaseName(p.Username, p.DbName); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	if err := ValidateDatabaseUsername(p.Username, p.DbUser); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	if err := ValidateRemoteHost(host); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	privs, err := ValidatePrivileges(p.Privileges)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	privStr := strings.Join(privs, ", ")
	logs := []string{fmt.Sprintf("granting privileges [%s] on '%s' to '%s'@'%s'", privStr, p.DbName, p.DbUser, host)}

	sql := fmt.Sprintf("GRANT %s ON `%s`.* TO '%s'@'%s'; FLUSH PRIVILEGES;", privStr, p.DbName, p.DbUser, host)
	if _, err := runMariaDbSql(ctx, sql, dryRun); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	logs = append(logs, fmt.Sprintf("successfully granted privileges to '%s'@'%s' on '%s'", p.DbUser, host, p.DbName))
	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"db_name":    p.DbName,
			"db_user":    p.DbUser,
			"host":       host,
			"privileges": privs,
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// Action: dbuser.revoke
// -------------------------------------------------------------

type DbUserRevokePayload struct {
	Username   string `json:"username"`
	DbUser     string `json:"db_user"`
	DbName     string `json:"db_name"`
	RemoteHost string `json:"remote_host,omitempty"`
}

type DbUserRevokeAction struct{}

func NewDbUserRevokeAction() *DbUserRevokeAction {
	return &DbUserRevokeAction{}
}

func (a *DbUserRevokeAction) Name() string {
	return "dbuser.revoke"
}

func (a *DbUserRevokeAction) Validate(payload []byte) error {
	var p DbUserRevokePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}

	if err := ValidateDatabaseName(p.Username, p.DbName); err != nil {
		return err
	}
	if err := ValidateDatabaseUsername(p.Username, p.DbUser); err != nil {
		return err
	}

	host := "localhost"
	if p.RemoteHost != "" {
		host = p.RemoteHost
	}
	return ValidateRemoteHost(host)
}

func (a *DbUserRevokeAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p DbUserRevokePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	host := "localhost"
	if p.RemoteHost != "" {
		host = p.RemoteHost
	}

	if err := ValidateDatabaseName(p.Username, p.DbName); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	if err := ValidateDatabaseUsername(p.Username, p.DbUser); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	if err := ValidateRemoteHost(host); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("revoking all privileges on '%s' from '%s'@'%s'", p.DbName, p.DbUser, host)}

	sql := fmt.Sprintf("REVOKE ALL PRIVILEGES ON `%s`.* FROM '%s'@'%s'; FLUSH PRIVILEGES;", p.DbName, p.DbUser, host)
	if _, err := runMariaDbSql(ctx, sql, dryRun); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	logs = append(logs, fmt.Sprintf("successfully revoked privileges on '%s' from '%s'@'%s'", p.DbName, p.DbUser, host))
	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"db_name": p.DbName,
			"db_user": p.DbUser,
			"host":    host,
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// Action: dbuser.drop
// -------------------------------------------------------------

type DbUserDropPayload struct {
	Username   string `json:"username"`
	DbUser     string `json:"db_user"`
	RemoteHost string `json:"remote_host,omitempty"`
}

type DbUserDropAction struct{}

func NewDbUserDropAction() *DbUserDropAction {
	return &DbUserDropAction{}
}

func (a *DbUserDropAction) Name() string {
	return "dbuser.drop"
}

func (a *DbUserDropAction) Validate(payload []byte) error {
	var p DbUserDropPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}

	if err := ValidateDatabaseUsername(p.Username, p.DbUser); err != nil {
		return err
	}

	host := "localhost"
	if p.RemoteHost != "" {
		host = p.RemoteHost
	}
	return ValidateRemoteHost(host)
}

func (a *DbUserDropAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p DbUserDropPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	host := "localhost"
	if p.RemoteHost != "" {
		host = p.RemoteHost
	}

	if err := ValidateDatabaseUsername(p.Username, p.DbUser); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	if err := ValidateRemoteHost(host); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("dropping database user '%s'@'%s'", p.DbUser, host)}

	sql := fmt.Sprintf("DROP USER IF EXISTS '%s'@'%s'; FLUSH PRIVILEGES;", p.DbUser, host)
	if _, err := runMariaDbSql(ctx, sql, dryRun); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	logs = append(logs, fmt.Sprintf("successfully dropped database user '%s'@'%s'", p.DbUser, host))
	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"db_user": p.DbUser,
			"host":    host,
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// Action: db.dump
// -------------------------------------------------------------

type DbDumpPayload struct {
	Username   string `json:"username"`
	DbName     string `json:"db_name"`
	OutputFile string `json:"output_file,omitempty"`
}

type DbDumpAction struct{}

func NewDbDumpAction() *DbDumpAction {
	return &DbDumpAction{}
}

func (a *DbDumpAction) Name() string {
	return "db.dump"
}

func (a *DbDumpAction) Validate(payload []byte) error {
	var p DbDumpPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}
	return ValidateDatabaseName(p.Username, p.DbName)
}

func (a *DbDumpAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p DbDumpPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if err := ValidateDatabaseName(p.Username, p.DbName); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	backupsDir := fmt.Sprintf("/home/%s/backups", p.Username)
	outputFile := p.OutputFile
	if outputFile == "" {
		outputFile = fmt.Sprintf("%s/%s_%s.sql", backupsDir, p.DbName, time.Now().Format("20060102_150405"))
	}

	// Verify target file path stays inside /home/<user>/backups
	cleanOut := filepath.Clean(outputFile)
	if !strings.HasPrefix(cleanOut, backupsDir) {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("output file must reside within '%s'", backupsDir),
		}, errors.New("invalid backup destination path")
	}

	logs := []string{fmt.Sprintf("exporting database '%s' to '%s'", p.DbName, cleanOut)}

	if dryRun || runtime.GOOS != "linux" {
		logs = append(logs, "[dry-run] simulated mysqldump")
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"db_name":     p.DbName,
				"output_file": cleanOut,
				"dry_run":     true,
			},
			Logs: logs,
		}, nil
	}

	// Ensure backups dir exists
	_ = os.MkdirAll(backupsDir, 0700)

	cnfPath := "/etc/mysql/debian.cnf"
	var dumpCmd *exec.Cmd
	if _, err := os.Stat(cnfPath); err == nil {
		dumpCmd = exec.CommandContext(ctx, "mysqldump", "--defaults-file="+cnfPath, "--single-transaction", "--quick", p.DbName)
	} else {
		dumpCmd = exec.CommandContext(ctx, "mysqldump", "--single-transaction", "--quick", p.DbName)
	}

	outFile, err := os.Create(cleanOut)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("failed to create dump file: %v", err), Logs: logs}, err
	}
	defer outFile.Close()

	dumpCmd.Stdout = outFile
	var stderr bytes.Buffer
	dumpCmd.Stderr = &stderr

	if err := dumpCmd.Run(); err != nil {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("mysqldump failed: %v: %s", err, strings.TrimSpace(stderr.String())),
			Logs:    logs,
		}, err
	}

	// Chown to customer user if on Linux
	_ = exec.CommandContext(ctx, "chown", fmt.Sprintf("%s:%s", p.Username, p.Username), cleanOut).Run()

	logs = append(logs, fmt.Sprintf("database '%s' exported successfully to '%s'", p.DbName, cleanOut))
	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"db_name":     p.DbName,
			"output_file": cleanOut,
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// SSO Helpers & Actions: db.sso_create, db.sso_revoke
// -------------------------------------------------------------

func generatePmaSsoCredentials() (string, string, error) {
	const userCharset = "abcdefghijklmnopqrstuvwxyz0123456789"
	userBytes := make([]byte, 10)
	if _, err := rand.Read(userBytes); err != nil {
		return "", "", fmt.Errorf("failed to generate random user bytes: %w", err)
	}
	for i := range userBytes {
		userBytes[i] = userCharset[int(userBytes[i])%len(userCharset)]
	}
	pmaUser := "pma_" + string(userBytes)

	const passCharset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"
	passBytes := make([]byte, 24)
	if _, err := rand.Read(passBytes); err != nil {
		return "", "", fmt.Errorf("failed to generate random password bytes: %w", err)
	}
	for i := range passBytes {
		passBytes[i] = passCharset[int(passBytes[i])%len(passCharset)]
	}
	pmaPass := string(passBytes)

	return pmaUser, pmaPass, nil
}

// -------------------------------------------------------------
// Action: db.sso_create
// -------------------------------------------------------------

type DbSsoCreatePayload struct {
	AccountUsername string `json:"account_username"`
	Database        string `json:"database,omitempty"`
}

type DbSsoCreateAction struct{}

func NewDbSsoCreateAction() *DbSsoCreateAction {
	return &DbSsoCreateAction{}
}

func (a *DbSsoCreateAction) Name() string {
	return "db.sso_create"
}

func (a *DbSsoCreateAction) Validate(payload []byte) error {
	var p DbSsoCreatePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}

	if err := ValidateLinuxUsername(p.AccountUsername); err != nil {
		return fmt.Errorf("invalid account_username: %w", err)
	}

	if p.Database != "" {
		if err := ValidateDatabaseName(p.AccountUsername, p.Database); err != nil {
			return fmt.Errorf("invalid database: %w", err)
		}
	}

	return nil
}

func (a *DbSsoCreateAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p DbSsoCreatePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if err := ValidateLinuxUsername(p.AccountUsername); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	if p.Database != "" {
		if err := ValidateDatabaseName(p.AccountUsername, p.Database); err != nil {
			return &ActionResult{Success: false, Error: err.Error()}, err
		}
	}

	pmaUser, pmaPass, err := generatePmaSsoCredentials()
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{
		fmt.Sprintf("creating temporary MariaDB SSO user for account '%s'", p.AccountUsername),
	}

	if dryRun || runtime.GOOS != "linux" {
		logs = append(logs, fmt.Sprintf("granted ALL on `%s\\_%%`.* to '%s'@'localhost' (simulated)", p.AccountUsername, pmaUser))
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"db_user":     pmaUser,
				"db_password": pmaPass,
				"database":    p.Database,
				"dry_run":     true,
			},
			Logs: logs,
		}, nil
	}

	// Strictly grant only on the account's database prefix pattern <account>\_%
	// Do not grant global privileges or SHOW DATABASES. Limit connections to 2.
	sql := fmt.Sprintf(
		"CREATE USER IF NOT EXISTS '%s'@'localhost' IDENTIFIED BY '%s'; "+
			"GRANT ALL ON `%s\\_%%`.* TO '%s'@'localhost' WITH MAX_USER_CONNECTIONS 2; "+
			"FLUSH PRIVILEGES;",
		pmaUser, pmaPass, p.AccountUsername, pmaUser,
	)

	if _, err := runMariaDbSql(ctx, sql, false); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	logs = append(logs, fmt.Sprintf("granted ALL on `%s\\_%%`.* to temporary user '%s'@'localhost'", p.AccountUsername, pmaUser))

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"db_user":     pmaUser,
			"db_password": pmaPass,
			"database":    p.Database,
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// Action: db.sso_revoke
// -------------------------------------------------------------

var pmaUserPattern = regexp.MustCompile(`^pma_[a-zA-Z0-9]{6,24}$`)

type DbSsoRevokePayload struct {
	DbUser string `json:"db_user"`
}

type DbSsoRevokeAction struct{}

func NewDbSsoRevokeAction() *DbSsoRevokeAction {
	return &DbSsoRevokeAction{}
}

func (a *DbSsoRevokeAction) Name() string {
	return "db.sso_revoke"
}

func (a *DbSsoRevokeAction) Validate(payload []byte) error {
	var p DbSsoRevokePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}

	if !pmaUserPattern.MatchString(p.DbUser) {
		return errors.New("invalid db_user: must match ^pma_[a-zA-Z0-9]{6,24}$")
	}

	return nil
}

func (a *DbSsoRevokeAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p DbSsoRevokePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if !pmaUserPattern.MatchString(p.DbUser) {
		return &ActionResult{Success: false, Error: "invalid db_user"}, errors.New("invalid db_user")
	}

	logs := []string{fmt.Sprintf("revoking temporary MariaDB SSO user '%s'", p.DbUser)}

	if dryRun || runtime.GOOS != "linux" {
		logs = append(logs, "[dry-run] simulated drop temporary pma user")
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"db_user": p.DbUser,
				"revoked": true,
				"dry_run": true,
			},
			Logs: logs,
		}, nil
	}

	sql := fmt.Sprintf("DROP USER IF EXISTS '%s'@'localhost'; FLUSH PRIVILEGES;", p.DbUser)
	if _, err := runMariaDbSql(ctx, sql, false); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	logs = append(logs, fmt.Sprintf("successfully dropped temporary user '%s'@'localhost'", p.DbUser))
	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"db_user": p.DbUser,
			"revoked": true,
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// Action: db.import
// -------------------------------------------------------------

const MaxImportSizeBytes int64 = 500 * 1024 * 1024 // 500 MB

var (
	reForbiddenCreateUser   = regexp.MustCompile(`(?i)^\s*CREATE\s+USER\b`)
	reForbiddenGrant        = regexp.MustCompile(`(?i)^\s*GRANT\s+`)
	reForbiddenDropDatabase = regexp.MustCompile(`(?i)^\s*DROP\s+DATABASE\s+(?:IF\s+EXISTS\s+)?[\x60"']?([a-zA-Z0-9_]+)[\x60"']?`)
	reForbiddenUseDatabase  = regexp.MustCompile(`(?i)^\s*USE\s+[\x60"']?([a-zA-Z0-9_]+)[\x60"']?`)
)

func checkSqlLineAllowed(line string, targetDb string) error {
	trimmed := strings.TrimSpace(line)
	if trimmed == "" || strings.HasPrefix(trimmed, "--") || strings.HasPrefix(trimmed, "#") {
		return nil
	}

	if reForbiddenCreateUser.MatchString(trimmed) {
		return errors.New("forbidden SQL statement: CREATE USER is not allowed")
	}
	if reForbiddenGrant.MatchString(trimmed) {
		return errors.New("forbidden SQL statement: GRANT is not allowed")
	}
	if m := reForbiddenDropDatabase.FindStringSubmatch(trimmed); len(m) > 1 {
		db := m[1]
		if db != targetDb {
			return fmt.Errorf("forbidden SQL statement: DROP DATABASE on unauthorized database '%s'", db)
		}
	}
	if m := reForbiddenUseDatabase.FindStringSubmatch(trimmed); len(m) > 1 {
		db := m[1]
		if db != targetDb {
			return fmt.Errorf("forbidden SQL statement: USE on unauthorized database '%s'", db)
		}
	}
	return nil
}

func streamAndValidateSql(ctx context.Context, src io.Reader, dst io.Writer, targetDb string, maxSize int64) error {
	br := bufio.NewReader(src)
	var totalBytes int64
	inLongLine := false

	for {
		select {
		case <-ctx.Done():
			return ctx.Err()
		default:
		}

		line, isPrefix, err := br.ReadLine()
		if len(line) > 0 {
			totalBytes += int64(len(line))
			if totalBytes > maxSize {
				return fmt.Errorf("SQL dump exceeds maximum allowed size of %d MB", maxSize/(1024*1024))
			}

			// Validate line if it starts a statement
			if !inLongLine {
				if errCheck := checkSqlLineAllowed(string(line), targetDb); errCheck != nil {
					return errCheck
				}
			}

			if _, wErr := dst.Write(line); wErr != nil {
				return wErr
			}
		}

		if err != nil {
			if errors.Is(err, io.EOF) {
				break
			}
			return err
		}

		if isPrefix {
			inLongLine = true
		} else {
			inLongLine = false
			totalBytes++
			if totalBytes > maxSize {
				return fmt.Errorf("SQL dump exceeds maximum allowed size of %d MB", maxSize/(1024*1024))
			}
			if _, wErr := dst.Write([]byte("\n")); wErr != nil {
				return wErr
			}
		}
	}

	return nil
}

type DbImportPayload struct {
	Username  string `json:"username"`
	DbName    string `json:"db_name"`
	InputFile string `json:"input_file"`
}

type DbImportAction struct{}

func NewDbImportAction() *DbImportAction {
	return &DbImportAction{}
}

func (a *DbImportAction) Name() string {
	return "db.import"
}

func (a *DbImportAction) Validate(payload []byte) error {
	var p DbImportPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}

	if err := ValidateDatabaseName(p.Username, p.DbName); err != nil {
		return err
	}

	if p.InputFile == "" {
		return errors.New("input_file is required")
	}

	return nil
}

func (a *DbImportAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p DbImportPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if err := ValidateDatabaseName(p.Username, p.DbName); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	userHome := fmt.Sprintf("/home/%s", p.Username)
	cleanInput := filepath.Clean(p.InputFile)
	if !strings.HasPrefix(cleanInput, userHome+"/") && cleanInput != userHome {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("input file '%s' must reside inside customer home '%s'", cleanInput, userHome),
		}, errors.New("unauthorized input file path")
	}

	// Verify symlinks do not escape customer home
	if realPath, err := filepath.EvalSymlinks(cleanInput); err == nil {
		if !strings.HasPrefix(realPath, userHome+"/") && realPath != userHome {
			return &ActionResult{
				Success: false,
				Error:   fmt.Sprintf("symlink input file '%s' resolves outside customer home", cleanInput),
			}, errors.New("symlink traversal detected")
		}
	}

	logs := []string{fmt.Sprintf("importing SQL dump '%s' into database '%s'", cleanInput, p.DbName)}

	fi, err := os.Stat(cleanInput)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("cannot open import file: %v", err), Logs: logs}, err
	}
	if fi.Size() > MaxImportSizeBytes {
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("input file size (%d bytes) exceeds maximum limit of 500 MB", fi.Size()),
			Logs:    logs,
		}, errors.New("file size limit exceeded")
	}

	inFile, err := os.Open(cleanInput)
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("cannot open import file: %v", err), Logs: logs}, err
	}
	defer inFile.Close()

	if dryRun || runtime.GOOS != "linux" {
		var checkReader io.Reader = inFile
		if strings.HasSuffix(strings.ToLower(cleanInput), ".gz") {
			gzReader, gzErr := gzip.NewReader(inFile)
			if gzErr != nil {
				return &ActionResult{Success: false, Error: fmt.Sprintf("cannot decompress gzip file: %v", gzErr), Logs: logs}, gzErr
			}
			defer gzReader.Close()
			checkReader = gzReader
		}
		var nullBuf bytes.Buffer
		if err := streamAndValidateSql(ctx, checkReader, &nullBuf, p.DbName, MaxImportSizeBytes); err != nil {
			return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
		}

		logs = append(logs, "[dry-run] simulated mysql import with restricted user")
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"db_name":    p.DbName,
				"input_file": cleanInput,
				"dry_run":    true,
			},
			Logs: logs,
		}, nil
	}

	// Set a 15-minute execution timeout for database import
	importCtx, cancel := context.WithTimeout(ctx, 15*time.Minute)
	defer cancel()

	// Create temporary restricted MariaDB user with access strictly on p.DbName
	tmpUser, tmpPass, err := generatePmaSsoCredentials()
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("failed to generate import credentials: %v", err), Logs: logs}, err
	}
	tmpUser = "imp_" + tmpUser[4:]

	createUserSql := fmt.Sprintf(
		"CREATE USER IF NOT EXISTS '%s'@'localhost' IDENTIFIED BY '%s'; "+
			"GRANT ALL ON `%s`.* TO '%s'@'localhost'; "+
			"FLUSH PRIVILEGES;",
		tmpUser, tmpPass, p.DbName, tmpUser,
	)
	if _, err := runMariaDbSql(importCtx, createUserSql, false); err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("failed to provision import user: %v", err), Logs: logs}, err
	}

	// Create temporary client cnf file with 0600 mode
	tempCnf, err := os.CreateTemp("", "hpanel-imp-*.cnf")
	if err != nil {
		cleanupSql := fmt.Sprintf("DROP USER IF EXISTS '%s'@'localhost'; FLUSH PRIVILEGES;", tmpUser)
		_, _ = runMariaDbSql(context.Background(), cleanupSql, false)
		return &ActionResult{Success: false, Error: fmt.Sprintf("failed to create temporary mysql cnf: %v", err), Logs: logs}, err
	}
	tempCnfPath := tempCnf.Name()
	_ = os.Chmod(tempCnfPath, 0600)
	cnfContent := fmt.Sprintf("[client]\nuser=%s\npassword=%s\nhost=127.0.0.1\n", tmpUser, tmpPass)
	_, _ = tempCnf.WriteString(cnfContent)
	_ = tempCnf.Close()

	defer func() {
		_ = os.Remove(tempCnfPath)
		cleanupSql := fmt.Sprintf("DROP USER IF EXISTS '%s'@'localhost'; FLUSH PRIVILEGES;", tmpUser)
		_, _ = runMariaDbSql(context.Background(), cleanupSql, false)
	}()

	var sqlReader io.Reader = inFile
	if strings.HasSuffix(strings.ToLower(cleanInput), ".gz") {
		gzReader, err := gzip.NewReader(inFile)
		if err != nil {
			return &ActionResult{Success: false, Error: fmt.Sprintf("cannot decompress gzip file: %v", err), Logs: logs}, err
		}
		defer gzReader.Close()
		sqlReader = gzReader
	}

	importCmd := exec.CommandContext(importCtx, "mysql", "--defaults-file="+tempCnfPath, p.DbName)
	pr, pw := io.Pipe()
	importCmd.Stdin = pr
	var stderr bytes.Buffer
	importCmd.Stderr = &stderr

	go func() {
		err := streamAndValidateSql(importCtx, sqlReader, pw, p.DbName, MaxImportSizeBytes)
		if err != nil {
			_ = pw.CloseWithError(err)
		} else {
			_ = pw.Close()
		}
	}()

	if err := importCmd.Run(); err != nil {
		errOutput := strings.TrimSpace(stderr.String())
		if errOutput == "" {
			errOutput = err.Error()
		}
		// Truncate error output to 500 characters
		if len(errOutput) > 500 {
			errOutput = errOutput[:500] + "..."
		}
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("mysql import failed: %s", errOutput),
			Logs:    logs,
		}, err
	}

	logs = append(logs, fmt.Sprintf("database '%s' imported successfully from '%s'", p.DbName, cleanInput))
	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"db_name":    p.DbName,
			"input_file": cleanInput,
		},
		Logs: logs,
	}, nil
}

// -------------------------------------------------------------
// Action: db.list_usage
// -------------------------------------------------------------

type DbListUsagePayload struct {
	Username string `json:"username"`
}

type DbUsageItem struct {
	DbName    string  `json:"db_name"`
	SizeBytes int64   `json:"size_bytes"`
	SizeMB    float64 `json:"size_mb"`
}

type DbListUsageAction struct{}

func NewDbListUsageAction() *DbListUsageAction {
	return &DbListUsageAction{}
}

func (a *DbListUsageAction) Name() string {
	return "db.list_usage"
}

func (a *DbListUsageAction) Validate(payload []byte) error {
	var p DbListUsagePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}

	if !reLinuxUser.MatchString(p.Username) {
		return fmt.Errorf("invalid username: '%s'", p.Username)
	}

	return nil
}

func (a *DbListUsageAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p DbListUsagePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	if !reLinuxUser.MatchString(p.Username) {
		return &ActionResult{Success: false, Error: "invalid username"}, errors.New("invalid username")
	}

	logs := []string{fmt.Sprintf("querying database storage usage for user '%s'", p.Username)}

	if dryRun || runtime.GOOS != "linux" {
		logs = append(logs, "[dry-run] simulated usage data")
		mockUsages := []DbUsageItem{
			{DbName: p.Username + "_app", SizeBytes: 10485760, SizeMB: 10.0},
		}
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username":  p.Username,
				"databases": mockUsages,
				"dry_run":   true,
			},
			Logs: logs,
		}, nil
	}

	sql := fmt.Sprintf(
		"SELECT table_schema, COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema LIKE '%s_%%' GROUP BY table_schema;",
		p.Username,
	)

	out, err := runMariaDbSql(ctx, sql, false)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	var usages []DbUsageItem
	lines := strings.Split(strings.TrimSpace(string(out)), "\n")
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		parts := strings.Fields(line)
		if len(parts) >= 2 {
			dbName := parts[0]
			var bytesVal int64
			_, _ = fmt.Sscanf(parts[1], "%d", &bytesVal)
			usages = append(usages, DbUsageItem{
				DbName:    dbName,
				SizeBytes: bytesVal,
				SizeMB:    float64(bytesVal) / (1024 * 1024),
			})
		}
	}

	logs = append(logs, fmt.Sprintf("retrieved usage for %d databases", len(usages)))
	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username":  p.Username,
			"databases": usages,
		},
		Logs: logs,
	}, nil
}
