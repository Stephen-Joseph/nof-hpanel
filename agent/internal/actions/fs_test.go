package actions

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"testing"
)

func TestFsPathTraversal(t *testing.T) {
	tempBase := t.TempDir()
	username := "cust01"
	homeDir := filepath.Join(tempBase, username)
	if err := os.MkdirAll(homeDir, 0711); err != nil {
		t.Fatalf("failed creating test home dir: %v", err)
	}

	traversalAttacks := []string{
		"../../etc/passwd",
		"/etc/passwd",
		"%2e%2e/etc/passwd",
		"public_html/%2e%2e/%2e%2e/etc/shadow",
		"/home/cust01/../../etc/shadow",
		"%2fetc%2fpasswd",
		"..\x00/etc/hosts",
	}

	ctx := context.Background()
	listAction := NewFsListActionWithBase(tempBase)
	readAction := NewFsReadTextActionWithBase(tempBase)
	writeAction := NewFsWriteTextActionWithBase(tempBase)
	delAction := NewFsDeleteActionWithBase(tempBase)

	for _, attack := range traversalAttacks {
		// Test list validation or execution
		listPayload, _ := json.Marshal(FsListPayload{Username: username, Path: attack})
		if err := listAction.Validate(listPayload); err == nil {
			if res, err := listAction.Execute(ctx, listPayload, false); err == nil && res.Success {
				t.Errorf("expected list to reject traversal attack '%s', but succeeded", attack)
			}
		}

		// Test read validation or execution
		readPayload, _ := json.Marshal(FsReadTextPayload{Username: username, Path: attack})
		if err := readAction.Validate(readPayload); err == nil {
			if res, err := readAction.Execute(ctx, readPayload, false); err == nil && res.Success {
				t.Errorf("expected read_text to reject traversal attack '%s', but succeeded", attack)
			}
		}

		// Test write validation or execution
		writePayload, _ := json.Marshal(FsWriteTextPayload{Username: username, Path: attack, Content: "bad"})
		if err := writeAction.Validate(writePayload); err == nil {
			if res, err := writeAction.Execute(ctx, writePayload, false); err == nil && res.Success {
				t.Errorf("expected write_text to reject traversal attack '%s', but succeeded", attack)
			}
		}

		// Test delete validation or execution
		delPayload, _ := json.Marshal(FsDeletePayload{Username: username, Path: attack})
		if err := delAction.Validate(delPayload); err == nil {
			if res, err := delAction.Execute(ctx, delPayload, false); err == nil && res.Success {
				t.Errorf("expected delete to reject traversal attack '%s', but succeeded", attack)
			}
		}
	}
}

