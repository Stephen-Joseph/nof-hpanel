package actions

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
)

// -------------------------------------------------------------
// Action: usage.collect
// -------------------------------------------------------------

type UsageCollectPayload struct {
	Username   string           `json:"username"`
	Domains    []string         `json:"domains,omitempty"`
	Databases  []string         `json:"databases,omitempty"`
	LogOffsets map[string]int64 `json:"log_offsets,omitempty"`
}

type UsageCollectAction struct{}

func NewUsageCollectAction() *UsageCollectAction {
	return &UsageCollectAction{}
}

func (a *UsageCollectAction) Name() string {
	return "usage.collect"
}

func (a *UsageCollectAction) Validate(payload []byte) error {
	var p UsageCollectPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("invalid json payload: %w", err)
	}

	if err := ValidateUsageCollectRequest(p.Username, p.Domains, p.Databases); err != nil {
		return err
	}

	for domain, offset := range p.LogOffsets {
		if err := ValidateDomain(domain); err != nil {
			return fmt.Errorf("invalid domain key '%s' in log_offsets: %w", domain, err)
		}
		if err := ValidateLogOffset(offset); err != nil {
			return fmt.Errorf("invalid log offset for '%s': %w", domain, err)
		}
	}

	return nil
}

func (a *UsageCollectAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p UsageCollectPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("collecting resource usage metrics for user '%s'", p.Username)}

	if dryRun || runtime.GOOS != "linux" {
		domainBw := make(map[string]int64)
		newOffsets := make(map[string]int64)
		var totalBw int64 = 0

		for _, dom := range p.Domains {
			lastOffset := int64(0)
			if p.LogOffsets != nil {
				lastOffset = p.LogOffsets[dom]
			}
			newBytes := int64(1048576) // 1 MB simulated delta
			domainBw[dom] = newBytes
			totalBw += newBytes
			newOffsets[dom] = lastOffset + newBytes
		}

		dbBytes := make(map[string]int64)
		var totalDbBytes int64 = 0
		for _, db := range p.Databases {
			size := int64(10485760) // 10 MB per db
			dbBytes[db] = size
			totalDbBytes += size
		}

		logs = append(logs,
			"[dry-run] simulated storage metric: 256.00 MB",
			"[dry-run] simulated inodes metric: 4,120",
			fmt.Sprintf("[dry-run] simulated bandwidth parsed across %d domains", len(p.Domains)),
		)

		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"username":               p.Username,
				"storage_bytes":          int64(268435456), // 256 MB
				"storage_mb":             256.0,
				"inodes":                 int64(4120),
				"bandwidth_bytes_total":  totalBw,
				"domain_bandwidth":       domainBw,
				"new_log_offsets":        newOffsets,
				"database_bytes_total":   totalDbBytes,
				"database_bytes":         dbBytes,
				"fpm_active_workers":     1,
				"fpm_idle_workers":       3,
				"fpm_total_workers":      4,
				"dry_run":                true,
			},
			Logs: logs,
		}, nil
	}

	homeDir := fmt.Sprintf("/home/%s", p.Username)

	// Step 1: Disk storage (via du -sb)
	var storageBytes int64 = 0
	duCmd := exec.CommandContext(ctx, "du", "-sb", homeDir)
	if duOut, err := duCmd.Output(); err == nil {
		parts := strings.Fields(string(duOut))
		if len(parts) > 0 {
			if s, parseErr := strconv.ParseInt(parts[0], 10, 64); parseErr == nil {
				storageBytes = s
			}
		}
	}
	storageMB := float64(storageBytes) / (1024 * 1024)

	// Step 2: Inodes count (via find | wc -l or du --inodes -s)
	var inodesCount int64 = 0
	inodeCmd := exec.CommandContext(ctx, "du", "--inodes", "-s", homeDir)
	if inodeOut, err := inodeCmd.Output(); err == nil {
		parts := strings.Fields(string(inodeOut))
		if len(parts) > 0 {
			if count, parseErr := strconv.ParseInt(parts[0], 10, 64); parseErr == nil {
				inodesCount = count
			}
		}
	}

	// Step 3: Parse Nginx Access Logs for Bandwidth with Offset Tracking
	domainBw := make(map[string]int64)
	newOffsets := make(map[string]int64)
	var totalBandwidthBytes int64 = 0

	for _, domain := range p.Domains {
		logFile := filepath.Join(homeDir, "logs", fmt.Sprintf("%s_access.log", domain))
		lastOffset := int64(0)
		if p.LogOffsets != nil {
			lastOffset = p.LogOffsets[domain]
		}

		bytesSent, newOffset := parseAccessLogBandwidth(logFile, lastOffset)
		domainBw[domain] = bytesSent
		totalBandwidthBytes += bytesSent
		newOffsets[domain] = newOffset
	}

	// Step 4: Calculate Database Sizes from MariaDB
	dbBytesMap := make(map[string]int64)
	var totalDbBytes int64 = 0

	for _, dbName := range p.Databases {
		sql := fmt.Sprintf("SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = '%s';", dbName)
		mysqlCmd := exec.CommandContext(ctx, "mysql", "-N", "-e", sql)
		if out, err := mysqlCmd.Output(); err == nil {
			sizeStr := strings.TrimSpace(string(out))
			if parsedSize, parseErr := strconv.ParseInt(sizeStr, 10, 64); parseErr == nil {
				dbBytesMap[dbName] = parsedSize
				totalDbBytes += parsedSize
			}
		}
	}

	// Step 5: Count FPM Worker Processes
	var fpmWorkers int = 0
	pgrepCmd := exec.CommandContext(ctx, "pgrep", "-u", p.Username, "-c", "php-fpm")
	if out, err := pgrepCmd.Output(); err == nil {
		if count, parseErr := strconv.Atoi(strings.TrimSpace(string(out))); parseErr == nil {
			fpmWorkers = count
		}
	}

	logs = append(logs, fmt.Sprintf("metrics collected: %.2f MB storage, %d inodes, %d bytes bandwidth", storageMB, inodesCount, totalBandwidthBytes))

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"username":              p.Username,
			"storage_bytes":         storageBytes,
			"storage_mb":            storageMB,
			"inodes":                inodesCount,
			"bandwidth_bytes_total": totalBandwidthBytes,
			"domain_bandwidth":      domainBw,
			"new_log_offsets":       newOffsets,
			"database_bytes_total":  totalDbBytes,
			"database_bytes":        dbBytesMap,
			"fpm_active_workers":    fpmWorkers,
			"fpm_idle_workers":      0,
			"fpm_total_workers":     fpmWorkers,
		},
		Logs: logs,
	}, nil
}

