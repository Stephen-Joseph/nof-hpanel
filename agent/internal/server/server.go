package server

import (
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"sync"
	"time"

	"github.com/hpanel/agent/internal/actions"
	"github.com/hpanel/agent/internal/config"
	"github.com/hpanel/agent/internal/security"
)

type ActionRequest struct {
	Action    string          `json:"action"`
	RequestID string          `json:"request_id"`
	Timestamp int64           `json:"timestamp"`
	Nonce     string          `json:"nonce"`
	DryRun    bool            `json:"dry_run"`
	Payload   json.RawMessage `json:"payload"`
	Signature string          `json:"signature"`
}

type ActionResponse struct {
	RequestID  string         `json:"request_id"`
	Action     string         `json:"action"`
	Success    bool           `json:"success"`
	Data       map[string]any `json:"data,omitempty"`
	Error      string         `json:"error,omitempty"`
	Logs       []string       `json:"logs,omitempty"`
	ExecutedAt int64          `json:"executed_at"`
}

type ErrorResponse struct {
	Error ErrorDetail `json:"error"`
}

type ErrorDetail struct {
	Code    string `json:"code"`
	Message string `json:"message"`
	Details string `json:"details,omitempty"`
}

type Logger struct {
	mu     sync.Mutex
	writer io.Writer
}

func NewLogger(writer io.Writer) *Logger {
	return &Logger{writer: writer}
}

func (l *Logger) Log(entry map[string]any) {
	if l.writer == nil {
		return
	}
	l.mu.Lock()
	defer l.mu.Unlock()

	entry["time"] = time.Now().UTC().Format(time.RFC3339)
	data, err := json.Marshal(entry)
	if err == nil {
		_, _ = l.writer.Write(append(data, '\n'))
	}
}

type Server struct {
	cfg       *config.Config
	validator *security.RequestValidator
	registry  *actions.Registry
	logger    *Logger
	httpSrv   *http.Server
}

func NewServer(cfg *config.Config, validator *security.RequestValidator, registry *actions.Registry, logWriter io.Writer) *Server {
	if logWriter == nil {
		logWriter = os.Stdout
	}

	s := &Server{
		cfg:       cfg,
		validator: validator,
		registry:  registry,
		logger:    NewLogger(logWriter),
	}

	return s
}

func (s *Server) Routes() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/action", s.handleAction)
	mux.HandleFunc("/api/v1/action", s.handleAction)
	mux.HandleFunc("/healthz", s.handleHealthz)
	return mux
}

func (s *Server) handleHealthz(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(http.StatusOK)
	_, _ = w.Write([]byte(`{"status":"ok"}`))
}

