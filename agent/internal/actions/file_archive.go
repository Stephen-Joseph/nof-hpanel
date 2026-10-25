package actions

import (
	"archive/zip"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
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

	zipFile, err := os.Create(destSafe)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}
	defer zipFile.Close()

	w := zip.NewWriter(zipFile)
	defer w.Close()

	for _, src := range p.Sources {
		srcSafe, err := ResolveSafePath(p.Username, src)
		if err != nil {
			continue
		}

		baseDir := filepath.Dir(srcSafe)

		_ = filepath.Walk(srcSafe, func(path string, info os.FileInfo, err error) error {
			if err != nil || path == destSafe {
				return nil
			}

			relPath, err := filepath.Rel(baseDir, path)
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

			file, err := os.Open(path)
			if err != nil {
				return nil
			}
			defer file.Close()

			_, _ = io.Copy(writer, file)
			return nil
		})
	}

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
	ZipPath        string `json:"zip_path"`
	DestinationDir string `json:"destination_dir"`
}

type FileExtractAction struct{}

func NewFileExtractAction() *FileExtractAction { return &FileExtractAction{} }

func (a *FileExtractAction) Name() string { return "file.extract" }

func (a *FileExtractAction) Validate(payload []byte) error {
	var p FileExtractPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return fmt.Errorf("malformed payload: %w", err)
	}
	if err := ValidateLinuxUsername(p.Username); err != nil {
		return err
	}
	if _, err := ResolveSafePath(p.Username, p.ZipPath); err != nil {
		return fmt.Errorf("invalid zip_path: %w", err)
	}
	if _, err := ResolveSafePath(p.Username, p.DestinationDir); err != nil {
		return fmt.Errorf("invalid destination_dir: %w", err)
	}
	return nil
}

func (a *FileExtractAction) Execute(ctx context.Context, payload []byte, dryRun bool) (*ActionResult, error) {
	var p FileExtractPayload
	if err := json.Unmarshal(payload, &p); err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	zipSafe, err := ResolveSafePath(p.Username, p.ZipPath)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}
	destSafe, err := ResolveSafePath(p.Username, p.DestinationDir)
	if err != nil {
		return &ActionResult{Success: false, Error: err.Error()}, err
	}

	logs := []string{fmt.Sprintf("extracting %s to %s", zipSafe, destSafe)}

	if dryRun {
		return &ActionResult{
			Success: true,
			Data: map[string]any{
				"zip_path":    zipSafe,
				"destination": destSafe,
				"extracted":   true,
				"dry_run":     true,
			},
			Logs: logs,
		}, nil
	}

	if err := ExtractZipSafely(zipSafe, destSafe); err != nil {
		return &ActionResult{Success: false, Error: err.Error(), Logs: logs}, err
	}

	return &ActionResult{
		Success: true,
		Data: map[string]any{
			"zip_path":    zipSafe,
			"destination": destSafe,
			"extracted":   true,
		},
		Logs: logs,
	}, nil
}
