# Threat Model and Security Hardening

## 1. System Architecture Overview & Trust Boundaries

The HPanel platform separates concerns into two distinct execution tiers:
1. **Control Panel (Laravel / PHP 8.4)**: User-facing web UI and API orchestrator running as `www-data`.
2. **Server Agent (`/agent`, Go single static binary)**: System-level daemon running under systemd on worker nodes, responsible for low-level server operations (Nginx configs, PHP-FPM pools, Linux user creation, MariaDB accounts, Certbot, restic backups).

### Trust Boundaries
```
[ Customer / Browser ] ─── HTTPS / 2FA ───> [ Laravel Control Panel ]
                                                    │
                                         Private Network (WireGuard)
                                         mTLS + HMAC-SHA256 Signatures
                                         Timestamp (<60s) + Nonces
                                                    ▼
                                            [ Go Server Agent ]
                                                    │
                                         Exec Argument Arrays (No Shell)
                                         Atomic Configs + Quotas
                                                    ▼
                                         [ Host OS / Isolated Users ]
```

---

## 2. STRIDE Threat Model Analysis

### 1. Spoofing
- **Threat**: Attacker attempts to impersonate the Control Panel to trigger actions on the Agent, or an attacker impersonates an Agent to feed forged telemetry back to the Panel.
- **Countermeasures**:
  - **Mutual TLS (mTLS)**: The agent strictly requires valid client certificates signed by the panel's private Certificate Authority (`tls.RequireAndVerifyClientCert`, TLS 1.3 only).
  - **HMAC-SHA256 Signatures**: Every payload carries an HMAC-SHA256 signature generated with a 256-bit shared secret.
  - **Replay Protection**: Requests outside a 60-second clock-skew window are rejected. Nonces are recorded in a sliding-window tracker (`security.NonceTracker`).
  - **Admin IP Allowlist**: Panel administrative routes (`/admin/*`, `/servers*`) can be restricted to designated IP addresses and CIDR blocks via `AdminIpAllowlistMiddleware`.

### 2. Tampering
- **Threat**: Malicious user injects shell commands or traverses directory trees via domain names, usernames, database names, or document roots.
- **Countermeasures**:
  - **No Generic Command Endpoints**: The agent contains zero `exec.Command("sh", "-c", ...)` calls and exposes only strictly typed actions (`/agent/internal/actions`).
  - **Strict Allow-List Regex Validation**:
    - Usernames: `^[a-z][a-z0-9]{2,15}$` (no root, no dashes, no spaces, no uppercase, no shell metacharacters).
    - Domains: RFC-compliant lowercase domain regex.
    - Database names: Must match `^[a-z][a-z0-9]{2,15}_[a-zA-Z0-9_]{1,40}$`.
  - **Directory Traversal Defense**: All file and document root paths are resolved with `filepath.Clean` and verified to remain strictly inside `/home/<user>`. Escapes via `../` or symlinks are rejected.
  - **Atomic Config Deployment**: Configurations (Nginx vhosts, PHP pools) are written to temporary files, validated using test flags (`nginx -t`, `php-fpm -t`), and only then moved atomically into place. If validation fails, changes rollback immediately.

### 3. Repudiation
- **Threat**: A rogue administrator, reseller, or customer performs destructive operations (e.g. dropping a database, terminating an account) and denies having executed the action.
- **Countermeasures**:
  - **Audit Logging**: Every state-changing operation records an immutable record in `audit_logs` storing `user_id`, `action`, `old_values`, `new_values`, and `ip_address`.
  - **Agent Execution Log**: The Go agent writes structured JSON logs (`/var/log/panel-agent/agent.log`) with `request_id`, `action`, `client_subject`, and `duration_ms`.

### 4. Information Disclosure
- **Threat**: Cross-account data leaks where Tenant A reads files or databases of Tenant B.
- **Countermeasures**:
  - **Per-Account Linux User Isolation**: Every hosting account has its own distinct Linux UID/GID. Home directories are permissioned `0750` or `0710`.
  - **Non-Root PHP-FPM Execution**: PHP pools run strictly as the account's Linux user (`user = alice`, `group = alice`).
  - **SFTP Chroot & Pure-FTPd Virtual Users**: SFTP sessions are jailed inside the user's home directory.
  - **Zero Cleartext Secrets**: Database passwords and API tokens are hashed or encrypted at rest (`encrypted` cast in Laravel) and scrubbed from all logs.

### 5. Denial of Service (DoS)
- **Threat**: Resource starvation via bandwidth exhaustion, disk filling, inode exhaustion, or brute-force API requests.
- **Countermeasures**:
  - **Filesystem Quotas**: Hard Linux disk quotas and inode limits block writes once limits are reached.
  - **Rate Limiting**: Panel endpoints are protected by Laravel throttling (e.g. 5 requests/min for server enrollment, 30/min for webhooks, 60/min for standard APIs).
  - **Fail2ban & UFW**: Automated IP bans on repeated SSH, Nginx auth, or bot-scanning failures.
  - **Server Drain Mode**: Prevents overloaded servers from accepting new accounts.

### 6. Elevation of Privilege
- **Threat**: An attacker escapes the hosting web root or compromises an account to gain `root` access on the host.
- **Countermeasures**:
  - **Exec Argument Arrays**: All system calls use `exec.Command(binary, arg1, arg2)` instead of shell invocation strings, eliminating command injection.
  - **Systemd Process Sandboxing**:
    - `ProtectSystem=full`
    - `PrivateTmp=true`
    - `CapabilityBoundingSet=CAP_NET_BIND_SERVICE CAP_CHOWN CAP_FOWNER CAP_DAC_OVERRIDE CAP_SETUID CAP_SETGID CAP_SYS_RESOURCE`
    - `NoNewPrivileges=false` (agent only escalates permissions for account user switching).

---

## 3. Production Hardening Checklist

| Layer | Hardening Measure | Status |
|---|---|---|
| **HTTP Headers** | `Content-Security-Policy` with frame-ancestors 'none' | Enforced via `SecurityHeadersMiddleware` |
| **HTTP Headers** | `X-Frame-Options: DENY` | Enforced |
| **HTTP Headers** | `X-Content-Type-Options: nosniff` | Enforced |
| **HTTP Headers** | `Strict-Transport-Security` (HSTS) max-age 31536000 | Enforced |
| **Authentication** | Two-Factor Authentication (TOTP + recovery codes) | Implemented |
| **Network** | Agent port (8443) bound only to WireGuard / private IP | Enforced in config & UFW |
| **Firewall** | UFW default deny incoming, only 22/80/443 public | Configured in `/deploy/install-node.sh` |
| **Intrusion** | Fail2ban jails for SSH and Nginx | Configured in `/deploy/fail2ban/jail.local` |
| **Isolation** | 1 Linux User = 1 PHP-FPM Pool = 1 Isolated Directory | Architectural Rule |