func (s *Server) handleAction(w http.ResponseWriter, r *http.Request) {
	start := time.Now()
	w.Header().Set("Content-Type", "application/json")

	if r.Method != http.MethodPost {
		s.writeJSONError(w, http.StatusMethodNotAllowed, "METHOD_NOT_ALLOWED", "Only POST requests are accepted", "")
		return
	}

	// Limit request body to 4MB to prevent memory exhaustion
	r.Body = http.MaxBytesReader(w, r.Body, 4*1024*1024)
	bodyBytes, err := io.ReadAll(r.Body)
	if err != nil {
		s.writeJSONError(w, http.StatusBadRequest, "BAD_REQUEST", "Failed to read request body", err.Error())
		return
	}

	var req ActionRequest
	if err := json.Unmarshal(bodyBytes, &req); err != nil {
		s.writeJSONError(w, http.StatusBadRequest, "INVALID_JSON", "Malformed JSON payload", err.Error())
		return
	}

	// Ensure payload is valid JSON bytes (default to empty object if nil)
	payloadBytes := req.Payload
	if len(payloadBytes) == 0 {
		payloadBytes = []byte("{}")
	}

	// Security validation: Timestamp window, HMAC-SHA256 signature, Nonce replay
	if err := s.validator.ValidateRequest(req.Action, req.RequestID, req.Timestamp, req.Nonce, req.DryRun, payloadBytes, req.Signature); err != nil {
		code := "SECURITY_ERROR"
		status := http.StatusUnauthorized

		if errors.Is(err, security.ErrTimestampExpired) {
			code = "TIMESTAMP_EXPIRED"
		} else if errors.Is(err, security.ErrReplayedNonce) {
			code = "REPLAY_DETECTED"
		} else if errors.Is(err, security.ErrInvalidSignature) {
			code = "INVALID_SIGNATURE"
		} else if errors.Is(err, security.ErrMissingAction) || errors.Is(err, security.ErrMissingRequestID) || errors.Is(err, security.ErrEmptyNonce) {
			code = "INVALID_REQUEST"
			status = http.StatusBadRequest
		}

		s.logger.Log(map[string]any{
			"level":      "warn",
			"event":      "auth_failure",
			"code":       code,
			"action":     req.Action,
			"request_id": req.RequestID,
			"remote_ip":  r.RemoteAddr,
			"error":      err.Error(),
		})

		s.writeJSONError(w, status, code, err.Error(), "")
		return
	}

	// Execute typed action via registry
	res, execErr := s.registry.Execute(r.Context(), req.Action, payloadBytes, req.DryRun)
	durationMs := time.Since(start).Milliseconds()

	if errors.Is(execErr, actions.ErrUnknownAction) {
		s.logger.Log(map[string]any{
			"level":       "warn",
			"event":       "unknown_action",
			"action":      req.Action,
			"request_id":  req.RequestID,
			"duration_ms": durationMs,
		})
		s.writeJSONError(w, http.StatusNotFound, "UNKNOWN_ACTION", fmt.Sprintf("Action '%s' is not registered", req.Action), "")
		return
	}

	resp := ActionResponse{
		RequestID:  req.RequestID,
		Action:     req.Action,
		Success:    res.Success,
		Data:       res.Data,
		Error:      res.Error,
		Logs:       res.Logs,
		ExecutedAt: time.Now().Unix(),
	}

	s.logger.Log(map[string]any{
		"level":       "info",
		"event":       "action_executed",
		"action":      req.Action,
		"request_id":  req.RequestID,
		"success":     res.Success,
		"dry_run":     req.DryRun,
		"duration_ms": durationMs,
	})

	if execErr != nil && !res.Success {
		w.WriteHeader(http.StatusUnprocessableEntity)
	} else {
		w.WriteHeader(http.StatusOK)
	}

	_ = json.NewEncoder(w).Encode(resp)
}

func (s *Server) writeJSONError(w http.ResponseWriter, status int, code, message, details string) {
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(ErrorResponse{
		Error: ErrorDetail{
			Code:    code,
			Message: message,
			Details: details,
		},
	})
}

// BuildTLSConfig builds the strict mTLS tls.Config requiring valid client certificates.
func BuildTLSConfig(certFile, keyFile, clientCAFile string) (*tls.Config, error) {
	cert, err := tls.LoadX509KeyPair(certFile, keyFile)
	if err != nil {
		return nil, fmt.Errorf("loading server tls keypair: %w", err)
	}

	caCert, err := os.ReadFile(clientCAFile)
	if err != nil {
		return nil, fmt.Errorf("reading client CA cert: %w", err)
	}

	caPool := x509.NewCertPool()
	if !caPool.AppendCertsFromPEM(caCert) {
		return nil, errors.New("failed to parse client CA certificate PEM")
	}

	return &tls.Config{
		Certificates: []tls.Certificate{cert},
		ClientAuth:   tls.RequireAndVerifyClientCert,
		ClientCAs:    caPool,
		MinVersion:   tls.VersionTLS13, // Modern TLS 1.3 only
	}, nil
}

func (s *Server) Start() error {
	var tlsConf *tls.Config
	var err error

	// If mTLS files configured, build mTLS config
	if s.cfg.Server.TLSCertFile != "" && s.cfg.Server.TLSKeyFile != "" && s.cfg.Server.ClientCAFile != "" {
		tlsConf, err = BuildTLSConfig(s.cfg.Server.TLSCertFile, s.cfg.Server.TLSKeyFile, s.cfg.Server.ClientCAFile)
		if err != nil {
			return fmt.Errorf("configuring mTLS: %w", err)
		}
	}

	s.httpSrv = &http.Server{
		Addr:         s.cfg.Server.ListenAddress,
		Handler:      s.Routes(),
		TLSConfig:    tlsConf,
		ReadTimeout:  30 * time.Second,
		WriteTimeout: 60 * time.Second,
		IdleTimeout:  120 * time.Second,
	}

	// Create log directory if configured to write to file
	if s.cfg.Logging.LogFile != "" {
		_ = os.MkdirAll(filepath.Dir(s.cfg.Logging.LogFile), 0750)
	}

	if tlsConf != nil {
		return s.httpSrv.ListenAndServeTLS("", "")
	}

	return s.httpSrv.ListenAndServe()
}

func (s *Server) Close() error {
	if s.httpSrv != nil {
		return s.httpSrv.Close()
	}
	return nil
}
