package actions

import (
	"archive/tar"
	"archive/zip"
	"compress/gzip"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

// --- File Archive Action ---

type FileArchivePayload struct {
	Username       string   `json:"username"`
	Sources        []string `json:"sources"`
	DestinationZip string   `json:"destination_zip"`
}

type FileArchiveAction struct{}

func NewFileArchiveAction() *FileArchiveAction { return &FileArchiveAction{} }

func (a *FileArchiveAction) Name() string { return "file.archive" }

func (a *FileArchiveAction) Validate(payload []byte) error {
	var p FileArchivePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if len(p.Sources) == 0 {
		return fmt.Errorf("at least one source file or directory must be specified")
	}
	for _, src := range p.Sources {
		if _, err := ResolveSafePath(p.Username, src); err != nil {
			return fmt.Errorf("invalid source '%s': %w", src, err)
		}
	}
	if _, err := ResolveSafePath(p.Username, p.DestinationZip); err != nil {
		return fmt.Errorf("invalid destination_zip '%s': %w", p.DestinationZip, err)
	}
	if !strings.HasSuffix(strings.ToLower(p.DestinationZip), ".zip") {
		return fmt.Errorf("destination must have a .zip extension")
	}
	return nil
}

func (a *FileArchiveAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FileArchivePayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	destSafe, err := ResolveSafePath(p.Username, p.DestinationZip)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("compressing %d items into %s", len(p.Sources), destSafe)}

	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"destination": destSafe,
				"compressed":  true,
				"dry_run":     true,
			},
			Logs: logs,
		}, nil
	}

	destDir := filepath.Dir(destSafe)
	if err := os.MkdirAll(destDir, 0755); err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("creating destination directory: %v", err), Logs: logs}, err
	}

	// Create temp file in destination directory
	tmpFile, err := os.CreateTemp(destDir, "."+filepath.Base(destSafe)+".tmp-*")
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("creating temp archive file: %v", err), Logs: logs}, err
	}
	tmpPath := tmpFile.Name()

	cleanup := true
	defer func() {
		if cleanup {
			_ = os.Remove(tmpPath)
		}
	}()

	w := zip.NewWriter(tmpFile)

	for _, src := range p.Sources {
		srcSafe, err := ResolveSafePath(p.Username, src)
		if err != nil {
			continue
		}

		baseDir := filepath.Dir(srcSafe)

		_ = filepath.Walk(srcSafe, func(path string, info os.FileInfo, err error) error {
			if err != nil || path == destSafe || path == tmpPath {
				return nil
			}

			// Security: verify each walked path resolves safely inside user home
			safePath, err := ResolveSafePath(p.Username, path)
			if err != nil {
				return nil // Skip paths escaping home
			}

			// If it's a symlink, verify its target does not escape home
			if info.Mode()&os.ModeSymlink != 0 {
				linkTarget, readErr := os.Readlink(path)
				if readErr != nil {
					return nil
				}
				if filepath.IsAbs(linkTarget) {
					if _, err := ResolveSafePath(p.Username, linkTarget); err != nil {
						return nil // Skip absolute symlinks pointing outside home
					}
				} else {
					targetResolved := filepath.Join(filepath.Dir(path), linkTarget)
					if _, err := ResolveSafePath(p.Username, targetResolved); err != nil {
						return nil // Skip relative symlinks pointing outside home
					}
				}
			}

			relPath, err := filepath.Rel(baseDir, safePath)
			if err != nil {
				return nil
			}

			header, err := zip.FileInfoHeader(info)
			if err != nil {
				return nil
			}
			header.Name = filepath.ToSlash(relPath)
			if info.IsDir() {
				header.Name += "/"
			} else {
				header.Method = zip.Deflate
			}

			writer, err := w.CreateHeader(header)
			if err != nil {
				return nil
			}

			if info.IsDir() {
				return nil
			}

			if info.Mode()&os.ModeSymlink != 0 {
				linkTarget, err := os.Readlink(path)
				if err == nil {
					_, _ = writer.Write([]byte(linkTarget))
				}
				return nil
			}

			file, err := os.Open(path)
			if err != nil {
				return nil
			}
			defer file.Close()

			_, _ = io.Copy(writer, file)
			return nil
		})
	}

	if err := w.Close(); err != nil {
		_ = tmpFile.Close()
		return &ActionResult{Success: false, Error: fmt.Sprintf("closing zip writer: %v", err), Logs: logs}, err
	}

	if err := tmpFile.Sync(); err != nil {
		_ = tmpFile.Close()
		return &ActionResult{Success: false, Error: fmt.Sprintf("syncing temp archive: %v", err), Logs: logs}, err
	}
	_ = tmpFile.Close()

	if err := CommitConfigFile(tmpPath, destSafe, 0644); err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("committing archive file: %v", err), Logs: logs}, err
	}
	cleanup = false

	_ = exec.CommandContext(ctx, "chown", fmt.Sprintf("%s:%s", p.Username, p.Username), destSafe).Run()

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"destination": destSafe,
			"compressed":  true,
		},
		Logs: logs,
	}, nil
}

