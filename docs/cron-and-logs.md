# Module Documentation: Cron Editor & Logs Diagnostics

## 1. Overview
The Cron Editor and Logs Diagnostics module provides safe recurring task scheduling and real-time log inspection for hosting accounts on HPanel:
- Structured cron editor featuring common presets (hourly, daily, weekly, monthly, every N minutes) alongside granular field pickers (minute, hour, day, month, weekday).
- Per-account crontab isolation executing strictly as the account's unprivileged Linux user.
- Automatic stdout/stderr capture redirected into `/home/<user>/logs/cron_<id>.log` for instant terminal debugging.
- Live web terminal log viewer for domain access logs, error logs, PHP-FPM error logs, and cron logs with live auto-refresh, grep/search filtering, and secure text downloads.
- Automated per-account logrotate management.

---

## 2. Security Architecture & Threat Mitigations

### 2.1 Crontab Injection & Privilege Escalation Defenses
1. **Format Validation**: Cron expressions are strictly validated against standard 5-part fields (`<minute> <hour> <day_of_month> <month> <day_of_week>`). Six-part second-level syntax or non-standard characters are rejected.
2. **Injection Neutralization**: Commands and emails are checked for carriage returns (`\r`), newlines (`\n`), and null bytes (`\x00`). This completely mitigates crontab injection exploits.
3. **Privilege Escalation Disallowance**: Commands containing `sudo`, `su`, or `doas` are blocked at both panel validation and agent validation layers.
4. **Execution Boundary**: External commands are invoked using argument arrays (`exec.CommandContext(ctx, "crontab", "-u", username, tmpPath)`). The crontab is rendered to a secured temporary file (`0600` permissions), atomically installed, and immediately purged.
5. **Enforced Output Redirection**: All active cron jobs redirect both stdout and stderr into dedicated per-job logs:
   ```
   */15 * * * * /usr/bin/php /home/<user>/artisan schedule:run >> /home/<user>/logs/cron_<id>.log 2>&1
   ```

### 2.2 Log Traversal & Memory Safety Defenses
1. **Path Traversal Protection**: The agent strictly validates requested log filenames (`ValidateLogFile`). Only base filenames matching `^[a-zA-Z0-9_\-\.]+\.log(\.[0-9]+)?$` residing directly inside `/home/<username>/logs/` are permitted. Any path separators (`/`, `\`) or `..` are immediately rejected.
2. **Bounded Memory Reads**: Log files are read by seeking up to 2MB from the end of the file rather than buffering entire multi-gigabyte logs into RAM.
3. **Download Safety**: Web download endpoints enforce route constraints and authorization checks, rejecting path traversal attempts with HTTP 400 Bad Request.

---

## 3. Go Agent Typed Actions

| Action | Input Schema | Purpose |
|---|---|---|
| `cron.write` | `username`, `jobs[]` (`id`, `schedule`, `command`, `email_output`, `is_enabled`) | Atomically renders and installs crontab for user; clears if 0 active jobs. |
| `cron.list` | `username` | Reads installed crontab for verification. |
| `log.tail` | `username`, `log_file`, `lines`, `search` | Tails last $N$ lines (max 1000) from `/home/<username>/logs/<log_file>` with optional search filter. |
| `log.list` | `username` | Returns available `.log` files in `/home/<username>/logs/` categorized by type (access, error, php, cron). |
| `log.rotate` | `username`, `force` | Configures `/etc/logrotate.d/hpanel-<username>` and optionally triggers forced log rotation. |

---

## 4. Troubleshooting & Failure Recovery

### 4.1 Cron Daemon Status
If cron jobs do not execute at scheduled intervals:
1. Verify system cron service: `systemctl status cron` (Debian/Ubuntu).
2. Check user crontab installation: `crontab -u <username> -l`.
3. Check the per-job output log: `/home/<username>/logs/cron_<id>.log`.

### 4.2 Permission Denied or File Not Found in Cron
Cron executes with a minimal environment (`PATH=/usr/local/bin:/usr/bin:/bin:/home/<username>/bin`). Always specify full binary paths (e.g. `/usr/bin/php` or `/usr/bin/python3`).

### 4.3 Log File Truncation / High Disk Usage
If logs consume excessive disk space:
1. Trigger manual rotation via the UI or agent action `log.rotate` with `force: true`.
2. Inspect logrotate config at `/etc/logrotate.d/hpanel-<username>`.

