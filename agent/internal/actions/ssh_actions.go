package actions

import (
	"context"
	"encoding/json"
	"fmt"
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

type SftpAccountCreateAction struct{}

func NewSftpAccountCreateAction() *SftpAccountCreateAction { return &SftpAccountCreateAction{} }

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

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"parent_user": p.ParentUser,
			"subuser":     p.Subuser,
			"homedir":     safeHomedir,
			"configured":  true,
			"dry_run":     dryRun,
		},
		Logs: logs,
	}, nil
}
