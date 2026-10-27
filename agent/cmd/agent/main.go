package main

import (
	"context"
	"flag"
	"fmt"
	"io"
	"log"
	"os"
	"os/signal"
	"path/filepath"
	"runtime"
	"syscall"
	"time"

	"github.com/hpanel/agent/internal/actions"
	"github.com/hpanel/agent/internal/config"
	"github.com/hpanel/agent/internal/security"
	"github.com/hpanel/agent/internal/server"
)

// Version is the current version of the hpanel agent.
const Version = "0.1.0"

func main() {
	configPath := flag.String("config", "/etc/panel-agent/config.yaml", "Path to agent configuration file")
	showVersion := flag.Bool("version", false, "Print agent version and exit")
	flag.Parse()

	if *showVersion {
		fmt.Printf("hpanel-agent version %s\n", Version)
		os.Exit(0)
	}

	log.Printf("Starting hpanel-agent %s using config: %s", Version, *configPath)

	cfg, err := config.Load(*configPath)
	if err != nil {
		log.Printf("Warning: Could not read config file (%v), proceeding with defaults and env variables", err)
		cfg = config.DefaultConfig()
	}

	// Setup logging destination (file or stdout)
	var logWriter io.Writer = os.Stdout
	if cfg.Logging.LogFile != "" {
		_ = os.MkdirAll(filepath.Dir(cfg.Logging.LogFile), 0750)
		f, err := os.OpenFile(cfg.Logging.LogFile, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0640)
		if err == nil {
			defer f.Close()
			logWriter = io.MultiWriter(os.Stdout, f)
		} else {
			log.Printf("Warning: Failed to open log file %s: %v. Logging to stdout only.", cfg.Logging.LogFile, err)
		}
	}

	// Ensure system temp directory exists with mode 0700
	if cfg.System.TempDir != "" {
		if err := os.MkdirAll(cfg.System.TempDir, 0700); err != nil {
			log.Printf("Warning: Failed to create temp dir %s: %v", cfg.System.TempDir, err)
		} else {
			_ = os.Chmod(cfg.System.TempDir, 0700)
		}
	}

	// Ensure default-deny vhost exists on Linux
	if runtime.GOOS == "linux" {
		if err := actions.EnsureDefaultVhost("/etc/nginx/sites-available", "/etc/nginx/sites-enabled"); err != nil {
			log.Printf("Warning: Failed to ensure 00-default-deny.conf: %v", err)
		}
	}

	// Initialize sliding window nonce tracker
	windowDuration := time.Duration(cfg.Auth.MaxTimestampSkewSeconds) * time.Second
	nonceTracker, err := security.NewNonceTracker(cfg.Auth.NonceRingFile, windowDuration)
	if err != nil {
		log.Fatalf("Fatal: failed to initialize nonce tracker: %v", err)
	}

	// Initialize request security validator
	validator := security.NewRequestValidator([]byte(cfg.Auth.HMACSecret), nonceTracker, cfg.Auth.MaxTimestampSkewSeconds)

	// Initialize typed action registry
	registry := actions.NewRegistry()
	registry.Register(actions.NewSystemPingAction(Version))
	registry.Register(actions.NewSystemHealthAction())

	accountCreate := actions.NewAccountCreateAction()
	registry.Register(accountCreate)
	registry.Register(actions.NewAccountSuspendAction())
	registry.Register(actions.NewAccountUnsuspendAction())
	registry.Register(actions.NewAccountUpgradeAction(accountCreate))
	registry.Register(actions.NewAccountTerminateAction())

	registry.Register(actions.NewVhostWriteAction())
	registry.Register(actions.NewVhostRemoveAction())
	registry.Register(actions.NewVhostReloadAction())

	// Nginx config manager actions
	registry.Register(actions.NewNginxConfigListAction())
	registry.Register(actions.NewNginxConfigReadAction())
	registry.Register(actions.NewNginxConfigWriteAction())
	registry.Register(actions.NewNginxConfigEnableAction())
	registry.Register(actions.NewNginxConfigDisableAction())
	registry.Register(actions.NewNginxConfigRestoreAction())
	registry.Register(actions.NewNginxConfigBackupsAction())
	registry.Register(actions.NewNginxConfigTestAction())
	registry.Register(actions.NewNginxCustomReadAction())
	registry.Register(actions.NewNginxCustomWriteAction())

	registry.Register(actions.NewSslIssueAction())
	registry.Register(actions.NewSslRenewAction())
	registry.Register(actions.NewSslRevokeAction())
	registry.Register(actions.NewSslStatusAction())
	registry.Register(actions.NewSslCustomAction())

	registry.Register(actions.NewPhpPoolWriteAction())
	registry.Register(actions.NewPhpPoolRemoveAction())
	registry.Register(actions.NewPhpListVersionsAction())
	registry.Register(actions.NewPhpInstallVersionAction())
	registry.Register(actions.NewPhpFpmStatusAction())

	registry.Register(actions.NewFileListAction())
	registry.Register(actions.NewFileReadAction())
	registry.Register(actions.NewFileWriteAction())
	registry.Register(actions.NewFileCreateAction())
	registry.Register(actions.NewFileDeleteAction())
	registry.Register(actions.NewFileRenameAction())
	registry.Register(actions.NewFileChmodAction())
	registry.Register(actions.NewFileUploadAction())
	registry.Register(actions.NewFileArchiveAction())
	registry.Register(actions.NewFileExtractAction())

	// Online SFTP typed actions
	registry.Register(actions.NewFsListAction())
	registry.Register(actions.NewFsStatAction())
	registry.Register(actions.NewFsMkdirAction())
	registry.Register(actions.NewFsCreateFileAction())
	registry.Register(actions.NewFsRenameAction())
	registry.Register(actions.NewFsMoveAction())
	registry.Register(actions.NewFsCopyAction())
	registry.Register(actions.NewFsDeleteAction())
	registry.Register(actions.NewFsReadTextAction())
	registry.Register(actions.NewFsWriteTextAction())
	registry.Register(actions.NewFsUploadBeginAction())
	registry.Register(actions.NewFsUploadChunkAction())
	registry.Register(actions.NewFsUploadCommitAction())

	registry.Register(actions.NewSshKeyAddAction())
	registry.Register(actions.NewSshKeyRemoveAction())
	registry.Register(actions.NewSftpAccountCreateAction())

	registry.Register(actions.NewDbCreateAction())
	registry.Register(actions.NewDbDropAction())
	registry.Register(actions.NewDbUserCreateAction())
	registry.Register(actions.NewDbUserSetPasswordAction())
	registry.Register(actions.NewDbUserGrantAction())
	registry.Register(actions.NewDbUserRevokeAction())
	registry.Register(actions.NewDbUserDropAction())
	registry.Register(actions.NewDbDumpAction())
	registry.Register(actions.NewDbImportAction())
	registry.Register(actions.NewDbListUsageAction())
	registry.Register(actions.NewDbSsoCreateAction())
	registry.Register(actions.NewDbSsoRevokeAction())

	registry.Register(actions.NewCronWriteAction())
	registry.Register(actions.NewCronListAction())
	registry.Register(actions.NewLogTailAction())
	registry.Register(actions.NewLogListAction())
	registry.Register(actions.NewLogRotateAction())

	registry.Register(actions.NewBackupCreateAction())
	registry.Register(actions.NewBackupListAction())
	registry.Register(actions.NewBackupRestoreAction())
	registry.Register(actions.NewBackupDeleteAction())
	registry.Register(actions.NewBackupVerifyAction())

	registry.Register(actions.NewGitDeployAction())
	registry.Register(actions.NewGitRollbackAction())

	registry.Register(actions.NewWordpressInstallAction())
	registry.Register(actions.NewWordpressUninstallAction())

	registry.Register(actions.NewHtpasswdWriteAction())
	registry.Register(actions.NewHtpasswdDeleteAction())

	registry.Register(actions.NewUsageCollectAction())

	// Initialize HTTP/mTLS server
	srv := server.NewServer(cfg, validator, registry, logWriter)

	// Trap termination signals for graceful shutdown
	stop := make(chan os.Signal, 1)
	signal.Notify(stop, os.Interrupt, syscall.SIGTERM)

	go func() {
		log.Printf("Agent listening on %s (TLS configured: %v)", cfg.Server.ListenAddress, cfg.Server.TLSCertFile != "")
		if err := srv.Start(); err != nil && err.Error() != "http: Server closed" {
			log.Fatalf("Fatal server error: %v", err)
		}
	}()

	<-stop
	log.Println("Shutting down agent gracefully...")

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	_ = srv.Close()
	_ = ctx
	log.Println("Agent stopped.")
}