func TestFsSymlinkEscape(t *testing.T) {
	tempBase := t.TempDir()
	username := "cust01"
	homeDir := filepath.Join(tempBase, username)
	_ = os.MkdirAll(homeDir, 0711)

	// Create an external directory outside home
	outsideDir := filepath.Join(tempBase, "external_system_dir")
	_ = os.MkdirAll(outsideDir, 0755)
	outsideFile := filepath.Join(outsideDir, "secret.conf")
	_ = os.WriteFile(outsideFile, []byte("TOP_SECRET_CONFIG"), 0644)

	// Create a symlink inside user home pointing to outside directory
	symlinkPath := filepath.Join(homeDir, "outside_link")
	if err := os.Symlink(outsideDir, symlinkPath); err != nil {
		t.Fatalf("failed creating test symlink: %v", err)
	}

	ctx := context.Background()

	// 1. fs.list on the symlink target directly must be rejected because it escapes home
	listAction := NewFsListActionWithBase(tempBase)
	listSymlinkPayload, _ := json.Marshal(FsListPayload{Username: username, Path: "outside_link"})
	if _, err := listAction.Execute(ctx, listSymlinkPayload, false); err == nil {
		t.Fatalf("expected fs.list on escaping symlink to fail, but succeeded")
	}

	// 2. fs.list on home directory must list the symlink as type 'symlink' without following it
	listHomePayload, _ := json.Marshal(FsListPayload{Username: username, Path: ""})
	listRes, err := listAction.Execute(ctx, listHomePayload, false)
	if err != nil || !listRes.Success {
		t.Fatalf("fs.list home failed: %v", err)
	}
	entries := listRes.Data["entries"].([]FsEntry)
	foundSymlink := false
	for _, e := range entries {
		if e.Name == "outside_link" {
			foundSymlink = true
			if e.Type != "symlink" {
				t.Errorf("expected outside_link to have type 'symlink', got '%s'", e.Type)
			}
		}
	}
	if !foundSymlink {
		t.Errorf("expected outside_link to be listed in home entries")
	}

	// 3. fs.read_text on file through symlink must be rejected
	readAction := NewFsReadTextActionWithBase(tempBase)
	readPayload, _ := json.Marshal(FsReadTextPayload{Username: username, Path: "outside_link/secret.conf"})
	if _, err := readAction.Execute(ctx, readPayload, false); err == nil {
		t.Fatalf("expected fs.read_text through symlink to fail, but succeeded")
	}

	// 4. fs.write_text on file through symlink must be rejected
	writeAction := NewFsWriteTextActionWithBase(tempBase)
	writePayload, _ := json.Marshal(FsWriteTextPayload{Username: username, Path: "outside_link/hacked.txt", Content: "evil"})
	if _, err := writeAction.Execute(ctx, writePayload, false); err == nil {
		t.Fatalf("expected fs.write_text through symlink to fail, but succeeded")
	}

	// 5. fs.rename escaping symlink must be rejected
	renameAction := NewFsRenameActionWithBase(tempBase)
	renamePayload, _ := json.Marshal(FsRenamePayload{Username: username, SourcePath: "outside_link/secret.conf", TargetPath: "stolen.conf"})
	if _, err := renameAction.Execute(ctx, renamePayload, false); err == nil {
		t.Fatalf("expected fs.rename through symlink to fail, but succeeded")
	}

	// 6. fs.delete on the symlink itself must remove ONLY the symlink, NOT the outside directory or file
	delAction := NewFsDeleteActionWithBase(tempBase)
	delPayload, _ := json.Marshal(FsDeletePayload{Username: username, Path: "outside_link"})
	delRes, err := delAction.Execute(ctx, delPayload, false)
	if err != nil || !delRes.Success {
		t.Fatalf("fs.delete on symlink failed: %v", err)
	}
	if _, err := os.Lstat(symlinkPath); !os.IsNotExist(err) {
		t.Errorf("expected symlink file to be removed")
	}
	if _, err := os.Stat(outsideFile); err != nil {
		t.Errorf("outside file was deleted! Target outside home must NEVER be deleted: %v", err)
	}
}

func TestFsDeleteRefusesHomeRoot(t *testing.T) {
	tempBase := t.TempDir()
	username := "cust01"
	homeDir := filepath.Join(tempBase, username)
	_ = os.MkdirAll(homeDir, 0711)

	delAction := NewFsDeleteActionWithBase(tempBase)
	ctx := context.Background()

	// Attempt to delete home directory itself
	payloads := []string{
		"",
		homeDir,
		".",
	}

	for _, p := range payloads {
		delPayload, _ := json.Marshal(FsDeletePayload{Username: username, Path: p})
		res, err := delAction.Execute(ctx, delPayload, false)
		if err == nil && res.Success {
			t.Fatalf("expected fs.delete on home root '%s' to fail, but succeeded", p)
		}
	}

	// Verify home directory still exists
	if _, err := os.Stat(homeDir); err != nil {
		t.Fatalf("home directory was deleted: %v", err)
	}
}

