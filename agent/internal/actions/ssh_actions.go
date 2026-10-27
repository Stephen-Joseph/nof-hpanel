package actions

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
)

// --- SSH Key Add Action ---

type SshKeyAddPayload struct {
	Username   string `json:"username"`
	PublicKey  string `json:"public_key"`
	Name       string `json:"name,omitempty"`
	SSHEnabled bool   `json:"ssh_enabled,omitempty"`
}

type SshKeyAddAction struct{}

func NewSshKeyAddAction() *SshKeyAddAction { return &SshKeyAddAction{} }

func (a *SshKeyAddAction) Name() string { return "ssh.key_add" }

func (a *SshKeyAddAction) Validate(payload []byte) error {
	var p SshKeyAddPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	_, err := ValidateAndParseSSHPublicKey(p.PublicKey)
	return err
}

func (a *SshKeyAddAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p SshKeyAddPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	keyInfo, err := ValidateAndParseSSHPublicKey(p.PublicKey)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{
		fmt.Sprintf("adding SSH key '%s' (type: %s, fp: %s) for user '%s' (shell enabled: %v)",
			p.Name, keyInfo.KeyType, keyInfo.Fingerprint, p.Username, p.SSHEnabled),
	}

	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username":    p.Username,
				"name":        p.Name,
				"key_type":    keyInfo.KeyType,
				"fingerprint": keyInfo.Fingerprint,
				"ssh_enabled": p.SSHEnabled,
				"dry_run":     true,
			},
			Logs: logs,
		}, nil
	}

	if err := WriteAuthorizedKey(p.Username, keyInfo, p.SSHEnabled); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username":    p.Username,
			"name":        p.Name,
			"key_type":    keyInfo.KeyType,
			"fingerprint": keyInfo.Fingerprint,
			"ssh_enabled": p.SSHEnabled,
		},
		Logs: logs,
	}, nil
}

// --- SSH Key Remove Action ---

type SshKeyRemovePayload struct {
	Username  string `json:"username"`
	PublicKey string `json:"public_key"`
}

type SshKeyRemoveAction struct{}

func NewSshKeyRemoveAction() *SshKeyRemoveAction { return &SshKeyRemoveAction{} }

func (a *SshKeyRemoveAction) Name() string { return "ssh.key_remove" }

func (a *SshKeyRemoveAction) Validate(payload []byte) error {
	var p SshKeyRemovePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	_, err := ValidateAndParseSSHPublicKey(p.PublicKey)
	return err
}

func (a *SshKeyRemoveAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p SshKeyRemovePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	keyInfo, err := ValidateAndParseSSHPublicKey(p.PublicKey)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("removing SSH key with fingerprint '%s' for user '%s'", keyInfo.Fingerprint, p.Username)}

	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username":    p.Username,
				"fingerprint": keyInfo.Fingerprint,
				"removed":     true,
				"dry_run":     true,
			},
			Logs: logs,
		}, nil
	}

	if err := RemoveAuthorizedKeyByBlob(p.Username, keyInfo.BlobBase64); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username":    p.Username,
			"fingerprint": keyInfo.Fingerprint,
			"removed":     true,
		},
		Logs: logs,
	}, nil
}

// --- SFTP Sub-Account Actions ---

type SftpAccountCreatePayload struct {
	ParentUser string `json:"parent_user"`
	Subuser    string `json:"subuser"`
	Homedir    string `json:"homedir"`
	Password   string `json:"password,omitempty"`
}

type SftpAccountCreateAction struct {
	executor      commandExecutor
	sshdConfigDir string
}

func NewSftpAccountCreateAction() *SftpAccountCreateAction {
	return &SftpAccountCreateAction{
		executor:      osCommandExecutor{},
		sshdConfigDir: "/etc/ssh/sshd_config.d",
	}
}

func NewSftpAccountCreateActionForTest(executor commandExecutor, sshdConfigDir string) *SftpAccountCreateAction {
	return &SftpAccountCreateAction{
		executor:      executor,
		sshdConfigDir: sshdConfigDir,
	}
}

func (a *SftpAccountCreateAction) Name() string { return "sftp.account_create" }

func (a *SftpAccountCreateAction) Validate(payload []byte) error {
	var p SftpAccountCreatePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.ParentUser); err != nil {
		return err
	}
	if _, err := ResolveSafePath(p.ParentUser, p.Homedir); err != nil {
		return fmt.Errorf("sub-account homedir must be located inside parent home: %w", err)
	}
	return nil
}

func (a *SftpAccountCreateAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p SftpAccountCreatePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	safeHomedir, err := ResolveSafePath(p.ParentUser, p.Homedir)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("configuring SFTP sub-account '%s' restricted to %s", p.Subuser, safeHomedir)}

	if dryRun {
		logs = append(logs, fmt.Sprintf("[dry-run] simulated sshd match block for subuser %s", p.Subuser))
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"parent_user": p.ParentUser,
				"subuser":     p.Subuser,
				"homedir":     safeHomedir,
				"configured":  true,
				"dry_run":     true,
			},
			Logs: logs,
		}, nil
	}

	sshdDir := a.sshdConfigDir
	if sshdDir == "" {
		sshdDir = "/etc/ssh/sshd_config.d"
	}
	if err := os.MkdirAll(sshdDir, 0755); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	confPath := filepath.Join(sshdDir, fmt.Sprintf("hpanel-subuser-%s.conf", p.Subuser))
	content := fmt.Sprintf(`# HPanel SFTP access for subuser %s
# Note: ChrootDirectory is omitted in this phase due to home ownership (0711).
# A dedicated chroot layout (/var/chroot/%s) will be introduced in a future release.
Match User %s
    ForceCommand internal-sftp -u 022
    AllowTcpForwarding no
    X11Forwarding no
    PasswordAuthentication yes
`, p.Subuser, p.Subuser, p.Subuser)

	if err := atomicWriteFile(confPath, []byte(content), 0644, 0, 0); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	executor := a.executor
	if executor == nil {
		executor = osCommandExecutor{}
	}

	if out, err := executor.CombinedOutput(ctx, "sshd", "-t"); err != nil {
		_ = os.Remove(confPath)
		return &ActionResult{
			Success: false,
			Error:   fmt.Sprintf("sshd -t validation failed: %s", string(out)),
		}, fmt.Errorf("sshd -t validation failed: %s: %w", string(out), err)
	}

	if err := executor.Run(ctx, "systemctl", "reload", "ssh"); err != nil {
		_ = executor.Run(ctx, "systemctl", "reload", "sshd")
	}

	logs = append(logs, fmt.Sprintf("sshd match block configured and reloaded for subuser '%s'", p.Subuser))

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"parent_user": p.ParentUser,
			"subuser":     p.Subuser,
			"homedir":     safeHomedir,
			"configured":  true,
			"dry_run":     false,
		},
		Logs: logs,
	}, nil
}
