# System Architecture & Topology

## Overview

The hosting control panel platform is partitioned into two core components:
1. **Hosting Dashboard (Panel)**: Centralized Laravel 11/12+ application managing user authentication, role-based access control (RBAC), multi-tenancy, packages, billing API, and asynchronous job orchestration.
2. **Server Agent**: Standalone Go binary executing as a systemd service on target Linux nodes (Ubuntu 24.04 / Debian 12), exposing strictly typed system management actions over an authenticated mTLS + HMAC channel.

```
                    ┌─────────────────────────┐
                    │    Customer / Reseller  │
                    │         Browser         │
                    └───────────┬─────────────┘
                                │ HTTPS
                                ▼
                    ┌─────────────────────────┐
                    │    Hosting Dashboard    │
                    │   (Laravel + Livewire)  │
                    └───────────┬─────────────┘
                                │ Signed Jobs (mTLS + HMAC-SHA256)
                                │ Over Private Network / WireGuard
                                ▼
                    ┌─────────────────────────┐
                    │      Server Agent       │
                    │       (Go daemon)       │
                    └───────────┬─────────────┘
                                │ Typed Actions (exec argument arrays)
         ┌──────────────┬───────┴──────┬──────────────┬──────────────┐
         ▼              ▼              ▼              ▼              ▼
     ┌───────┐    ┌──────────┐    ┌─────────┐    ┌─────────┐    ┌──────────┐
     │ Nginx │    │ PHP-FPM  │    │ MariaDB │    │ Certbot │    │ SFTP/    │
     │ vhost │    │  pools   │    │  users  │    │   SSL   │    │ restic   │
     └───────┘    └──────────┘    └─────────┘    └─────────┘    └──────────┘
```

## Key Architectural Principles

1. **Isolation by Design**:
   - Each customer account maps to an isolated Linux user (`/home/<user>`).
   - PHP-FPM executes under dedicated per-account pools with restricted `open_basedir` and disabled dangerous functions.
   - SFTP users are restricted via OpenSSH `ChrootDirectory`.
2. **Zero Shell Execution from Panel**:
   - The panel never invokes `exec`, `shell_exec`, or `Process` for provisioning.
   - Operations are enqueued as signed jobs, and the Go agent validates and performs system operations.
3. **Atomic Configurations**:
   - Configuration files for Nginx and PHP-FPM are rendered from structured templates.
   - Changes are verified using test flags (`nginx -t`, `php-fpm -t`) before moving atomically into active directories. If tests fail, files are rolled back immediately.
4. **Idempotence & Audit Trail**:
   - Every agent action can be retried safely without state corruption.
   - State mutations write audit log entries in both panel and agent logs.