// --- File Extract Action ---

type FileExtractPayload struct {
	Username       string `json:"username"`
	ArchivePath    string `json:"archive_path"`
	ZipPath        string `json:"zip_path,omitempty"` // backward compat with earlier code
	DestinationDir string `json:"destination_dir"`
	StorageQuotaMB int64  `json:"storage_quota_mb,omitempty"`
}

type FileExtractAction struct{}

func NewFileExtractAction() *FileExtractAction { return &FileExtractAction{} }

func (a *FileExtractAction) Name() string { return "file.extract" }

func (a *FileExtractAction) resolveArchivePath(p *FileExtractPayload) string {
	if p.ArchivePath != "" {
		return p.ArchivePath
	}
	return p.ZipPath
}

func (a *FileExtractAction) Validate(payload []byte) error {
	var p FileExtractPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}

	archivePath := a.resolveArchivePath(&p)
	if archivePath == "" {
		return fmt.Errorf("archive_path (or zip_path) cannot be empty")
	}

	if _, err := ResolveSafePath(p.Username, archivePath); err != nil {
		return fmt.Errorf("invalid archive path: %w", err)
	}
	if _, err := ResolveSafePath(p.Username, p.DestinationDir); err != nil {
		return fmt.Errorf("invalid destination_dir: %w", err)
	}

	lower := strings.ToLower(archivePath)
	if !strings.HasSuffix(lower, ".zip") && !strings.HasSuffix(lower, ".tar.gz") && !strings.HasSuffix(lower, ".tgz") && !strings.HasSuffix(lower, ".tar") {
		return fmt.Errorf("unsupported archive format; expected .zip, .tar.gz, .tgz, or .tar")
	}

	return nil
}

