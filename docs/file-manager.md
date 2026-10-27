# File Manager Architecture & Configuration

## Overview
The HPanel File Manager provides web-based file management, multi-file uploads, archive extraction (`.zip`, `.tar.gz`, `.tgz`), and archiving (`.zip`) for hosting accounts.

In accordance with HPanel non-negotiable security rules:
- The Laravel panel never executes direct shell commands.
- All file operations are dispatched as signed, typed actions to the node agent (`file.upload`, `file.extract`, `file.archive`, `file.list`, `file.read`, `file.write`, `file.delete`, `file.mkdir`, `file.chmod`).
- All actions run within the account user's home directory jail (`/home/<user>/`), verified via `filepath.EvalSymlinks`.

---

## Server & Runtime Configuration

To support multi-file uploads up to 100 MB without truncation:

### 1. Panel PHP-FPM Configuration
Configured in `/etc/php/<version>/fpm/php.ini`:
```ini
upload_max_filesize = 128M
post_max_size = 128M
memory_limit = 256M
```

### 2. Panel Nginx Virtual Host
Configured in `/etc/nginx/sites-available/hpanel`:
```nginx
client_max_body_size 128M;
```

### 3. Application Configuration
Configured in `config/hpanel.php`:
```php
'max_upload_mb' => (int) env('HPANEL_MAX_UPLOAD_MB', 100),
```

### 4. Temporary Upload Directories
- `storage/app/livewire-tmp`: Livewire temporary upload storage; created with `0775` permissions owned by `www-data:www-data`.
- `storage/app/private/uploads`: Panel staging directory for chunking base64 payload to the agent; cleaned up immediately after agent confirms receipt.

---

## Agent Typed Actions

### 1. `file.upload`
- Supports chunked uploads with `chunk_index`, `total_chunks`, and `upload_id`.
- Chunks are assembled in a temporary file beside the destination in the account home.
- Verified against the account's disk quota.
- Atomic rename to target destination upon the final chunk.
- Permissions set to `0644` (or `0755` for scripts/executables) owned by the account Linux user.

### 2. `file.extract`
- Supports standard `.zip`, `.tar.gz`, and `.tgz` archives.
- Destination folder strictly validated inside `/home/<user>/`.
- Security validations:
  - Rejects path traversal (`../`, absolute paths).
  - Rejects symlinks or hard links escaping the extraction root.
  - Rejects device nodes, FIFOs, and special files.
  - Enforces per-entry and total decompression size limits (protection against zip bombs).
- Extracted into a temporary staging folder, atomically moved, and chowned recursively to `<user>:<user>` with mode `0755` for directories and `0644` for files.

### 3. `file.archive`
- Compresses selected files and directories inside the account into a target `.zip` file.
- Prevents archiving outside `/home/<user>/`.
- Final archive owned by `<user>:<user>` with mode `0644`.

---

## Online SFTP ("Browser-based SFTP Client")

### 1. Architecture Decision: Option B
We chose **Option B (Panel-side file browsing through typed agent file actions with an SFTP-like UI)**:
- **Zero New Attack Surface**: Avoids opening extra external or loopback WebSocket ports or running dynamic SSH proxy daemons.
- **Unified Security Model**: Fully leverages the existing mTLS + HMAC-SHA256 request signing and per-account Linux user isolation.
- **Desktop SFTP UX**: Two-pane interface with folder tree rooted at `/home/<user>`, breadcrumb navigation, real-time file filtering, inline text editor (< 1 MB), atomic file saves, chunked transfers, and quota monitoring.

### 2. Typed Agent Actions (`fs.*`)
Registered in `/agent/internal/actions/fs_actions.go`:
- `fs.list(path)`: Lists directory entries (name, type, size, mode, mtime, owner). Symlinks are reported as `type: "symlink"` without following them outside `/home/<user>`.
- `fs.stat(path)`: Returns detailed entry metadata and link targets.
- `fs.mkdir(path)`: Creates directory with mode `0755` and chowns to `<user>:<user>`.
- `fs.create_file(path)`: Creates empty file with mode `0644` and chowns to `<user>:<user>`.
- `fs.rename(source, target)`: Renames file or directory within home; refuses home root.
- `fs.move(source, target)`: Moves file or directory into destination directory within home; refuses home root.
- `fs.copy(source, target)`: Recursively copies directories or files with a 100 MB safety limit; chowns all created objects to `<user>:<user>` and rejects escaping symlinks.
- `fs.delete(path)`: Recursively removes files/directories; strictly refuses to delete the account home root (`/home/<user>`). For symlinks, removes only the symlink node without touching the target.
- `fs.read_text(path)`: Reads text file contents up to 1 MB; strictly checks for NUL bytes (`\0`) and rejects binary files.
- `fs.write_text(path, content)`: Atomically writes text using a same-directory temporary file (`.tmp-*`), syncs, chmods `0644`, chowns to `<user>:<user>`, and renames.
- `fs.upload_begin(destination_dir, filename, total_size, quota_remaining)`: Validates filename, checks quota remaining, and allocates an upload session with temporary staging in the destination folder.
- `fs.upload_chunk(session_id, chunk_index, content_base64)`: Validates session and appends base64-decoded chunks (max 4 MB per chunk).
- `fs.upload_commit(session_id)`: Atomically renames temporary file to target filename, sets mode `0644`, and chowns to `<user>:<user>`.

### 3. Panel Security & Session Controls
- **Authorization**: Governed by `AccountPolicy@view` and `AccountPolicy@manageFiles` (owner, reseller subtree, or admin only).
- **Session Freshness / PIN**: The Online SFTP interface requires fresh authentication (within 30 minutes) or prompts for the account owner PIN (configured via `HPANEL_ONLINE_SFTP_PIN` in `config/hpanel.php`) or user password.
- **Audit Logging**: Every mutating action creates an entry in `audit_logs` recording action, account, path, size, and client IP without logging file contents, passwords, or tokens.
- **Rate Limiting**: Enforces a strict 120 requests/minute limit per user via Laravel's rate limiter (`throttle:online_sftp`), returning HTTP 429 when exceeded.
- **Filename Sanitization**: Rejects all control characters, null bytes (`\0`), path separators (`/`, `\`), and relative traversal (`..`).

---

## Authorization & Auditing
- Managed through Laravel Policy (`AccountPolicy::update`).
- Customers can only manage their own account's files.
- Resellers can only manage accounts within their subtree.
- Administrators can manage all accounts.
- Every upload, extract, and archive action generates an `AgentJob` tracked in the database and audit trail.

