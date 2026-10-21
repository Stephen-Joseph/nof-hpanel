# Module Documentation: PHP Versions & FPM Workers

## 1. Overview
The PHP module provides complete multi-version PHP execution and per-account process isolation for websites hosted on HPanel:
- Simultaneous support for PHP `8.1`, `8.2`, `8.3`, and `8.4` (installed via Ondřej Surý PPA).
- Independent per-account PHP-FPM worker pools executing under the account's dedicated Linux user and group.
- Per-website PHP version switching with automatic Nginx FastCGI socket re-pointing.
- Safe runtime `php.ini` directive customization adhering to package quotas (`memory_limit`, `upload_max_filesize`, `post_max_size`, `max_execution_time`, `display_errors`).
- Pre-flight syntax validation (`php-fpm<version> -t`) with automatic rollback on syntax or reload errors.
- Real-time worker pool inspection (`active_workers`, `idle_workers`, `total_workers`).

---

## 2. Process & Security Isolation

### 2.1 Per-Account Unix Sockets
Each hosting account runs in its own FPM pool file:
`/etc/php/<version>/fpm/pool.d/<username>.conf`
The pool listens on a private Unix socket:
`/run/php/php<version>-fpm-<username>.sock`
Socket permissions are restricted to:
```ini
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
```
This ensures Nginx can reverse-proxy requests, while customer processes cannot read or write to other users' sockets.

### 2.2 Account Sandboxing & open_basedir
Every generated pool enforces strict directory containment:
```ini
php_admin_value[open_basedir] = /home/<username>/:/tmp/:/var/tmp/
php_admin_value[upload_tmp_dir] = /home/<username>/tmp
php_admin_value[session.save_path] = /home/<username>/tmp
php_admin_value[error_log] = /home/<username>/logs/php_error.log
php_admin_flag[log_errors] = on
```
Customers are prevented from inspecting PHP sessions, upload files, or system directories belonging to other accounts.

---

## 3. Go Agent Typed PHP Actions

| Action | Input Schema | Purpose |
|---|---|---|
| `php.list_versions` | _None_ | Discovers installed PHP binaries (`/usr/bin/php*`) and checks active systemd status for each version's FPM daemon. |
| `php.install_version` | `version` | Installs PHP-FPM, CLI, and common extensions (e.g. `mysql`, `xml`, `mbstring`, `curl`) from the Sury PPA allow-list. |
| `php.pool_write` | `username`, `version`, `pm_mode`, `max_children`, `max_memory_mb`, `settings` | Validates configuration, tests with `php-fpm<ver> -t`, atomically writes pool config, and reloads `systemctl reload php<ver>-fpm`. Rolls back on failure. |
| `php.pool_remove` | `username`, `version` | Deletes pool config and reloads service. |
| `php.fpm_status` | `username`, `version` | Checks socket health and process count executing for the user's pool. |

---

## 4. Worker Management & Scaling

PHP worker processes are capped by the hosting package's `php_workers` limit:
- **Default Mode (`ondemand`)**: Workers only spawn upon receiving incoming HTTP requests and terminate after `pm.process_idle_timeout = 10s` of inactivity, saving memory on idle accounts.
- **Maximum Workers (`pm.max_children`)**: Derived directly from `package.php_workers`. If an account reaches its worker cap, requests queue until a worker frees up, preventing server resource exhaustion.

---

## 5. Failure Recovery

### Syntax Test Failure during Deployment
If an invalid INI override is submitted:
1. The pre-flight test `php-fpm<ver> -t` detects the error on the temporary staging file.
2. The staging file is removed and the live pool is left unmodified.
3. The error details are returned to the panel and logged in `AgentJob`.

### Worker Exhaustion
If websites experience slow responses during peak traffic:
1. Open the **PHP Manager** (`/domains/{domain}/php`) and click **Refresh** on the FPM Worker Status card.
2. If `active_workers` equals the maximum allowed workers, upgrade the account package to grant additional workers (`pm.max_children`).

