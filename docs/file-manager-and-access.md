# Module Documentation: File Manager, SFTP & SSH Keys

## 1. Overview
The Access and File Management module provides isolated file system interaction, SFTP sub-accounts, and SSH key management for hosting accounts on HPanel:
- Web File Manager: browse, view, edit text files (up to 5MB), create files/folders, rename, delete, change permissions (octal chmod), compress into zip, and extract archives.
- All file operations are executed strictly through typed Go Agent actions with zero shell execution and comprehensive path containment checks.
- SFTP access: sub-accounts restricted to specific directories (`ChrootDirectory`) enforced via OpenSSH.
- SSH Key management: validates key types (`ssh-ed25519`, `ecdsa`, `ssh-rsa` &ge; 2048-bit), rejects weak keys, and enforces a strict SFTP jail when interactive shell access is disabled by the hosting package (`package.ssh_enabled = false`).

---

## 2. Security Architecture & Traversal Defenses

### 2.1 Path Containment & Symlink Guards (`ResolveSafePath`)
Every file action verifies that the requested path resides strictly within `/home/<username>/`:
1. **Null Byte Disallowance**: Any path containing null bytes (`\x00`) is rejected.
2. **Lexical Normalization**: Resolves `.` and `..` via `filepath.Clean`. If the resulting candidate path does not begin with `/home/<username>/`, it is immediately rejected.
3. **Symlink Target Resolution (`filepath.EvalSymlinks`)**: If a file, folder, or parent directory is a symbolic link, the real target path is resolved. If the target points outside `/home/<username>/` (e.g. symlink to `/etc/passwd` or another customer's files), the action is blocked.
4. **Account Root Protection**: The user's root home directory (`/home/<username>`) cannot be deleted or renamed.

### 2.2 Zip Slip & Decompression Bomb Prevention (`ExtractZipSafely`)
Archive extraction enforces strict safety constraints:
- **Zip Slip Defense**: Validates each file header path inside the zip. Any entry attempting to navigate outside the destination directory using `../` is rejected.
- **Decompression Limit**: Caps extraction to a maximum of 500MB per file to mitigate zip bomb denial-of-service attacks.

### 2.3 SSH Shell Jailing
If the hosting package has `ssh_enabled = false`:
The agent writes keys into `~/.ssh/authorized_keys` with forced SFTP-only command prefixes:
```
no-port-forwarding,no-X11-forwarding,no-agent-forwarding,no-pty,command="/usr/lib/openssh/sftp-server" ssh-ed25519 AAAAC3NzaC1...
```
This enables secure SFTP client connections (FileZilla, Cyberduck, VS Code) while strictly blocking interactive bash/sh terminals.

---

## 3. Go Agent Typed Access Actions

| Action | Input Schema | Purpose |
|---|---|---|
| `file.list` | `username`, `path` | Reads directory entries, returning names, sizes, permissions, mod times, and editability flags. |
| `file.read` | `username`, `path` | Reads text files (enforces 5MB size cap). |
| `file.write` | `username`, `path`, `content` | Writes content to file preserving or defaulting to `0644` permissions. |
| `file.create` | `username`, `path`, `is_dir` | Creates directory (`0755`) or empty file (`0644`). |
| `file.delete` | `username`, `path` | Recursively removes file or directory (except home root). |
| `file.rename` | `username`, `source_path`, `target_path` | Renames / moves file or folder within account home. |
| `file.chmod` | `username`, `path`, `mode` | Modifies file permissions using validated octal string (e.g. `0644`, `0755`). |
| `file.archive` | `username`, `sources[]`, `destination_zip` | Compresses multiple files/folders into a `.zip` archive. |
| `file.extract` | `username`, `zip_path`, `destination_dir` | Safely extracts zip files guarding against path traversal. |
| `sftp.account_create`| `parent_user`, `subuser`, `homedir`, `password` | Configures secondary SFTP sub-account restricted to subfolder. |
| `ssh.key_add` | `username`, `public_key`, `name`, `ssh_enabled` | Validates key strength, writes to `authorized_keys`, applies SFTP jail if shell disabled. |
| `ssh.key_remove` | `username`, `public_key` | Purges public key from `authorized_keys`. |

---

## 4. Failure Recovery

### Permission Denied inside Account Home
If user files have incorrect ownership (e.g. created by root during manual migrations):
1. Confirm files are owned by the account's Linux user: `chown -R <username>:<username> /home/<username>`.
2. Standard directory permissions should be `0755`, standard file permissions `0644`.

### Weak SSH Key Rejected
If the panel rejects an SSH key upload:
- Weak RSA keys under 2048 bits and outdated DSA (`ssh-dss`) keys are rejected for security.
- Generate a modern, highly secure key using: `ssh-keygen -t ed25519 -C "user@device"`.

