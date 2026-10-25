package actions

import (
	"context"
	"encoding/json"
	"testing"
)

func TestValidateLogFile(t *testing.T) {
	tests := []struct {
		name    string
		user    string
		file    string
		wantErr bool
	}{
		{"valid domain access log", "alicecorp", "alicecorp.com.access.log", false},
		{"valid domain error log", "alicecorp", "alicecorp.com.error.log", false},
		{"valid php error log", "alicecorp", "php_error.log", false},
		{"valid cron log", "alicecorp", "cron_1.log", false},
		{"valid rotated log", "alicecorp", "php_error.log.1", false},
		{"path traversal slash", "alicecorp", "../../../etc/passwd.log", true},
		{"backslash traversal", "alicecorp", "..\\..\\windows.log", true},
		{"dot dot injection", "alicecorp", "something..log", true},
		{"non-log extension", "alicecorp", "config.php", true},
		{"empty file", "alicecorp", "", true},
		{"invalid username", "alice-corp", "app.log", true},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			_, err := ValidateLogFile(tt.user, tt.file)
			if (err != nil) != tt.wantErr {
				t.Errorf("ValidateLogFile(%q, %q) error = %v, wantErr %v", tt.user, tt.file, err, tt.wantErr)
			}
		})
	}
}

func TestLogActionsDryRun(t *testing.T) {
	ctx := context.Background()

	// log.tail
	tailAction := NewLogTailAction()
	tailPayload, _ := json.Marshal(LogTailPayload{
		Username: "alicecorp",
		LogFile:  "alicecorp.com.access.log",
		Lines:    50,
		Search:   "GET",
	})
	if err := tailAction.Validate(tailPayload); err != nil {
		t.Fatalf("LogTail validation failed: %v", err)
	}
	res, err := tailAction.Execute(ctx, tailPayload, true)
	if err != nil || !res.Success {
		t.Fatalf("LogTail execute failed: %v, res: %+v", err, res)
	}

	// log.list
	listAction := NewLogListAction()
	listPayload, _ := json.Marshal(LogListPayload{Username: "alicecorp"})
	if err := listAction.Validate(listPayload); err != nil {
		t.Fatalf("LogList validation failed: %v", err)
	}
	res, err = listAction.Execute(ctx, listPayload, true)
	if err != nil || !res.Success {
		t.Fatalf("LogList execute failed: %v, res: %+v", err, res)
	}

	// log.rotate
	rotateAction := NewLogRotateAction()
	rotatePayload, _ := json.Marshal(LogRotatePayload{Username: "alicecorp", Force: false})
	if err := rotateAction.Validate(rotatePayload); err != nil {
		t.Fatalf("LogRotate validation failed: %v", err)
	}
	res, err = rotateAction.Execute(ctx, rotatePayload, true)
	if err != nil || !res.Success {
		t.Fatalf("LogRotate execute failed: %v, res: %+v", err, res)
	}
}
