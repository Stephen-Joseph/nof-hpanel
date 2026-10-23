package actions

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
)

func TestUsageValidation(t *testing.T) {
	if err := ValidateUsageCollectRequest("alicecorp", []string{"alicecorp.com"}, []string{"alicecorp_wp"}); err != nil {
		t.Errorf("expected valid usage collect request, got: %v", err)
	}

	if err := ValidateUsageCollectRequest("invalid-user!", []string{"alicecorp.com"}, nil); err == nil {
		t.Error("expected error on invalid username")
	}

	if err := ValidateUsageCollectRequest("alicecorp", []string{"invalid..domain"}, nil); err == nil {
		t.Error("expected error on invalid domain")
	}

	if err := ValidateUsageCollectRequest("alicecorp", nil, []string{"invalid;db"}); err == nil {
		t.Error("expected error on invalid db name")
	}

	if err := ValidateLogOffset(1024); err != nil {
		t.Errorf("expected valid offset, got: %v", err)
	}

	if err := ValidateLogOffset(-1); err == nil {
		t.Error("expected error on negative offset")
	}
}

func TestExtractBytesSentFromLogLine(t *testing.T) {
	tests := []struct {
		line      string
		wantBytes int64
	}{
		{
			line:      `192.168.1.1 - - [23/Oct/2026:10:00:00 +0000] "GET /index.php HTTP/1.1" 200 4520 "-" "curl/7.68.0"`,
			wantBytes: 4520,
		},
		{
			line:      `10.0.0.2 - admin [23/Oct/2026:10:05:00 +0000] "POST /api/upload HTTP/1.1" 201 1048576 "-" "Postman"`,
			wantBytes: 1048576,
		},
		{
			line:      `10.0.0.3 - - [23/Oct/2026:10:06:00 +0000] "GET /notfound HTTP/1.1" 404 153 "-" "Mozilla/5.0"`,
			wantBytes: 153,
		},
		{
			line:      `malformed line without quotes`,
			wantBytes: 0,
		},
	}

	for _, tt := range tests {
		got := extractBytesSentFromLogLine(tt.line)
		if got != tt.wantBytes {
			t.Errorf("extractBytesSentFromLogLine(%q) = %d, want %d", tt.line, got, tt.wantBytes)
		}
	}
}

func TestParseAccessLogBandwidthWithOffset(t *testing.T) {
	tmpDir, err := os.MkdirTemp("", "test_logs_*")
	if err != nil {
		t.Fatalf("failed to create temp dir: %v", err)
	}
	defer os.RemoveAll(tmpDir)

	logFile := filepath.Join(tmpDir, "example.com_access.log")

	chunk1 := "192.168.1.1 - - [23/Oct/2026:10:00:00 +0000] \"GET /file1 HTTP/1.1\" 200 1000 \"-\" \"curl\"\n"
	chunk2 := "192.168.1.1 - - [23/Oct/2026:10:01:00 +0000] \"GET /file2 HTTP/1.1\" 200 2500 \"-\" \"curl\"\n"

	_ = os.WriteFile(logFile, []byte(chunk1), 0644)

	// First pass: from offset 0
	bytesSent1, offset1 := parseAccessLogBandwidth(logFile, 0)
	if bytesSent1 != 1000 {
		t.Errorf("expected 1000 bytes, got %d", bytesSent1)
	}
	if offset1 <= 0 {
		t.Errorf("expected positive offset, got %d", offset1)
	}

	// Append chunk2
	f, _ := os.OpenFile(logFile, os.O_APPEND|os.O_WRONLY, 0644)
	_, _ = f.WriteString(chunk2)
	_ = f.Close()

	// Second pass: from offset1
	bytesSent2, offset2 := parseAccessLogBandwidth(logFile, offset1)
	if bytesSent2 != 2500 {
		t.Errorf("expected 2500 bytes for chunk 2, got %d", bytesSent2)
	}
	if offset2 <= offset1 {
		t.Errorf("expected offset2 (%d) > offset1 (%d)", offset2, offset1)
	}

	// Third pass without changes: should be 0 bytes
	bytesSent3, _ := parseAccessLogBandwidth(logFile, offset2)
	if bytesSent3 != 0 {
		t.Errorf("expected 0 new bytes, got %d", bytesSent3)
	}
}

func TestUsageCollectActionDryRun(t *testing.T) {
	ctx := context.Background()
	action := NewUsageCollectAction()

	payload, _ := json.Marshal(UsageCollectPayload{
		Username:   "alicecorp",
		Domains:    []string{"alicecorp.com", "blog.alicecorp.com"},
		Databases:  []string{"alicecorp_wp"},
		LogOffsets: map[string]int64{"alicecorp.com": 500},
	})

	if err := action.Validate(payload); err != nil {
		t.Fatalf("validation failed: %v", err)
	}

	res, err := action.Execute(ctx, payload, true)
	if err != nil || !res.Success {
		t.Fatalf("execute dry-run failed: %v, res: %+v", err, res)
	}

	data := res.Data
	if data["storage_bytes"].(int64) <= 0 {
		t.Errorf("expected positive storage_bytes, got %v", data["storage_bytes"])
	}
	if data["inodes"].(int64) <= 0 {
		t.Errorf("expected positive inodes, got %v", data["inodes"])
	}
	if data["bandwidth_bytes_total"].(int64) <= 0 {
		t.Errorf("expected positive bandwidth_bytes_total, got %v", data["bandwidth_bytes_total"])
	}
}