func (a *FileExtractAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FileExtractPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	archivePath := a.resolveArchivePath(&p)
	archiveSafe, err := ResolveSafePath(p.Username, archivePath)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	destSafe, err := ResolveSafePath(p.Username, p.DestinationDir)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("extracting %s to %s", archiveSafe, destSafe)}

	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"archive_path": archiveSafe,
				"destination":  destSafe,
				"extracted":    true,
				"dry_run":      true,
			},
			Logs: logs,
		}, nil
	}

	// Calculate max limits: single entry limit 500MB, total quota limit default 1024MB or p.StorageQuotaMB
	maxEntrySize := int64(500 * 1024 * 1024)
	maxQuotaBytes := int64(1024 * 1024 * 1024) // 1GB default
	if p.StorageQuotaMB > 0 {
		maxQuotaBytes = p.StorageQuotaMB * 1024 * 1024
		if maxEntrySize > maxQuotaBytes {
			maxEntrySize = maxQuotaBytes
		}
	}

	lower := strings.ToLower(archiveSafe)
	isZip := strings.HasSuffix(lower, ".zip")
	isTarGz := strings.HasSuffix(lower, ".tar.gz") || strings.HasSuffix(lower, ".tgz")
	isTar := strings.HasSuffix(lower, ".tar")

	// Ensure destination directory exists
	if err := os.MkdirAll(destSafe, 0755); err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("creating destination directory: %v", err), Logs: logs}, err
	}

	// Step 1: Pre-scan every entry before writing anything
	if isZip {
		if err := scanZipEntries(archiveSafe, destSafe, maxEntrySize, maxQuotaBytes); err != nil {
			return &ActionResult{Success: false, Error: fmt.Sprintf("archive scan failed: %v", err), Logs: logs}, err
		}
	} else if isTarGz || isTar {
		if err := scanTarEntries(archiveSafe, destSafe, isTarGz, maxEntrySize, maxQuotaBytes); err != nil {
			return &ActionResult{Success: false, Error: fmt.Sprintf("archive scan failed: %v", err), Logs: logs}, err
		}
	} else {
		return &ActionResult{Success: false, Error: "unsupported archive format", Logs: logs}, errors.New("unsupported archive format")
	}

	// Step 2: Extract into a new temp directory in destSafe's directory
	tempExtractDir, err := os.MkdirTemp(destSafe, ".extract-tmp-*")
	if err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("creating temp extraction directory: %v", err), Logs: logs}, err
	}
	defer os.RemoveAll(tempExtractDir)

	if isZip {
		if err := extractZipContent(archiveSafe, tempExtractDir); err != nil {
			return &ActionResult{Success: false, Error: fmt.Sprintf("extracting zip content: %v", err), Logs: logs}, err
		}
	} else {
		if err := extractTarContent(archiveSafe, tempExtractDir, isTarGz); err != nil {
			return &ActionResult{Success: false, Error: fmt.Sprintf("extracting tar content: %v", err), Logs: logs}, err
		}
	}

	// Step 3: Move extracted items from temp directory into destSafe
	if err := moveExtractedItems(tempExtractDir, destSafe); err != nil {
		return &ActionResult{Success: false, Error: fmt.Sprintf("finalizing extracted files: %v", err), Logs: logs}, err
	}

	// Step 4: Chown everything to the account user
	_ = exec.CommandContext(ctx, "chown", "-R", fmt.Sprintf("%s:%s", p.Username, p.Username), destSafe).Run()

	logs = append(logs, fmt.Sprintf("successfully extracted %s into %s", archiveSafe, destSafe))
	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"archive_path": archiveSafe,
			"destination":  destSafe,
			"extracted":    true,
		},
		Logs: logs,
	}, nil
}

// scanZipEntries scans every entry in the zip archive before extracting.
// It rejects absolute paths, "..", symlinks pointing outside the target, and entries over size limit.
func scanZipEntries(zipPath, destDir string, maxEntrySize, maxQuotaBytes int64) error {
	r, err := zip.OpenReader(zipPath)
	if err != nil {
		return fmt.Errorf("opening zip file: %w", err)
	}
	defer r.Close()

	destClean := filepath.Clean(destDir)
	var totalUncompressed int64

	for _, f := range r.File {
		// Reject absolute path
		if filepath.IsAbs(f.Name) || strings.HasPrefix(f.Name, "/") || strings.HasPrefix(f.Name, "\\") {
			return fmt.Errorf("illegal absolute path in zip entry: %s", f.Name)
		}

		// Reject traversal ("..")
		cleanEntry := filepath.Clean(f.Name)
		if strings.Contains(f.Name, "..") || !isSubpath(destClean, filepath.Join(destClean, cleanEntry)) {
			return fmt.Errorf("illegal path traversal in zip entry: %s", f.Name)
		}

		// Size checks
		if int64(f.UncompressedSize64) > maxEntrySize {
			return fmt.Errorf("zip entry '%s' exceeds single file size limit (%d bytes)", f.Name, maxEntrySize)
		}
		totalUncompressed += int64(f.UncompressedSize64)
		if totalUncompressed > maxQuotaBytes {
			return fmt.Errorf("total uncompressed archive size exceeds storage quota limit (%d bytes)", maxQuotaBytes)
		}

		// Symlink check
		if f.Mode()&os.ModeSymlink != 0 {
			rc, err := f.Open()
			if err != nil {
				return fmt.Errorf("reading symlink entry '%s': %w", f.Name, err)
			}
			linkBytes, err := io.ReadAll(rc)
			rc.Close()
			if err != nil {
				return fmt.Errorf("reading symlink target for '%s': %w", f.Name, err)
			}
			linkTarget := string(linkBytes)
			if filepath.IsAbs(linkTarget) {
				return fmt.Errorf("illegal absolute symlink target in entry '%s': %s", f.Name, linkTarget)
			}
			resolved := filepath.Clean(filepath.Join(filepath.Dir(filepath.Join(destClean, cleanEntry)), linkTarget))
			if !isSubpath(destClean, resolved) {
				return fmt.Errorf("symlink '%s' points outside destination target: %s", f.Name, linkTarget)
			}
		}
	}

	return nil
}