func TestFsReadTextBinaryAndOversize(t *testing.T) {
	tempBase := t.TempDir()
	username := "cust01"
	homeDir := filepath.Join(tempBase, username)
	_ = os.MkdirAll(homeDir, 0711)

	ctx := context.Background()
	readAction := NewFsReadTextActionWithBase(tempBase)

	// 1. Binary file with NUL byte
	binPath := filepath.Join(homeDir, "binary.bin")
	_ = os.WriteFile(binPath, []byte("Hello\x00World!"), 0644)

	binPayload, _ := json.Marshal(FsReadTextPayload{Username: username, Path: "binary.bin"})
	if res, err := readAction.Execute(ctx, binPayload, false); err == nil && res.Success {
		t.Fatalf("expected fs.read_text on binary file to fail, but succeeded")
	}

	// 2. Oversize file (> 1 MB)
	oversizePath := filepath.Join(homeDir, "large.txt")
	largeData := bytes.Repeat([]byte("A"), 1024*1024+100)
	_ = os.WriteFile(oversizePath, largeData, 0644)

	largePayload, _ := json.Marshal(FsReadTextPayload{Username: username, Path: "large.txt"})
	if res, err := readAction.Execute(ctx, largePayload, false); err == nil && res.Success {
		t.Fatalf("expected fs.read_text on oversize file to fail, but succeeded")
	}

	// 3. Normal text file (< 1 MB)
	normalPath := filepath.Join(homeDir, "normal.txt")
	normalContent := "Valid text content for SFTP editor\n"
	_ = os.WriteFile(normalPath, []byte(normalContent), 0644)

	normalPayload, _ := json.Marshal(FsReadTextPayload{Username: username, Path: "normal.txt"})
	res, err := readAction.Execute(ctx, normalPayload, false)
	if err != nil || !res.Success {
		t.Fatalf("fs.read_text on normal text file failed: %v", err)
	}
	if res.Data["content"] != normalContent {
		t.Errorf("expected content %q, got %q", normalContent, res.Data["content"])
	}
}

func TestFsWriteTextAtomic(t *testing.T) {
	tempBase := t.TempDir()
	username := "cust01"
	homeDir := filepath.Join(tempBase, username)
	_ = os.MkdirAll(homeDir, 0711)

	ctx := context.Background()
	writeAction := NewFsWriteTextActionWithBase(tempBase)

	filePath := filepath.Join(homeDir, "test.txt")
	content := "Atomic write test content"
	payload, _ := json.Marshal(FsWriteTextPayload{Username: username, Path: "test.txt", Content: content})

	res, err := writeAction.Execute(ctx, payload, false)
	if err != nil || !res.Success {
		t.Fatalf("fs.write_text failed: %v", err)
	}

	readBytes, err := os.ReadFile(filePath)
	if err != nil {
		t.Fatalf("failed reading written file: %v", err)
	}
	if string(readBytes) != content {
		t.Errorf("expected written content %q, got %q", content, string(readBytes))
	}

	fi, _ := os.Stat(filePath)
	if fi.Mode().Perm() != 0644 {
		t.Errorf("expected file mode 0644, got %04o", fi.Mode().Perm())
	}
}

