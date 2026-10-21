package actions

import (
	"errors"
	"fmt"
	"path/filepath"
	"strings"
)

// ValidateDocumentRoot verifies that the document root is strictly inside /home/<username>/.
func ValidateDocumentRoot(username, docRoot string) error {
	docRoot = strings.TrimSpace(docRoot)
	if docRoot == "" {
		return errors.New("document_root cannot be empty")
	}

	if strings.Contains(docRoot, "\x00") {
		return errors.New("document_root contains illegal null byte")
	}

	// Normalize path
	cleanPath := filepath.Clean(docRoot)
	cleanPath = filepath.ToSlash(cleanPath)

	expectedHome := "/home/" + username
	if cleanPath != expectedHome && !strings.HasPrefix(cleanPath, expectedHome+"/") {
		return fmt.Errorf("document_root '%s' must reside strictly within account home directory '%s'", docRoot, expectedHome)
	}

	// Check for path traversal segments
	segments := strings.Split(cleanPath, "/")
	for _, seg := range segments {
		if seg == ".." {
			return errors.New("path traversal '..' is strictly prohibited in document_root")
		}
	}

	return nil
}

// ValidateAppProfile checks if profile is one of the supported profiles.
func ValidateAppProfile(profile string) error {
	profile = strings.TrimSpace(profile)
	if profile == "" {
		return nil // defaults to php-generic
	}

	allowed := map[string]bool{
		"php-generic": true,
		"laravel":     true,
		"wordpress":   true,
		"static-spa":  true,
	}

	if !allowed[profile] {
		return fmt.Errorf("unsupported app_profile '%s'. Allowed: php-generic, laravel, wordpress, static-spa", profile)
	}

	return nil
}

// ValidateWwwRedirect checks if mode is valid.
func ValidateWwwRedirect(mode string) error {
	mode = strings.TrimSpace(mode)
	if mode == "" {
		return nil
	}

	allowed := map[string]bool{
		"none":      true,
		"force_www": true,
		"strip_www": true,
	}

	if !allowed[mode] {
		return fmt.Errorf("unsupported www_redirect '%s'. Allowed: none, force_www, strip_www", mode)
	}

	return nil
}