// scanTarEntries scans every entry in tar/tar.gz before extracting.
// It rejects absolute paths, "..", symlinks/hardlinks outside target, device files, and size limits.
func scanTarEntries(tarPath, destDir string, isGzip bool, maxEntrySize, maxQuotaBytes int64) error {
	file, err := os.Open(tarPath)
	if err != nil {
		return fmt.Errorf("opening tar file: %w", err)
	}
	defer file.Close()

	var tr *tar.Reader
	if isGzip {
		gzr, err := gzip.NewReader(file)
		if err != nil {
			return fmt.Errorf("opening gzip reader: %w", err)
		}
		defer gzr.Close()
		tr = tar.NewReader(gzr)
	} else {
		tr = tar.NewReader(file)
	}

	destClean := filepath.Clean(destDir)
	var totalUncompressed int64

	for {
		header, err := tr.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			return fmt.Errorf("reading tar entry: %w", err)
		}

		name := header.Name
		// Reject absolute paths
		if filepath.IsAbs(name) || strings.HasPrefix(name, "/") || strings.HasPrefix(name, "\\") {
			return fmt.Errorf("illegal absolute path in tar entry: %s", name)
		}

		// Reject traversal ("..")
		cleanEntry := filepath.Clean(name)
		if strings.Contains(name, "..") || !isSubpath(destClean, filepath.Join(destClean, cleanEntry)) {
			return fmt.Errorf("illegal path traversal in tar entry: %s", name)
		}

		// Reject device files, FIFOs, and unusual types
		switch header.Typeflag {
		case tar.TypeReg, tar.TypeRegA, tar.TypeDir, tar.TypeSymlink, tar.TypeLink:
			// allowed types
		default:
			return fmt.Errorf("illegal device or special file in tar entry '%s' (typeflag: %v)", name, header.Typeflag)
		}

		// Size checks
		if header.Size > maxEntrySize {
			return fmt.Errorf("tar entry '%s' exceeds single file size limit (%d bytes)", name, maxEntrySize)
		}
		totalUncompressed += header.Size
		if totalUncompressed > maxQuotaBytes {
			return fmt.Errorf("total uncompressed archive size exceeds storage quota limit (%d bytes)", maxQuotaBytes)
		}

		// Check symlinks and hardlinks
		if header.Typeflag == tar.TypeSymlink {
			target := header.Linkname
			if filepath.IsAbs(target) {
				return fmt.Errorf("illegal absolute symlink in tar entry '%s': %s", name, target)
			}
			resolved := filepath.Clean(filepath.Join(filepath.Dir(filepath.Join(destClean, cleanEntry)), target))
			if !isSubpath(destClean, resolved) {
				return fmt.Errorf("symlink '%s' points outside destination target: %s", name, target)
			}
		} else if header.Typeflag == tar.TypeLink {
			target := header.Linkname
			if filepath.IsAbs(target) {
				return fmt.Errorf("illegal absolute hardlink in tar entry '%s': %s", name, target)
			}
			resolved := filepath.Clean(filepath.Join(destClean, filepath.Clean(target)))
			if !isSubpath(destClean, resolved) {
				return fmt.Errorf("hardlink '%s' points outside destination target: %s", name, target)
			}
		}
	}

	return nil
}

