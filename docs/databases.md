# Module Documentation: MariaDB Databases & phpMyAdmin SSO

## 1. Overview
The Databases module provides isolated MariaDB / MySQL management for hosting accounts on HPanel:
- Database creation and deletion strictly prefixed with `<username>_` (e.g. `alicecorp_wp`).
- Dedicated database user creation, password management, and host allow-listing (`localhost`, `%`, or specific remote IPs).
- Fine-grained database privilege assignment (`ALL PRIVILEGES`, `SELECT`, `INSERT`, `UPDATE`, `DELETE`, `CREATE`, `DROP`, `INDEX`, `ALTER`, etc.).
- Resource limit enforcement based on hosting package (`max_databases` and `max_db_users`).
- On-demand database export (SQL dump via `mysqldump` to `/home/<username>/backups/`) and usage synchronization.
- One-click SSO to bundled phpMyAdmin / Adminer using short-lived, single-use signed tokens served on an isolated domain.

---

## 2. Security Architecture & Threat Mitigations

### 2.1 Strict Account Isolation & Prefixing
Every database and database user created by an account must adhere to strict prefix constraints:
- **Database Name**: Must start with `<username>_`, followed by lowercase alphanumeric characters and underscores (`^[a-z0-9_]{1,40}$`). Total length is capped at 64 characters (MariaDB maximum identifier length).
- **Database Username**: Must start with `<username>_`, followed by lowercase alphanumeric characters and underscores (`^[a-z0-9_]{1,16}$`). Total length is capped at 32 characters (MariaDB maximum user length).
- Customers cannot manipulate, view, grant privileges to, or drop databases belonging to other Linux accounts.

### 2.2 Privilege Whitelist & Argument Array Execution
- Privileges are checked against an immutable allow-list of database-level grants. Administrative grants (`SUPER`, `FILE`, `GRANT OPTION`, `SHUTDOWN`) are strictly rejected.
- External commands in the Go Agent run strictly with argument arrays (e.g., `exec.CommandContext(ctx, "mysql", "--defaults-file=/etc/mysql/debian.cnf")`).
- **Zero Cleartext Exposure in Process Tables**: SQL commands containing user credentials or passwords are fed into the MariaDB client via `cmd.Stdin` pipes. Passwords never appear as command-line arguments in `ps aux` or process tables.

### 2.3 Single-Use Signed phpMyAdmin SSO
- Database users can initiate single-sign-on sessions into phpMyAdmin or Adminer.
- The panel issues a cryptographically random, 60-character token with a 60-second time-to-live (`expires_at`).
- Upon first use, the token is permanently marked consumed (`used_at = now()`). Any replay or second access attempt is immediately rejected with HTTP 403 Forbidden.
- The SSO token stores the authorized `database_user_id` and optional `target_db`, preventing customers from pivoting into unauthorized databases.

### 2.4 Backup Path Confinement
- Database dumps initiated via `db.dump` must strictly write inside `/home/<username>/backups/`.
- File paths are normalized with `filepath.Clean` and verified to reside inside the account's backup directory.

---

## 3. Go Agent Typed Actions

| Action | Input Schema | Purpose |
|---|---|---|
| `db.create` | `username`, `db_name`, `charset`, `collate` | Provisions database with UTF-8 character set (`utf8mb4_unicode_ci`). |
| `db.drop` | `username`, `db_name` | Drops database idempotently (`DROP DATABASE IF EXISTS`). |
| `dbuser.create` | `username`, `db_user`, `password`, `remote_host`, `max_user_connections` | Creates database user with connection caps from package. |
| `dbuser.set_password` | `username`, `db_user`, `password`, `remote_host` | Rotates database user password safely via stdin. |
| `dbuser.grant` | `username`, `db_user`, `db_name`, `privileges[]`, `remote_host` | Grants allowed privileges on `<db_name>.*` to user. |
| `dbuser.revoke` | `username`, `db_user`, `db_name`, `remote_host` | Revokes all privileges on `<db_name>.*` from user. |
| `dbuser.drop` | `username`, `db_user`, `remote_host` | Deletes database user idempotently. |
| `db.dump` | `username`, `db_name`, `output_file` | Exports database to `/home/<username>/backups/` using `mysqldump`. |
| `db.import` | `username`, `db_name`, `input_file` | Imports SQL dump file safely into target database. |
| `db.list_usage` | `username` | Queries `information_schema.tables` to calculate storage in bytes per database. |

---

## 4. Failure Recovery & Troubleshooting

### 4.1 MariaDB Connection or Socket Errors
If the agent reports `mariadb query error: connect failed`:
1. Check MariaDB service status: `systemctl status mariadb`.
2. Verify Debian maintenance credentials: `/etc/mysql/debian.cnf` must be readable by root.
3. Verify unix socket availability: `ls -la /run/mysqld/mysqld.sock`.

### 4.2 Database Privileges Out of Sync
If privileges were manually altered or corrupted:
1. Re-run privilege assignment from the panel UI: "Manage Privileges" &rarr; select privileges &rarr; "Apply Privileges".
2. The agent executes `GRANT ... FLUSH PRIVILEGES;` idempotently.

### 4.3 Restoring from an SQL Dump
1. Dumps created by the panel reside in `/home/<username>/backups/<dbname>_<timestamp>.sql`.
2. Restores can be triggered via the agent's `db.import` action or command line:
   ```bash
   mysql --defaults-file=/etc/mysql/debian.cnf <dbname> < /home/<username>/backups/<dumpfile>.sql
   ```

