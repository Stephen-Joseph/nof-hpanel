package actions

import (
	"context"
	"encoding/json"
	"testing"
)

func TestValidateSnapshotID(t *testing.T) {
	tests := []struct {
		name       string
		snapshotID string
		wantErr    bool
	}{
		{"valid short hex id", "a1b2c3d4", false},
		{"valid full 64 char hex id", "a1b2c3d4e5f67890123456789abcdef0123456789abcdef0123456789abcdef0", false},
		{"empty string", "", true},
		{"too short", "abc", true},
		{"invalid characters", "a1b2c3g4", true},
		{"uppercase hex", "A1B2C3D4", true},
		{"path traversal attempt", "../a1b2c3d4", true},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			err := ValidateSnapshotID(tt.snapshotID)
			if (err != nil) != tt.wantErr {
				t.Errorf("ValidateSnapshotID(%q) error = %v, wantErr %v", tt.snapshotID, err, tt.wantErr)
			}
		})
	}
}

func TestValidateRestoreRequest(t *testing.T) {
	tests := []struct {
		name        string
		username    string
		snapshotID  string
		restoreType string
		targetPath  string
		targetDB    string
		wantErr     bool
	}{
		{"valid full restore", "alicecorp", "a1b2c3d4", "full", "", "", false},
		{"valid files_only restore", "alicecorp", "a1b2c3d4", "files_only", "", "", false},
		{"valid databases_only restore", "alicecorp", "a1b2c3d4", "databases_only", "", "", false},
		{"valid single_db restore", "alicecorp", "a1b2c3d4", "single_db", "", "alicecorp_wp", false},
		{"missing single_db target", "alicecorp", "a1b2c3d4", "single_db", "", "", true},
		{"invalid single_db prefix", "alicecorp", "a1b2c3d4", "single_db", "", "otheruser_wp", true},
		{"valid single_path inside home", "alicecorp", "a1b2c3d4", "single_path", "/home/alicecorp/public_html", "", false},
		{"escaping single_path", "alicecorp", "a1b2c3d4", "single_path", "/etc/nginx", "", true},
		{"unknown restore type", "alicecorp", "a1b2c3d4", "invalid_type", "", "", true},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			err := ValidateRestoreRequest(tt.username, tt.snapshotID, tt.restoreType, tt.targetPath, tt.targetDB)
			if (err != nil) != tt.wantErr {
				t.Errorf("ValidateRestoreRequest error = %v, wantErr %v", err, tt.wantErr)
			}
		})
	}
}

func TestBackupActionsDryRun(t *testing.T) {
	ctx := context.Background()

	// 1. backup.create
	createAction := NewBackupCreateAction()
	createPayload, _ := json.Marshal(BackupCreatePayload{
		Username:       "alicecorp",
		BackupType:     "manual",
		Databases:      []string{"alicecorp_blog"},
		RetentionCount: 5,
	})
	if err := createAction.Validate(createPayload); err != nil {
		t.Fatalf("BackupCreate validation failed: %v", err)
	}
	res, err := createAction.Execute(ctx, createPayload, true)
	if err != nil || !res.Success {
		t.Fatalf("BackupCreate execution failed: %v, res: %+v", err, res)
	}

	// 2. backup.list
	listAction := NewBackupListAction()
	listPayload, _ := json.Marshal(BackupListPayload{Username: "alicecorp"})
	if err := listAction.Validate(listPayload); err != nil {
		t.Fatalf("BackupList validation failed: %v", err)
	}
	res, err = listAction.Execute(ctx, listPayload, true)
	if err != nil || !res.Success {
		t.Fatalf("BackupList execution failed: %v, res: %+v", err, res)
	}

	// 3. backup.restore
	restoreAction := NewBackupRestoreAction()
	restorePayload, _ := json.Marshal(BackupRestorePayload{
		Username:    "alicecorp",
		SnapshotID:  "a1b2c3d4",
		RestoreType: "full",
	})
	if err := restoreAction.Validate(restorePayload); err != nil {
		t.Fatalf("BackupRestore validation failed: %v", err)
	}
	res, err = restoreAction.Execute(ctx, restorePayload, true)
	if err != nil || !res.Success {
		t.Fatalf("BackupRestore execution failed: %v, res: %+v", err, res)
	}

	// 4. backup.verify
	verifyAction := NewBackupVerifyAction()
	verifyPayload, _ := json.Marshal(BackupVerifyPayload{Username: "alicecorp"})
	if err := verifyAction.Validate(verifyPayload); err != nil {
		t.Fatalf("BackupVerify validation failed: %v", err)
	}
	res, err = verifyAction.Execute(ctx, verifyPayload, true)
	if err != nil || !res.Success {
		t.Fatalf("BackupVerify execution failed: %v, res: %+v", err, res)
	}

	// 5. backup.delete
	deleteAction := NewBackupDeleteAction()
	deletePayload, _ := json.Marshal(BackupDeletePayload{
		Username:   "alicecorp",
		SnapshotID: "a1b2c3d4",
	})
	if err := deleteAction.Validate(deletePayload); err != nil {
		t.Fatalf("BackupDelete validation failed: %v", err)
	}
	res, err = deleteAction.Execute(ctx, deletePayload, true)
	if err != nil || !res.Success {
		t.Fatalf("BackupDelete execution failed: %v, res: %+v", err, res)
	}
}