func extractZipContent(zipPath, targetDir string) error {
	r, err := zip.OpenReader(zipPath)
	if err != nil {
		return err
	}
	defer r.Close()

	destClean := filepath.Clean(targetDir)

	for _, f := range r.File {
		cleanEntry := filepath.Clean(f.Name)
		outPath := filepath.Join(destClean, cleanEntry)

		if f.FileInfo().IsDir() {
			if err := os.MkdirAll(outPath, 0755); err != nil {
				return err
			}
			continue
		}

		if err := os.MkdirAll(filepath.Dir(outPath), 0755); err != nil {
			return err
		}

		if f.Mode()&os.ModeSymlink != 0 {
			rc, err := f.Open()
			if err != nil {
				return err
			}
			targetBytes, err := io.ReadAll(rc)
			rc.Close()
			if err != nil {
				return err
			}
			_ = os.Remove(outPath)
			if err := os.Symlink(string(targetBytes), outPath); err != nil {
				return err
			}
			continue
		}

		outFile, err := os.OpenFile(outPath, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, f.Mode().Perm())
		if err != nil {
			return err
		}

		rc, err := f.Open()
		if err != nil {
			outFile.Close()
			return err
		}

		_, copyErr := io.Copy(outFile, rc)
		rc.Close()
		syncErr := outFile.Sync()
		closeErr := outFile.Close()

		if copyErr != nil {
			return copyErr
		}
		if syncErr != nil {
			return syncErr
		}
		if closeErr != nil {
			return closeErr
		}
	}

	return nil
}

func extractTarContent(tarPath, targetDir string, isGzip bool) error {
	file, err := os.Open(tarPath)
	if err != nil {
		return err
	}
	defer file.Close()

	var tr *tar.Reader
	if isGzip {
		gzr, err := gzip.NewReader(file)
		if err != nil {
			return err
		}
		defer gzr.Close()
		tr = tar.NewReader(gzr)
	} else {
		tr = tar.NewReader(file)
	}

	destClean := filepath.Clean(targetDir)

	for {
		header, err := tr.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			return err
		}

		cleanEntry := filepath.Clean(header.Name)
		outPath := filepath.Join(destClean, cleanEntry)

		switch header.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(outPath, 0755); err != nil {
				return err
			}
		case tar.TypeReg, tar.TypeRegA:
			if err := os.MkdirAll(filepath.Dir(outPath), 0755); err != nil {
				return err
			}
			mode := os.FileMode(header.Mode).Perm()
			if mode == 0 {
				mode = 0644
			}
			outFile, err := os.OpenFile(outPath, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, mode)
			if err != nil {
				return err
			}
			_, copyErr := io.Copy(outFile, tr)
			syncErr := outFile.Sync()
			closeErr := outFile.Close()
			if copyErr != nil {
				return copyErr
			}
			if syncErr != nil {
				return syncErr
			}
			if closeErr != nil {
				return closeErr
			}
		case tar.TypeSymlink:
			if err := os.MkdirAll(filepath.Dir(outPath), 0755); err != nil {
				return err
			}
			_ = os.Remove(outPath)
			if err := os.Symlink(header.Linkname, outPath); err != nil {
				return err
			}
		case tar.TypeLink:
			if err := os.MkdirAll(filepath.Dir(outPath), 0755); err != nil {
				return err
			}
			targetPath := filepath.Join(destClean, filepath.Clean(header.Linkname))
			_ = os.Remove(outPath)
			if err := os.Link(targetPath, outPath); err != nil {
				return err
			}
		}
	}

	return nil
}

func moveExtractedItems(srcDir, destDir string) error {
	entries, err := os.ReadDir(srcDir)
	if err != nil {
		return err
	}

	for _, entry := range entries {
		srcItem := filepath.Join(srcDir, entry.Name())
		destItem := filepath.Join(destDir, entry.Name())

		if entry.IsDir() {
			if fi, err := os.Stat(destItem); err == nil && fi.IsDir() {
				if err := moveExtractedItems(srcItem, destItem); err != nil {
					return err
				}
				continue
			}
		}

		_ = os.RemoveAll(destItem)
		if err := os.Rename(srcItem, destItem); err != nil {
			if copyErr := copyAndReplace(srcItem, destItem, 0644); copyErr != nil {
				return fmt.Errorf("moving '%s' to '%s': %w", srcItem, destItem, copyErr)
			}
			_ = os.RemoveAll(srcItem)
		}
	}

	return nil
}

func isSubpath(base, target string) bool {
	base = filepath.Clean(base)
	target = filepath.Clean(target)
	if base == target {
		return true
	}
	return strings.HasPrefix(target, base+string(filepath.Separator))
}
