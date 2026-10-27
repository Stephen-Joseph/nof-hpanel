package actions

import (
	"archive/zip"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
)

// ResolveSafePath strictly verifies that the given path is contained inside /home/<username>/.
// It guards against path traversal (e.g. "../"), null bytes, URL encoded traversal (%2e%2e), and symlink escapes.
func ResolveSafePath(username, requestedPath string) (string, error) {
	return ResolveSafePathWithBase("/home", username, requestedPath)
}

// ResolveSafePathNoFollow verifies that the path resides inside /home/<username>/ without following
// the final path component if it is a symlink.
func ResolveSafePathNoFollow(username, requestedPath string) (string, error) {
	return ResolveSafePathNoFollowWithBase("/home", username, requestedPath)
}

// ResolveSafePathNoFollowWithBase validates that the path is inside baseHome/username, evaluating symlinks
// for all parent directories, but NOT following the final path component if it is a symlink.
func ResolveSafePathNoFollowWithBase(baseHome, username, requestedPath string) (string, error) {
	if err := ValidateLinuxUsername(username); err != nil {
		return "", err
	}

	lower := strings.ToLower(requestedPath)
	if strings.Contains(lower, "%2e%2e") || strings.Contains(lower, "%2f") || strings.Contains(lower, "%00") {
		return "", errors.New("illegal encoded path traversal sequence in file path")
	}

	if strings.Contains(requestedPath, "\x00") {
		return "", errors.New("illegal null byte in file path")
	}

	if baseHome == "" {
		baseHome = "/home"
	}
	baseHome = filepath.Clean(baseHome)
	homeDir := filepath.Clean(filepath.Join(baseHome, username))

	var candidatePath string
	if filepath.IsAbs(requestedPath) {
		candidatePath = filepath.Clean(requestedPath)
	} else {
		candidatePath = filepath.Clean(filepath.Join(homeDir, requestedPath))
	}

	// Lexical check: Candidate must start with home directory
	if candidatePath != homeDir && !strings.HasPrefix(candidatePath, homeDir+string(filepath.Separator)) {
		return "", fmt.Errorf("path '%s' traverses outside home directory '%s'", requestedPath, homeDir)
	}

	if candidatePath == homeDir {
		return candidatePath, nil
	}

	parentDir := filepath.Dir(candidatePath)
	realParent, parentErr := filepath.EvalSymlinks(parentDir)
	if parentErr == nil {
		realParent = filepath.Clean(realParent)
		if realParent != homeDir && !strings.HasPrefix(realParent, homeDir+string(filepath.Separator)) {
			return "", fmt.Errorf("parent directory symlink '%s' escapes home directory '%s'", realParent, homeDir)
		}
		return filepath.Join(realParent, filepath.Base(candidatePath)), nil
	}

	return candidatePath, nil
}

// ResolveSafePathWithBase verifies that the given path is contained inside <baseHome>/<username>/.
func ResolveSafePathWithBase(baseHome, username, requestedPath string) (string, error) {
	if err := ValidateLinuxUsername(username); err != nil {
		return "", err
	}

	lower := strings.ToLower(requestedPath)
	if strings.Contains(lower, "%2e%2e") || strings.Contains(lower, "%2f") || strings.Contains(lower, "%00") {
		return "", errors.New("illegal encoded path traversal sequence in file path")
	}

	if strings.Contains(requestedPath, "\x00") {
		return "", errors.New("illegal null byte in file path")
	}

	if baseHome == "" {
		baseHome = "/home"
	}
	baseHome = filepath.Clean(baseHome)
	homeDir := filepath.Clean(filepath.Join(baseHome, username))

	var candidatePath string
	if filepath.IsAbs(requestedPath) {
		candidatePath = filepath.Clean(requestedPath)
	} else {
		candidatePath = filepath.Clean(filepath.Join(homeDir, requestedPath))
	}

	// Lexical check: Candidate must start with home directory
	if candidatePath != homeDir && !strings.HasPrefix(candidatePath, homeDir+string(filepath.Separator)) {
		return "", fmt.Errorf("path '%s' traverses outside home directory '%s'", requestedPath, homeDir)
	}

	// Symlink escape check: if path exists, evaluate all symlinks
	realPath, err := filepath.EvalSymlinks(candidatePath)
	if err == nil {
		realPath = filepath.Clean(realPath)
		if realPath != homeDir && !strings.HasPrefix(realPath, homeDir+string(filepath.Separator)) {
			return "", fmt.Errorf("symlink target '%s' escapes home directory '%s'", realPath, homeDir)
		}
		return realPath, nil
	}

	// If candidate is homeDir itself and doesn't exist yet, return it
	if candidatePath == homeDir {
		return candidatePath, nil
	}

	// If file doesn't exist yet, evaluate parent directory symlinks if within home
	parentDir := filepath.Dir(candidatePath)
	if parentDir == homeDir || strings.HasPrefix(parentDir, homeDir+string(filepath.Separator)) {
		realParent, parentErr := filepath.EvalSymlinks(parentDir)
		if parentErr == nil {
			realParent = filepath.Clean(realParent)
			if realParent != homeDir && !strings.HasPrefix(realParent, homeDir+string(filepath.Separator)) {
				return "", fmt.Errorf("parent directory symlink '%s' escapes home directory '%s'", realParent, homeDir)
			}
			return filepath.Join(realParent, filepath.Base(candidatePath)), nil
		}
	}

	// In test environments or when parent doesn't exist yet, return cleaned candidate if lexical check passed
	return candidatePath, nil
}

// ExtractZipSafely extracts a zip file while strictly defending against Zip Slip (traversal) attacks.
func ExtractZipSafely(zipPath, destDir string) error {
	r, err := zip.OpenReader(zipPath)
	if err != nil {
		return fmt.Errorf("failed opening zip file: %w", err)
	}
	defer r.Close()

	destClean := filepath.Clean(destDir)

	for _, f := range r.File {
		targetPath := filepath.Clean(filepath.Join(destClean, f.Name))

		// Zip Slip Defense: Ensure extracted file is inside destination directory
		if targetPath != destClean && !strings.HasPrefix(targetPath, destClean+string(filepath.Separator)) {
			return fmt.Errorf("illegal zip slip entry '%s' attempting write outside destination", f.Name)
		}

		if f.FileInfo().IsDir() {
			if err := os.MkdirAll(targetPath, 0755); err != nil {
				return err
			}
			continue
		}

		if err := os.MkdirAll(filepath.Dir(targetPath), 0755); err != nil {
			return err
		}

		outFile, err := os.OpenFile(targetPath, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, f.Mode())
		if err != nil {
			return err
		}

		rc, err := f.Open()
		if err != nil {
			outFile.Close()
			return err
		}

		// Cap max extracted bytes to prevent decompression bombs (e.g. 500MB)
		_, err = io.CopyN(outFile, rc, 500*1024*1024)
		rc.Close()
		outFile.Close()

		if err != nil && err != io.EOF {
			return fmt.Errorf("error extracting '%s': %w", f.Name, err)
		}
	}

	return nil
}