func TestFsMkdirAndCreateFileAndMoveCopy(t *testing.T) {
	tempBase := t.TempDir()
	username := "cust01"
	homeDir := filepath.Join(tempBase, username)
	_ = os.MkdirAll(homeDir, 0711)

	ctx := context.Background()
	mkdirAction := NewFsMkdirActionWithBase(tempBase)
	createAction := NewFsCreateFileActionWithBase(tempBase)
	copyAction := NewFsCopyActionWithBase(tempBase)
	moveAction := NewFsMoveActionWithBase(tempBase)

	// Mkdir
	mkdirPayload, _ := json.Marshal(FsMkdirPayload{Username: username, Path: "my_folder"})
	if res, err := mkdirAction.Execute(ctx, mkdirPayload, false); err != nil || !res.Success {
		t.Fatalf("fs.mkdir failed: %v", err)
	}
	dirFi, _ := os.Stat(filepath.Join(homeDir, "my_folder"))
	if dirFi.Mode().Perm() != 0755 {
		t.Errorf("expected dir mode 0755, got %04o", dirFi.Mode().Perm())
	}

	// Create file
	createPayload, _ := json.Marshal(FsCreateFilePayload{Username: username, Path: "my_folder/app.js"})
	if res, err := createAction.Execute(ctx, createPayload, false); err != nil || !res.Success {
		t.Fatalf("fs.create_file failed: %v", err)
	}
	fileFi, _ := os.Stat(filepath.Join(homeDir, "my_folder", "app.js"))
	if fileFi.Mode().Perm() != 0644 {
		t.Errorf("expected file mode 0644, got %04o", fileFi.Mode().Perm())
	}

	// Copy
	copyPayload, _ := json.Marshal(FsCopyPayload{Username: username, SourcePath: "my_folder", TargetPath: "my_folder_copy"})
	if res, err := copyAction.Execute(ctx, copyPayload, false); err != nil || !res.Success {
		t.Fatalf("fs.copy failed: %v", err)
	}
	if _, err := os.Stat(filepath.Join(homeDir, "my_folder_copy", "app.js")); err != nil {
		t.Fatalf("copied file missing: %v", err)
	}

	// Move
	movePayload, _ := json.Marshal(FsMovePayload{Username: username, SourcePath: "my_folder_copy/app.js", TargetPath: "app_moved.js"})
	if res, err := moveAction.Execute(ctx, movePayload, false); err != nil || !res.Success {
		t.Fatalf("fs.move failed: %v", err)
	}
	if _, err := os.Stat(filepath.Join(homeDir, "app_moved.js")); err != nil {
		t.Fatalf("moved file missing: %v", err)
	}
}

func TestFsUploadChunksAndQuotaRejection(t *testing.T) {
	tempBase := t.TempDir()
	username := "cust01"
	homeDir := filepath.Join(tempBase, username)
	_ = os.MkdirAll(homeDir, 0711)

	ctx := context.Background()
	beginAction := NewFsUploadBeginActionWithBase(tempBase)
	chunkAction := NewFsUploadChunkAction()
	commitAction := NewFsUploadCommitAction()

	// 1. Quota rejection test
	quotaRejectPayload, _ := json.Marshal(FsUploadBeginPayload{
		Username:       username,
		DestinationDir: "",
		Filename:       "huge.tar",
		TotalSize:      5000000,
		QuotaRemaining: 1000000, // less than TotalSize
	})
	if err := beginAction.Validate(quotaRejectPayload); err == nil {
		t.Fatalf("expected validation error for upload exceeding quota, got nil")
	}

	// 2. Successful chunked upload
	chunk1Data := []byte("Hello chunk 1. ")
	chunk2Data := []byte("Hello chunk 2. Completed upload!")
	totalData := append(chunk1Data, chunk2Data...)

	beginPayload, _ := json.Marshal(FsUploadBeginPayload{
		Username:       username,
		DestinationDir: "",
		Filename:       "chunked_file.txt",
		TotalSize:      int64(len(totalData)),
		QuotaRemaining: 50000000,
	})
	if err := beginAction.Validate(beginPayload); err != nil {
		t.Fatalf("beginAction validate failed: %v", err)
	}
	beginRes, err := beginAction.Execute(ctx, beginPayload, false)
	if err != nil || !beginRes.Success {
		t.Fatalf("beginAction execute failed: %v", err)
	}
	sessionID := beginRes.Data["session_id"].(string)

	// Send chunk 1
	chunk1Payload, _ := json.Marshal(FsUploadChunkPayload{
		Username:      username,
		SessionID:     sessionID,
		ChunkIndex:    0,
		ContentBase64: base64.StdEncoding.EncodeToString(chunk1Data),
	})
	if res, err := chunkAction.Execute(ctx, chunk1Payload, false); err != nil || !res.Success {
		t.Fatalf("upload chunk 1 failed: %v", err)
	}

	// Send chunk 2
	chunk2Payload, _ := json.Marshal(FsUploadChunkPayload{
		Username:      username,
		SessionID:     sessionID,
		ChunkIndex:    1,
		ContentBase64: base64.StdEncoding.EncodeToString(chunk2Data),
	})
	if res, err := chunkAction.Execute(ctx, chunk2Payload, false); err != nil || !res.Success {
		t.Fatalf("upload chunk 2 failed: %v", err)
	}

	// Commit upload
	commitPayload, _ := json.Marshal(FsUploadCommitPayload{
		Username:  username,
		SessionID: sessionID,
	})
	commitRes, err := commitAction.Execute(ctx, commitPayload, false)
	if err != nil || !commitRes.Success {
		t.Fatalf("upload commit failed: %v", err)
	}

	finalPath := filepath.Join(homeDir, "chunked_file.txt")
	readContent, err := os.ReadFile(finalPath)
	if err != nil {
		t.Fatalf("final uploaded file missing: %v", err)
	}
	if !bytes.Equal(readContent, totalData) {
		t.Errorf("expected assembled file %q, got %q", string(totalData), string(readContent))
	}
	fi, _ := os.Stat(finalPath)
	if fi.Mode().Perm() != 0644 {
		t.Errorf("expected uploaded file mode 0644, got %04o", fi.Mode().Perm())
	}
}

