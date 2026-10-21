# Server Agent Security Architecture & Threat Model

This document outlines the security architecture, threat model, and cryptographic protocol for communication between the Hosting Panel (Laravel) and the Server Agent (`/agent`, Go binary).

---

## 1. Architectural Overview & Trust Boundary

```
+-------------------------------------------------------------+
|                     Laravel Panel (Web/API)                 |
|  - Role-based multi-tenancy (Admin / Reseller / Customer)    |
|  - Strictly NO shell execution (`exec`, `proc_open` banned) |
|  - Enqueues signed action jobs to Laravel Queue             |
+-------------------------------------------------------------+
                              |
                     mTLS + HMAC-SHA256
               Private WireGuard Network (e.g. 10.0.0.x)
                              |
                              v
+-------------------------------------------------------------+
|                      Go Server Agent                        |
|  - Runs as systemd service on target server                 |
|  - Binds strictly to private network interface              |
|  - Strictly typed Action Registry (no arbitrary commands)   |
|  - External commands strictly via argument arrays           |
|  - Ephemeral dry-run capability for every action            |
+-------------------------------------------------------------+
```

### Core Security Tenets
1. **Zero Shell Execution from Panel**: Laravel NEVER invokes `exec()`, `shell_exec()`, `system()`, `passthru()`, or `proc_open()` for provisioning or server management.
2. **Strict Typed Action Registry**: The Agent only recognizes actions explicitly registered in `internal/actions/` (e.g., `system.ping`, `system.health`, `account.create`). Generic `command.exec` or shell endpoints are strictly forbidden.
3. **Defense-in-Depth Authentication**: Every request requires both transport-layer authentication (mTLS) and application-layer cryptographic proof (HMAC-SHA256 signature + timestamp + nonce).

---

## 2. Threat Model & Mitigations

### Threat 1: Command Injection & Arbitrary Code Execution
* **Risk**: An attacker who compromises a user or reseller account injects shell metacharacters (`;`, `&&`, `|`, backticks) into usernames, domains, or paths.
* **Mitigations**:
  * **Regex Allow-lists**: Linux usernames are constrained to `^[a-z][a-z0-9]{2,15}$`. Domains must conform to RFC lowercase domain format. Document roots must resolve inside `/home/<user>`.
  * **Argument Arrays Only**: External system utilities run strictly with argument arrays via Go's `exec.CommandContext(ctx, "binary", "arg1", "arg2")`. Shell string concatenation and `sh -c` are completely banned.
  * **Strict Action Schema**: Each action implements `Validate(payload []byte) error` before execution begins.

### Threat 2: Man-in-the-Middle (MitM) & Eavesdropping
* **Risk**: Interception, eavesdropping, or tampering with panel-to-agent communication over the network.
* **Mitigations**:
  * **Mutual TLS (mTLS)**: The agent enforces `ClientAuth: tls.RequireAndVerifyClientCert`. Only panel requests bearing a valid x509 client certificate issued by the dedicated private CA are accepted.
  * **Private Network Binding**: The agent binds exclusively to a private address (e.g. WireGuard interface or VPC subnet) and is never exposed to the public internet.
  * **TLS 1.3 Minimum**: Weak ciphers and legacy TLS protocols are rejected.

### Threat 3: Request Tampering & Forgery
* **Risk**: Modification of request parameters (e.g., altering target user or resource limits in transit).
* **Mitigations**:
  * **HMAC-SHA256 Signatures**: Every request payload is hashed and signed with a shared HMAC secret.
  * **Constant-Time Verification**: Go's `subtle.ConstantTimeCompare` (via `hmac.Equal`) prevents timing side-channel attacks.

### Threat 4: Replay Attacks
* **Risk**: An attacker captures a valid signed request and re-sends it later to repeat operations.
* **Mitigations**:
  * **Timestamp Window**: Requests must have a timestamp within $\pm 60$ seconds of the server's clock (`|now - timestamp| <= 60`). Requests outside this window are immediately rejected with HTTP 401 `TIMESTAMP_EXPIRED`.
  * **Nonce Ring Buffer**: Every request carries a unique 128-bit random hex nonce. The agent tracks nonces in a sliding-window in-memory map backed by a persistent append-only disk ring buffer (`nonces.dat`). If a nonce has already been seen within the freshness window, it is rejected with HTTP 401 `REPLAY_DETECTED`.

