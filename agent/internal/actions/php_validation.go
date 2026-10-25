package actions

import (
	"errors"
	"fmt"
	"regexp"
	"slices"
	"strconv"
	"strings"
)

var (
	SupportedPHPVersions = []string{"8.1", "8.2", "8.3", "8.4"}
	memoryRegex          = regexp.MustCompile(`^[1-9][0-9]{1,4}[MG]$`)
)

type PhpIniSettings struct {
	MemoryLimit       string `json:"memory_limit,omitempty"`
	UploadMaxFilesize string `json:"upload_max_filesize,omitempty"`
	PostMaxSize       string `json:"post_max_size,omitempty"`
	MaxExecutionTime  int    `json:"max_execution_time,omitempty"`
	DisplayErrors     string `json:"display_errors,omitempty"`
}

// ValidateSupportedPHPVersion ensures the version is in the supported allow-list.
func ValidateSupportedPHPVersion(version string) error {
	v := strings.TrimSpace(version)
	if !slices.Contains(SupportedPHPVersions, v) {
		return fmt.Errorf("unsupported PHP version '%s' (allowed: %s)", version, strings.Join(SupportedPHPVersions, ", "))
	}
	return nil
}

// ValidatePhpIniSettings validates all customized INI directives against safe ranges.
func ValidatePhpIniSettings(settings PhpIniSettings) error {
	if settings.MemoryLimit != "" {
		if !memoryRegex.MatchString(settings.MemoryLimit) {
			return fmt.Errorf("invalid memory_limit format '%s' (e.g. 256M, 512M)", settings.MemoryLimit)
		}
	}

	if settings.UploadMaxFilesize != "" {
		if !memoryRegex.MatchString(settings.UploadMaxFilesize) {
			return fmt.Errorf("invalid upload_max_filesize format '%s'", settings.UploadMaxFilesize)
		}
	}

	if settings.PostMaxSize != "" {
		if !memoryRegex.MatchString(settings.PostMaxSize) {
			return fmt.Errorf("invalid post_max_size format '%s'", settings.PostMaxSize)
		}
	}

	if settings.MaxExecutionTime != 0 {
		if settings.MaxExecutionTime < 5 || settings.MaxExecutionTime > 600 {
			return fmt.Errorf("max_execution_time must be between 5 and 600 seconds, got %d", settings.MaxExecutionTime)
		}
	}

	if settings.DisplayErrors != "" {
		normalized := strings.ToLower(strings.TrimSpace(settings.DisplayErrors))
		if normalized != "on" && normalized != "off" && normalized != "0" && normalized != "1" {
			return errors.New("display_errors must be 'on' or 'off'")
		}
	}

	return nil
}

// ValidatePmMode checks if the process manager mode is valid.
func ValidatePmMode(mode string) error {
	m := strings.ToLower(strings.TrimSpace(mode))
	if m != "ondemand" && m != "dynamic" && m != "static" {
		return fmt.Errorf("invalid pm mode '%s' (must be ondemand, dynamic, or static)", mode)
	}
	return nil
}

// ParseMemoryToMB converts values like 256M or 1G to integer MB.
func ParseMemoryToMB(val string) int {
	v := strings.ToUpper(strings.TrimSpace(val))
	if strings.HasSuffix(v, "G") {
		num, _ := strconv.Atoi(strings.TrimSuffix(v, "G"))
		return num * 1024
	}
	if strings.HasSuffix(v, "M") {
		num, _ := strconv.Atoi(strings.TrimSuffix(v, "M"))
		return num
	}
	return 256
}
