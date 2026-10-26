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

## Authorization & Auditing
- Managed through Laravel Policy (`AccountPolicy::update`).
- Customers can only manage their own account's files.
- Resellers can only manage accounts within their subtree.
- Administrators can manage all accounts.
- Every upload, extract, and archive action generates an `AgentJob` tracked in the database and audit trail.
