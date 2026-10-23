# Module Documentation: Encrypted Backups & Snapshots (restic)

## 1. Overview
The Backups and Snapshots module provides encrypted, deduplicated, and incremental data protection for hosting accounts on HPanel using [restic](https://restic.net/):
- **Encrypted Snapshots**: Every snapshot is protected with customer-isolated repository keys.
- **Full & Granular Scopes**: Backs up account files (`/home/<user>`) while staging MariaDB database dumps (`mysqldump --single-transaction --quick`) into atomic snapshots.
- **Granular Restore Wizard**: Customers can restore their entire account, files only, databases only, a single database, or a specific directory/file path.
- **Automated Scheduling & Pruning**: Daily, weekly, or monthly scheduler policies enforced through the Laravel scheduler and pruned automatically based on package retention limits (`package.backups_retained`).
- **Webhooks Integration**: Dispatches signed HMAC webhooks (`backup.completed`, `backup.failed`) on every snapshot execution.

---

## 2. Security Architecture & Threat Mitigations

### 2.1 Zero Cleartext Secret Exposure
- Restic encryption passwords are never passed as command-line arguments (preventing inspection in `ps aux` or process tables).
- Instead, credentials are injected securely through `cmd.Env` as `RESTIC_PASSWORD=...`.
- Database authentication uses Debian system maintenance credentials (`/etc/mysql/debian.cnf`).

### 2.2 Strict Target Path & Database Confinement
- Single path restores are strictly verified with `filepath.Clean` to ensure the destination path resides within `/home/<username>/`.
- Single database restores strictly verify that the target database matches `<username>_%` preventing multi-tenant data contamination.
- The user's root home directory cannot be overwritten with arbitrary outside targets.

### 2.3 Automated Retention & Pruning
Automated backup jobs enforce the package retention limit:
```bash
restic -r /var/backups/hpanel/<user> forget --keep-last <retention_count> --prune --tag scheduled
```
Manual snapshots are retained indefinitely unless deleted by the customer or administrator.

---

## 3. Go Agent Typed Actions

| Action | Input Schema | Purpose |
|---|---|---|
| `backup.create` | `username`, `backup_type`, `repo_path`, `databases[]`, `retention_count` | Stages database dumps, creates restic snapshot, cleans staging, and prunes older automated snapshots. |
| `backup.list` | `username`, `repo_path` | Queries restic repository snapshots and returns JSON summary (IDs, timestamps, file sizes, tags). |
| `backup.restore` | `username`, `snapshot_id`, `restore_type`, `target_path`, `target_db` | Restores snapshot data (full account, files only, databases only, single DB, or single path). |
| `backup.delete` | `username`, `snapshot_id` | Forgets snapshot and reclaims repository storage via `--prune`. |
| `backup.verify` | `username` | Runs `restic check` to verify repository index and pack integrity. |

---

## 4. Scheduling & CLI Commands

### 4.1 Scheduled Execution
The Artisan command triggers all due automated backups:
```bash
php artisan backups:run-scheduled
```
This is registered in Laravel's console kernel and runs hourly or via system cron. It ensures accounts with an ongoing backup snapshot in progress are safely skipped to prevent concurrent repository lock contention.

---

## 5. Troubleshooting & Failure Recovery

### 5.1 Repository Lock Contention
If an agent process terminates unexpectedly during backup creation, restic may leave a lock:
```bash
RESTIC_PASSWORD=... restic -r /var/backups/hpanel/<username> unlock
```

### 5.2 Repository Integrity Check
To verify index integrity and detect any bitrot or damaged packs:
```bash
RESTIC_PASSWORD=... restic -r /var/backups/hpanel/<username> check
```

### 5.3 Manual Emergency Restore
To manually restore an account directly from the command line:
```bash
RESTIC_PASSWORD=... restic -r /var/backups/hpanel/<username> restore <snapshot_id> --target /
```

