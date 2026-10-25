package actions

import (
	"context"
	"encoding/json"
	"testing"
)

func TestHtpasswdWriteAndValidate(t *testing.T) {
	ctx := context.Background()

	writeAction := NewHtpasswdWriteAction()

	// Valid payload
	payload, _ := json.Marshal(HtpasswdWritePayload{
		Username: "alicecorp",
		Filename: "admin_area.htpasswd",
		Entries: []HtpasswdEntry{
			{User: "john", Hash: "$2y$10$abcdefghijklmnopqrstuvwxyz123456"},
			{User: "mary_admin", Hash: "$apr1$xyz$1234567890"},
		},
	})

	if err := writeAction.Validate(payload); err != nil {
		t.Fatalf("validation failed: %v", err)
	}

	res, err := writeAction.Execute(ctx, payload, true)
	if err != nil || !res.Success {
		t.Fatalf("execute dry-run failed: %v, res: %+v", err, res)
	}

	if res.Data["user_count"] != 2 {
		t.Fatalf("expected user_count 2, got %v", res.Data["user_count"])
	}

	// Invalid payload: path traversal filename
	badPayload, _ := json.Marshal(HtpasswdWritePayload{
		Username: "alicecorp",
		Filename: "../etc/passwd.htpasswd",
		Entries: []HtpasswdEntry{
			{User: "john", Hash: "hash123"},
		},
	})
	if err := writeAction.Validate(badPayload); err == nil {
		t.Fatal("expected error on path traversal filename")
	}

	// Invalid payload: bad user characters
	badUserPayload, _ := json.Marshal(HtpasswdWritePayload{
		Username: "alicecorp",
		Filename: "admin.htpasswd",
		Entries: []HtpasswdEntry{
			{User: "john:root", Hash: "hash123"},
		},
	})
	if err := writeAction.Validate(badUserPayload); err == nil {
		t.Fatal("expected error on invalid username with colon")
	}

	// Delete action test
	deleteAction := NewHtpasswdDeleteAction()
	delPayload, _ := json.Marshal(HtpasswdDeletePayload{
		Username: "alicecorp",
		Filename: "admin_area.htpasswd",
	})

	if err := deleteAction.Validate(delPayload); err != nil {
		t.Fatalf("delete validation failed: %v", err)
	}

	delRes, err := deleteAction.Execute(ctx, delPayload, true)
	if err != nil || !delRes.Success {
		t.Fatalf("delete execute dry-run failed: %v, res: %+v", err, delRes)
	}
}
