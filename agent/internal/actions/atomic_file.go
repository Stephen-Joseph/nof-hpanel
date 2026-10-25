package actions

import (
	"fmt"
	"io"
	"os"
	"path/filepath"
)

// renameFunc allows mocking os.Rename in unit tests (e.g., simulating EXDEV).
var renameFunc = os.Rename

// CreateConfigTemp creates a temporary file in the destination's directory using pattern
// "." + filepath.Base(dest) + ".tmp-*", writes content, calls fsync, closes it, and chmods to perm.
// It returns the path to the temporary file. If an error occurs, the temporary file is removed.
func CreateConfigTemp(dest string, content []byte, perm os.FileMode) (string, error) {
	destDir := filepath.Dir(dest)
	if err := os.MkdirAll(destDir, 0755); err != nil {
		return "", fmt.Errorf("creating destination directory %s: %w", destDir, err)
	}

	pattern := "." + filepath.Base(dest) + ".tmp-*"
	tmpFile, err := os.CreateTemp(destDir, pattern)
	if err != nil {
		return "", fmt.Errorf("creating temp config file in %s: %w", destDir, err)
	}
	tmpPath := tmpFile.Name()

	cleanup := true
	defer func() {
		if cleanup {
			_ = os.Remove(tmpPath)
		}
	}()

	if _, err := tmpFile.Write(content); err != nil {
		_ = tmpFile.Close()
		return "", fmt.Errorf("writing temp config file %s: %w", tmpPath, err)
	}

	if err := tmpFile.Sync(); err != nil {
		_ = tmpFile.Close()
		return "", fmt.Errorf("fsync temp config file %s: %w", tmpPath, err)
	}

	if err := tmpFile.Close(); err != nil {
		return "", fmt.Errorf("closing temp config file %s: %w", tmpPath, err)
	}

	if err := os.Chmod(tmpPath, perm); err != nil {
		return "", fmt.Errorf("chmodding temp config file %s: %w", tmpPath, err)
	}

	cleanup = false
	return tmpPath, nil
}

// CommitConfigFile atomically installs tmpPath into dest using rename.
// If rename fails, it falls back to a copy-and-replace within the same directory.
// On success or error, tmpPath is cleaned up.
func CommitConfigFile(tmpPath, dest string, perm os.FileMode) error {
	defer func() {
		_ = os.Remove(tmpPath)
	}()

	if err := renameFunc(tmpPath, dest); err == nil {
		_ = os.Chmod(dest, perm)
		return nil
	}

	// Fallback to copy-and-replace in the same directory
	if err := copyAndReplace(tmpPath, dest, perm); err != nil {
		return fmt.Errorf("fallback copy-and-replace %s to %s failed: %w", tmpPath, dest, err)
	}

	return nil
}

// WriteConfigFileAtomically writes content to a temporary file in dest's directory,
// fsyncs, closes, chmods, and atomically installs it to dest (with copy-and-replace fallback).
// On any error, the temporary file is removed.
func WriteConfigFileAtomically(dest string, content []byte, perm os.FileMode) error {
	tmpPath, err := CreateConfigTemp(dest, content, perm)
	if err != nil {
		return err
	}
	return CommitConfigFile(tmpPath, dest, perm)
}

func copyAndReplace(src, dest string, perm os.FileMode) error {
	in, err := os.Open(src)
	if err != nil {
		return err
	}
	defer in.Close()

	out, err := os.OpenFile(dest, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, perm)
	if err != nil {
		return err
	}
	defer out.Close()

	if _, err := io.Copy(out, in); err != nil {
		return err
	}

	if err := out.Sync(); err != nil {
		return err
	}

	if err := out.Close(); err != nil {
		return err
	}

	return os.Chmod(dest, perm)
}