### Threat 5: Account Traversal & Privilege Escalation
* **Risk**: A customer on a shared server escapes their jail to inspect other accounts' files, databases, or processes.
* **Mitigations**:
  * **Per-Account Linux User**: Each hosting account is owned by a unique unprivileged Linux system user.
  * **Isolated PHP-FPM Pools**: Each account executes via its own PHP-FPM pool running under that user's UID/GID, with `open_basedir` pinned to `/home/<user>`.
  * **SFTP Chroot**: SFTP logins are locked into `ChrootDirectory /home/%u` via OpenSSH match rules.

### Threat 6: Denial of Service / Payload Flooding
* **Risk**: Sending excessively large payloads to exhaust agent memory.
* **Mitigations**:
  * **Max Request Size**: HTTP body reads are limited to 4 MB via `http.MaxBytesReader`.
  * **Sliding Window Pruning**: Nonce tracker automatically discards entries older than 60 seconds on every write.

---

## 3. Cryptographic Protocol Specification

### Canonical Signing String
Before computing the HMAC-SHA256 signature, both the panel and agent construct an identical canonical string:

```
action.request_id.timestamp.nonce.dry_run.payload_sha256_hex
```

| Field | Description | Example |
|---|---|---|
| `action` | Registered action identifier | `system.ping` |
| `request_id` | Unique UUIDv4 of the operation | `9e23ac42-7df0-4d96-bfb1-dcc23aedabae` |
| `timestamp` | Unix epoch time in seconds | `1774000000` |
| `nonce` | 32-character hexadecimal string | `a3f89e1b4c7d025e89a1b2c3d4e5f607` |
| `dry_run` | `1` if dry run is active, `0` otherwise | `0` |
| `payload_sha256_hex` | SHA-256 hash of the exact raw JSON payload | `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855` |

### Request Envelope
```json
{
  "action": "system.ping",
  "request_id": "9e23ac42-7df0-4d96-bfb1-dcc23aedabae",
  "timestamp": 1774000000,
  "nonce": "a3f89e1b4c7d025e89a1b2c3d4e5f607",
  "dry_run": false,
  "payload": {},
  "signature": "d4735e3a265e16eee03f59718b9b5d03019c07d8b6c51f90da3a666eec13ab35"
}
```

### Successful Response Format (HTTP 200)
```json
{
  "request_id": "9e23ac42-7df0-4d96-bfb1-dcc23aedabae",
  "action": "system.ping",
  "success": true,
  "data": {
    "pong": true,
    "timestamp": 1774000000,
    "agent_version": "0.1.0",
    "dry_run": false
  },
  "logs": [
    "system.ping executed successfully"
  ],
  "executed_at": 1774000000
}
```

### Error Response Format (HTTP 4xx / 5xx)
```json
{
  "error": {
    "code": "TIMESTAMP_EXPIRED",
    "message": "security: request timestamp is older than allowed window or too far in the future",
    "details": ""
  }
}
```

Standard Error Codes:
- `TIMESTAMP_EXPIRED` (HTTP 401)
- `REPLAY_DETECTED` (HTTP 401)
- `INVALID_SIGNATURE` (HTTP 401)
- `UNKNOWN_ACTION` (HTTP 404)
- `VALIDATION_FAILED` (HTTP 422)
- `BAD_REQUEST` (HTTP 400)

---

## 4. Atomic Configuration Deployments & Rollback Strategy

All agent actions modifying server configurations (Nginx vhosts, PHP-FPM pools, crontabs) MUST follow the atomic pattern:

1. Render configuration into a staging file: `/tmp/panel-agent/<action>-<uuid>.conf`.
2. Execute syntax test with tool-specific flag:
   - Nginx: `nginx -t -c <staging_file>`
   - PHP-FPM: `php-fpm<version> -t`
3. If syntax test fails:
   - Delete staging file immediately.
   - Return structured error with syntax test stderr.
   - Do NOT affect live production configurations.
4. If syntax test passes:
   - Create backup copy of existing live configuration: `<live_path>.bak`.
   - Atomically rename staging file over live configuration via `os.Rename`.
   - Reload service: `systemctl reload <service>`.
   - If reload fails, restore `<live_path>.bak` and reload again.