func TestFsDryRun(t *testing.T) {
	ctx := context.Background()
	username := "bob"

	actions := []Action{
		NewFsListAction(),
		NewFsStatAction(),
		NewFsMkdirAction(),
		NewFsCreateFileAction(),
		NewFsRenameAction(),
		NewFsMoveAction(),
		NewFsCopyAction(),
		NewFsDeleteAction(),
		NewFsReadTextAction(),
		NewFsWriteTextAction(),
		NewFsUploadBeginAction(),
		NewFsUploadChunkAction(),
		NewFsUploadCommitAction(),
	}

	payloads := map[string][]byte{
		"fs.list":          []byte(fmt.Sprintf(`{"username":"%s","path":""}`, username)),
		"fs.stat":          []byte(fmt.Sprintf(`{"username":"%s","path":"file.txt"}`, username)),
		"fs.mkdir":         []byte(fmt.Sprintf(`{"username":"%s","path":"dir"}`, username)),
		"fs.create_file":   []byte(fmt.Sprintf(`{"username":"%s","path":"file.txt"}`, username)),
		"fs.rename":        []byte(fmt.Sprintf(`{"username":"%s","source_path":"a","target_path":"b"}`, username)),
		"fs.move":          []byte(fmt.Sprintf(`{"username":"%s","source_path":"a","target_path":"b"}`, username)),
		"fs.copy":          []byte(fmt.Sprintf(`{"username":"%s","source_path":"a","target_path":"b"}`, username)),
		"fs.delete":        []byte(fmt.Sprintf(`{"username":"%s","path":"file.txt"}`, username)),
		"fs.read_text":     []byte(fmt.Sprintf(`{"username":"%s","path":"file.txt"}`, username)),
		"fs.write_text":    []byte(fmt.Sprintf(`{"username":"%s","path":"file.txt","content":"hello"}`, username)),
		"fs.upload_begin":  []byte(fmt.Sprintf(`{"username":"%s","destination_dir":"","filename":"a.txt","total_size":10}`, username)),
		"fs.upload_chunk":  []byte(fmt.Sprintf(`{"username":"%s","session_id":"sess123","chunk_index":0,"content_base64":"aGVsbG8="}`, username)),
		"fs.upload_commit": []byte(fmt.Sprintf(`{"username":"%s","session_id":"sess123"}`, username)),
	}

	for _, act := range actions {
		pl := payloads[act.Name()]
		if pl == nil {
			t.Fatalf("missing payload for %s", act.Name())
		}
		res, err := act.Execute(ctx, pl, true)
		if err != nil || !res.Success {
			t.Fatalf("dry run failed for %s: %v", act.Name(), err)
		}
	}
}