// parseAccessLogBandwidth reads new log entries from startOffset to EOF, summing response body bytes sent.
func parseAccessLogBandwidth(logPath string, startOffset int64) (int64, int64) {
	fileInfo, err := os.Stat(logPath)
	if err != nil {
		return 0, 0
	}

	currentSize := fileInfo.Size()
	// If file was rotated or truncated, reset offset to beginning
	if startOffset > currentSize {
		startOffset = 0
	}

	f, err := os.Open(logPath)
	if err != nil {
		return 0, 0
	}
	defer f.Close()

	if _, err := f.Seek(startOffset, io.SeekStart); err != nil {
		return 0, currentSize
	}

	var totalBytes int64 = 0
	scanner := bufio.NewScanner(f)
	for scanner.Scan() {
		line := scanner.Text()
		if bytesSent := extractBytesSentFromLogLine(line); bytesSent > 0 {
			totalBytes += bytesSent
		}
	}

	return totalBytes, currentSize
}

// extractBytesSentFromLogLine extracts the body_bytes_sent field from Nginx combined log format.
// Format: $remote_addr - $remote_user [$time_local] "$request" $status $body_bytes_sent "$http_referer" "$http_user_agent"
func extractBytesSentFromLogLine(line string) int64 {
	// Find closing quote of the request section: "... HTTP/1.1"
	lastQuoteIdx := strings.LastIndex(line, "\"")
	if lastQuoteIdx == -1 {
		return 0
	}

	afterRequest := strings.TrimSpace(line[lastQuoteIdx+1:])
	fields := strings.Fields(afterRequest)
	// fields[0] is HTTP status, fields[1] is body_bytes_sent
	if len(fields) >= 2 {
		if b, err := strconv.ParseInt(fields[1], 10, 64); err == nil && b > 0 {
			return b
		}
	}

	return 0
}

