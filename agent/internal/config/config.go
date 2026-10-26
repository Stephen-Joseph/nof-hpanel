package config

import (
	"bufio"
	"encoding/json"
	"fmt"
	"os"
	"strconv"
	"strings"
)

type ServerConfig struct {
	ListenAddress string `json:"listen_address"`
	TLSCertFile   string `json:"tls_cert_file"`
	TLSKeyFile    string `json:"tls_key_file"`
	ClientCAFile  string `json:"client_ca_file"`
}

type AuthConfig struct {
	HMACSecret              string `json:"hmac_secret"`
	MaxTimestampSkewSeconds int64  `json:"max_timestamp_skew_seconds"`
	NonceRingFile           string `json:"nonce_ring_file"`
}

type LoggingConfig struct {
	LogFile string `json:"log_file"`
	Level   string `json:"level"`
	Format  string `json:"format"`
}

type SystemConfig struct {
	TempDir string `json:"temp_dir"`
}

type Config struct {
	Server  ServerConfig  `json:"server"`
	Auth    AuthConfig    `json:"auth"`
	Logging LoggingConfig `json:"logging"`
	System  SystemConfig  `json:"system"`
}

func DefaultConfig() *Config {
	return &Config{
		Server: ServerConfig{
			ListenAddress: "127.0.0.1:8443",
			TLSCertFile:   "/etc/panel-agent/certs/server.crt",
			TLSKeyFile:    "/etc/panel-agent/certs/server.key",
			ClientCAFile:  "/etc/panel-agent/certs/panel-ca.crt",
		},
		Auth: AuthConfig{
			HMACSecret:              "",
			MaxTimestampSkewSeconds: 60,
			NonceRingFile:           "/var/lib/panel-agent/nonces.dat",
		},
		Logging: LoggingConfig{
			LogFile: "/var/log/panel-agent/agent.log",
			Level:   "info",
			Format:  "json",
		},
		System: SystemConfig{
			TempDir: "/var/lib/panel-agent/tmp",
		},
	}
}

// Load loads the configuration from a file (YAML or JSON) or returns defaults if path is empty.
func Load(path string) (*Config, error) {
	cfg := DefaultConfig()

	if path == "" {
		cfg.applyEnvOverrides()
		return cfg, nil
	}

	data, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("reading config file: %w", err)
	}

	content := strings.TrimSpace(string(data))
	if strings.HasPrefix(content, "{") {
		// Parse as JSON
		if err := json.Unmarshal([]byte(content), cfg); err != nil {
			return nil, fmt.Errorf("parsing json config: %w", err)
		}
	} else {
		// Parse structured YAML
		if err := parseYAML(content, cfg); err != nil {
			return nil, fmt.Errorf("parsing yaml config: %w", err)
		}
	}

	cfg.applyEnvOverrides()
	return cfg, nil
}

func parseYAML(content string, cfg *Config) error {
	scanner := bufio.NewScanner(strings.NewReader(content))
	currentSection := ""

	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}

		// Strip inline comments
		if idx := strings.Index(line, " #"); idx != -1 {
			line = strings.TrimSpace(line[:idx])
		}

		if strings.HasSuffix(line, ":") {
			currentSection = strings.TrimSuffix(line, ":")
			continue
		}

		parts := strings.SplitN(line, ":", 2)
		if len(parts) != 2 {
			continue
		}

		key := strings.TrimSpace(parts[0])
		val := strings.TrimSpace(parts[1])
		val = strings.Trim(val, `"'`)

		switch currentSection {
		case "server":
			switch key {
			case "listen_address":
				cfg.Server.ListenAddress = val
			case "tls_cert_file":
				cfg.Server.TLSCertFile = val
			case "tls_key_file":
				cfg.Server.TLSKeyFile = val
			case "client_ca_file":
				cfg.Server.ClientCAFile = val
			}
		case "auth":
			switch key {
			case "hmac_secret":
				cfg.Auth.HMACSecret = val
			case "max_timestamp_skew_seconds":
				if s, err := strconv.ParseInt(val, 10, 64); err == nil {
					cfg.Auth.MaxTimestampSkewSeconds = s
				}
			case "nonce_ring_file":
				cfg.Auth.NonceRingFile = val
			}
		case "logging":
			switch key {
			case "log_file":
				cfg.Logging.LogFile = val
			case "level":
				cfg.Logging.Level = val
			case "format":
				cfg.Logging.Format = val
			}
		case "system":
			switch key {
			case "temp_dir":
				cfg.System.TempDir = val
			}
		}
	}

	return scanner.Err()
}

func (c *Config) applyEnvOverrides() {
	if v := os.Getenv("PANEL_AGENT_LISTEN_ADDR"); v != "" {
		c.Server.ListenAddress = v
	}
	if v := os.Getenv("PANEL_AGENT_TLS_CERT"); v != "" {
		c.Server.TLSCertFile = v
	}
	if v := os.Getenv("PANEL_AGENT_TLS_KEY"); v != "" {
		c.Server.TLSKeyFile = v
	}
	if v := os.Getenv("PANEL_AGENT_CLIENT_CA"); v != "" {
		c.Server.ClientCAFile = v
	}
	if v := os.Getenv("PANEL_AGENT_HMAC_SECRET"); v != "" {
		c.Auth.HMACSecret = v
	}
	if v := os.Getenv("PANEL_AGENT_NONCE_FILE"); v != "" {
		c.Auth.NonceRingFile = v
	}
	if v := os.Getenv("PANEL_AGENT_LOG_FILE"); v != "" {
		c.Logging.LogFile = v
	}
}
