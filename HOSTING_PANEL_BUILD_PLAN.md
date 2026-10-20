# Hosting Panel (cPanel-style) — Build Plan + AI IDE Prompts

Stack: **Laravel (panel + API)** · **Go agent (server-side, restricted)** · **Nginx** · **PHP-FPM (multi-version)** · **MariaDB** · **Certbot** · **OpenSSH-SFTP / Pure-FTPd** · **restic** (backups)

How to use this file:
1. Create a repo, put **Part 2** into `AGENTS.md` (Cursor: `.cursor/rules`, Antigravity/Gemini: `GEMINI.md`, Claude Code: `CLAUDE.md`, Copilot: `.github/copilot-instructions.md`).
2. Paste **one phase prompt at a time** from Part 3. Do not move to the next phase until tests pass and you have reviewed the diff.
3. Test every phase on a throwaway VPS (Ubuntu 24.04 / Debian 12), never on a production server.

---

## Part 1 — Architecture

```
Customer browser
      │ HTTPS
Hosting dashboard  (Laravel: UI, auth, RBAC, API, queue, DB)
      │  signed jobs (mTLS + HMAC, over WireGuard/private network)
Restricted server agent (Go, runs as root but ONLY exposes typed, validated actions)
      │
 ┌────┴─────┬───────────┬──────────┬──────────┐
Nginx     PHP-FPM    MariaDB    Certbot    SFTP/FTP, cron, restic
vhosts    pools      users/DBs  SSL        per-account Linux user
```

### Golden rules (the whole design depends on these)
1. **The Laravel app never touches the server shell.** It only creates "jobs". Only the agent changes the system.
2. **The agent has no generic "run command" endpoint.** Only a fixed list of actions (`account.create`, `vhost.write`, `ssl.issue`, ...). Each action has a strict JSON schema; every field is validated with an allow-list regex; commands run with argument arrays (never shell string concatenation).
3. **One Linux user per hosting account.** Each account gets its own home dir, its own PHP-FPM pool (runs as that user), its own SFTP chroot. This is what keeps customers isolated.
4. **Nginx configs are generated from templates** from structured data, written to a temp file, checked with `nginx -t`, then reloaded. If the test fails, roll back automatically.
5. **Every action is idempotent and audited** (running `account.create` twice must not break anything).
6. **Limits are enforced by the OS, not just shown in the UI**: XFS/ext4 project quotas (storage + inodes), PHP-FPM `pm.max_children` (PHP workers), systemd slices/cgroups (CPU/RAM), MariaDB `MAX_USER_CONNECTIONS`.

### Nginx notes (no `.htaccess`)
- Nginx ignores `.htaccess`. Rewrites/redirects/password-protection must be translated into generated Nginx config. Provide vhost "profiles": `php-generic`, `laravel` (root = `public/`, `try_files $uri $uri/ /index.php?$query_string`), `wordpress`, `static-spa` (React/Vue build, fallback to `index.html`), `node-proxy` (later).
- Customers must never edit raw Nginx. They edit structured settings (redirects, protected dirs, PHP version, document root); the agent renders the config.

### Data model (tables, outline)
`users` (role: admin/reseller/customer, `parent_id` for reseller tree, 2FA) · `servers` · `packages` (limits) · `accounts` · `domains` · `subdomains` · `redirects` · `ssl_certificates` · `php_settings` · `databases` · `database_users` · `ftp_accounts` · `ssh_keys` · `cron_jobs` · `backup_schedules` · `backups` · `git_deployments` · `protected_directories` · `api_clients` · `api_tokens` · `jobs` · `job_logs` · `usage_snapshots` · `audit_logs` · `webhook_endpoints` · `webhook_deliveries`

### Package limits
Storage (MB), inodes, bandwidth (GB/month), websites, subdomains, databases, DB users, FTP/SFTP accounts, cron jobs, email-less for now, PHP workers (`max_children`), PHP memory_limit, allowed PHP versions, backups retained, SSH access (bool), Git deploy (bool).

### Phase roadmap (build in this order)
| # | Phase | Result |
|---|---|---|
| 0 | Repo + conventions | Monorepo, Docker dev env, CI |
| 1 | Auth, RBAC, tenancy, audit | Admin / reseller / customer login |
| 2 | Packages + accounts (DB only) | Data model + admin UI |
| 3 | **Agent** + job protocol | Secure panel↔server channel |
| 4 | Account provisioning | Create/suspend/unsuspend/upgrade/terminate for real |
| 5 | Billing API v1 | The endpoints you listed + tokens + webhooks |
| 6 | Domains, subdomains, redirects, Nginx | Sites go live |
| 7 | SSL (Certbot) | Auto HTTPS + renewal |
| 8 | PHP versions + workers | Per-site PHP version, pools, limits |
| 9 | File manager, SFTP/FTP, SSH keys | Access to files |
| 10 | Databases | MariaDB DBs/users + phpMyAdmin-style SSO |
| 11 | Cron editor + logs | Safe cron, error/access log viewer |
| 12 | Backups | Schedule, restic, restore |
| 13 | Git deploy, WordPress, protected dirs | One-click apps |
| 14 | Usage/limits metering | Disk, inodes, bandwidth, enforcement |
| 15 | Resellers + multi-server | Scheduler, node registration |
| 16 | UI polish + theme | Final design system pass |
| 17 | Hardening, tests, installer | Production readiness |

---

## Part 2 — Master rules file (`AGENTS.md`)

```markdown
# Project: <CompanyName> Hosting Panel

You are a senior engineer building a cPanel-style web hosting control panel.

## Stack
- Panel: Laravel (latest stable), PHP 8.3+, MariaDB, Redis (queues/cache), Blade + Livewire (or Inertia+React — pick once in Phase 0 and never mix), Tailwind.
- Agent: Go (single static binary), systemd service, lives in /agent.
- Target servers: Ubuntu 24.04 / Debian 12 with Nginx, PHP-FPM (multiple versions via Sury/Ondrej repo), MariaDB, Certbot, OpenSSH, restic.
- Repo layout: /panel (Laravel), /agent (Go), /deploy (installer scripts, templates), /docs.

## Non-negotiable security rules
1. Laravel NEVER executes shell commands for provisioning. It only enqueues jobs for the agent.
2. The agent exposes ONLY typed actions defined in /agent/internal/actions. No generic exec. Reject unknown actions.
3. Every action input is validated against a strict schema + allow-list regexes (usernames `^[a-z][a-z0-9]{2,15}$`, domains RFC-valid lowercase, paths must resolve inside the account home — resolve symlinks and reject traversal).
4. Run external commands with exec arg arrays only. Never `sh -c` with user data.
5. Panel↔agent: mTLS + HMAC-SHA256 request signature + timestamp + nonce (replay protection). Agent listens only on a private/WireGuard interface.
6. Nginx/PHP-FPM/cron configs are rendered from templates, validated (`nginx -t`, `php-fpm -t`), applied atomically, rolled back on failure.
7. Each hosting account = its own Linux user + PHP-FPM pool + SFTP chroot. Customers can never read other accounts' files.
8. All state-changing actions are idempotent and write an audit log row.
9. Secrets (DB passwords, API tokens) are hashed or encrypted at rest; never logged.
10. Every permission check uses Laravel Policies; resellers can only see their own subtree.

## Engineering rules
- Write tests with each feature (Pest for Laravel, `go test` for agent). A phase is not done until tests pass.
- Use migrations + factories + seeders. Use Form Requests for validation. Use Jobs/Queues for anything slow.
- API: versioned `/api/v1`, JSON errors `{ "error": { "code", "message", "details" } }`, pagination, idempotency keys on POST.
- Keep controllers thin; business logic in Actions/Services.
- Provide a `docs/` markdown file for every module (what it does, config, failure modes).
- After each task: list files changed, how to run tests, and what is still TODO. Never claim something works if it was not run.
- Ask before adding any new dependency.
```

---

## Part 3 — Phase prompts (paste one at a time)

### Phase 0 — Foundations
```
Read AGENTS.md. Set up the monorepo: /panel (new Laravel project), /agent (Go module), /deploy, /docs.
- Add Docker Compose for local dev (MariaDB, Redis, Mailpit) for the panel.
- Choose and configure the frontend stack (Livewire+Blade+Tailwind) and record the decision in docs/decisions.md.
- Add Pest, Laravel Pint, PHPStan (level 6), and GitHub Actions CI running tests + lint for both panel and agent.
- Add a Makefile with: make up, make test, make lint.
Do not build features yet. At the end, show the tree and how to run everything.
```

### Phase 1 — Auth, roles, tenancy, audit
```
Implement authentication and authorization in /panel:
- Roles: admin, reseller, customer. Users table has role, parent_id (reseller owner), status, 2FA (TOTP) with recovery codes.
- Login, logout, forgot/reset password, email verification, session management (list/revoke sessions), login throttling.
- Policies so a reseller only sees users/accounts in their own subtree; admin sees all; customer sees only their own.
- audit_logs table + a service to record actor, action, target, IP, before/after JSON. Admin UI to browse/filter logs.
- Seeder creating one admin.
- Pest tests covering every policy (try cross-tenant access and assert 403/404).
```

### Phase 2 — Packages and accounts (database layer + admin UI)
```
Create packages and accounts in /panel (no server actions yet):
- packages: name, storage_mb, inodes, bandwidth_gb, max_websites, max_subdomains, max_databases, max_db_users, max_ftp_accounts, max_cron_jobs, php_workers, php_memory_mb, allowed_php_versions (json), backups_retained, ssh_enabled, git_enabled, reseller_owner_id (nullable, resellers can make own packages).
- servers: name, hostname, private_ip, agent_url, status, capacity fields, agent public cert fingerprint.
- accounts: user_id, package_id, server_id, linux_username (unique), primary_domain, status (pending, active, suspended, terminating, terminated, failed), suspended_reason, timestamps.
- Admin/reseller UI: list/create/edit packages, list/create/view accounts with status badges. Creating an account only creates a DB row with status=pending and dispatches a ProvisionAccount job stub.
- Add model factories, policies, tests.
```

### Phase 3 — The agent and the job protocol (most important phase)
```
Build the server agent in /agent (Go) and the panel-side client.
Agent:
- HTTPS server with mTLS (client cert required), listens on a configurable private address.
- Every request: JSON {action, request_id, timestamp, nonce, payload, signature}. Verify HMAC-SHA256, reject timestamps older than 60s, reject reused nonces (store in memory + disk ring buffer).
- Action registry pattern: each action implements Validate(payload) and Execute(ctx, payload) returning structured result + log lines. Unknown action = reject.
- Implement only these now: system.ping, system.health (CPU, RAM, disk, load, nginx/php-fpm/mariadb service status), and a dry-run mode flag for every action.
- Structured JSON logging to /var/log/panel-agent/, systemd unit file in /deploy, config file /etc/panel-agent/config.yaml.
Panel:
- jobs + job_logs tables; a AgentClient service (signing, retries with backoff, timeouts); Laravel queue job that sends an action and stores result; job status UI for admins.
- Tests: signature tampering, replay, expired timestamp, unknown action, invalid payload.
Also write docs/agent-security.md describing the threat model.
```

### Phase 4 — Account provisioning
```
Add real account actions to the agent and wire them to the panel (ProvisionAccount, SuspendAccount, UnsuspendAccount, UpgradeAccount, TerminateAccount):
Agent actions:
- account.create: create Linux user (no shell login by default, home /home/<user>), directory layout (public_html, logs, tmp, backups, .ssh), set project quota for storage+inodes, create a PHP-FPM pool file from template (user/group, socket path, pm.max_children from package, memory_limit, open_basedir, disable_functions, session/tmp dirs), create systemd slice limits for CPU/RAM.
- account.suspend: replace all vhosts with a "suspended" page vhost, stop PHP-FPM pool, disable cron + SFTP login, keep data.
- account.unsuspend: reverse the above.
- account.upgrade: re-apply quota, FPM pool, slice limits, allowed PHP versions.
- account.terminate: require a confirm token, take a final backup first (configurable), then remove vhosts, pool, cron, DB users, Linux user, files.
All actions idempotent with rollback on partial failure. Panel side: status transitions with a state machine, failure -> status=failed + retry button + job log view. Write tests for the state machine and Go tests for template rendering and username validation. Test on a real throwaway VM and document the manual test steps.
```

### Phase 5 — Billing API v1
```
Implement the billing/provisioning API in /panel under /api/v1 (works with WHMCS/custom billing):
Endpoints:
POST /api/v1/accounts            create hosting account (username, domain, package, customer email, server optional)
GET /api/v1/accounts/{id}        account details + status + package + limits
POST /api/v1/accounts/{id}/suspend    (reason)
POST /api/v1/accounts/{id}/unsuspend
POST /api/v1/accounts/{id}/upgrade    (package_id)
DELETE /api/v1/accounts/{id}          terminate
POST /api/v1/domains             add domain to account
POST /api/v1/ssl/issue           request certificate for a domain
GET /api/v1/accounts/{id}/usage  storage, inodes, bandwidth, databases, websites vs limits
GET /api/v1/health               panel + per-server agent health
Also add: GET /api/v1/packages, POST /api/v1/accounts/{id}/password, POST /api/v1/accounts/{id}/sso (one-time login URL).
Requirements:
- api_clients + api_tokens (hashed, scopes, IP allow-list, last_used_at, revoke). Bearer auth. Admin UI to create/revoke tokens and view request logs.
- Idempotency-Key header on all POSTs; same key returns the same response.
- Provisioning is async: return 202 with account id + job id; client polls GET or receives webhooks.
- Webhooks: account.created, account.suspended, account.unsuspended, account.upgraded, account.terminated, ssl.issued, ssl.failed, signed with HMAC, retries with exponential backoff, delivery log UI.
- Rate limiting, consistent JSON errors, OpenAPI 3.1 file at /docs/openapi.yaml, Pest feature tests for every endpoint including auth failures and idempotency replay.
```

### Phase 6 — Domains, subdomains, redirects, Nginx
```
Implement domain management end to end.
Panel UI (customer area): Domains page (add domain/addon domain, set document root, choose app profile: php-generic, laravel, wordpress, static-spa), Subdomains page, Redirects page (301/302, domain-level or path-level, www<->non-www, force HTTPS).
Agent actions: vhost.write, vhost.remove, vhost.reload.
- Render Nginx server blocks from templates per profile. Redirects are rendered as `return` rules/maps from structured data only; never accept raw Nginx text from users.
- Write config to temp, run `nginx -t`, atomically move, reload; on failure restore the previous file and return the error.
- Document root must be inside the account home; reject symlink escapes.
- Enforce package limits (max websites/subdomains) in the panel AND validate in the agent.
- DNS helper page: show required A/AAAA/CNAME records and a "check DNS" button.
- Tests: template golden files for each profile, invalid input fuzz tests in Go.
```

### Phase 7 — SSL
```
Implement SSL with Certbot (webroot or acme.sh-compatible abstraction):
- Agent actions: ssl.issue (HTTP-01 via a shared /.well-known/acme-challenge location in every vhost), ssl.renew, ssl.revoke, ssl.status.
- Auto-issue after a domain's DNS resolves to the server; retry with backoff; show clear errors (DNS not pointing, rate limited).
- Panel: SSL page per domain (status, expiry, issuer), "force HTTPS", upload custom certificate (validate key/cert match and chain), Let's Encrypt wildcard via DNS-01 as an optional later module.
- A scheduled job checks expiry and renews 30 days before; webhook ssl.issued / ssl.failed.
- Nginx templates updated with modern TLS config, HTTP/2, HSTS toggle.
```

### Phase 8 — PHP versions and workers
```
Implement multi PHP version support:
- Agent: php.list_versions, php.install_version (only from an allow-list of versions installed via the Sury repo), per-domain PHP version switch (re-point fastcgi_pass socket), per-account FPM pool for each version in use.
- Panel: PHP Manager page — pick version per domain, edit allowed ini options from a safe allow-list (memory_limit, upload_max_filesize, post_max_size, max_execution_time, display_errors, extension toggles from a safe list), view PHP error log.
- PHP workers: package-defined pm.max_children, with pm settings (ondemand/dynamic) generated by the agent; show current worker usage from FPM status.
- Validate with `php-fpm<ver> -t` before reload; roll back on error. Tests for pool template rendering.
```

### Phase 9 — Files, SFTP/FTP, SSH keys
```
Implement access features:
- File manager (web): browse, upload, download, edit text files, create/rename/delete, zip/unzip, permissions. All operations go through agent actions restricted to the account home with realpath checks.
- SFTP: use OpenSSH internal-sftp with ChrootDirectory per account via a Match Group block managed by the agent. FTP (optional, off by default): Pure-FTPd with virtual users mapped to the account user, FTPS only.
- Panel: FTP/SFTP accounts page (sub-accounts restricted to a subfolder, password reset, enable/disable), limits from package.
- SSH key management: add/remove public keys (validate format and key type, reject weak keys), written to the account's authorized_keys by the agent; shell access only if package.ssh_enabled (use a restricted shell/jail).
- Tests for path traversal and symlink attacks.
```

### Phase 10 — Databases
```
Implement MariaDB management:
- Agent actions: db.create (names prefixed with <user>_), db.drop, dbuser.create, dbuser.set_password, dbuser.grant (per-database privileges), dbuser.revoke, dbuser.drop, with MAX_USER_CONNECTIONS from package.
- Panel: Databases page (create DB, create user, assign privileges, change password, remote access allow-list optional), import/export (SQL dump via agent to account backups folder), usage per DB.
- Enforce max_databases/max_db_users.
- One-click login to a bundled phpMyAdmin (or Adminer) using a short-lived signed token (single use), served on a separate hostname.
- Passwords generated strongly and shown once.
```

### Phase 11 — Cron editor and logs
```
Implement:
- Cron: structured editor (minute/hour/day/month/weekday pickers + presets), command field, per-job output email/none, enable/disable. Agent writes to the account user's crontab (or systemd timers) after validating the schedule; cap job count by package; enforce minimum interval (e.g. 1 min) and run under the account's cgroup limits.
- Logs: error/access log viewer for each domain (tail last N lines, search, download, rotate). Agent action reads only from /home/<user>/logs and the domain's log files, with size caps and streaming. Configure logrotate per account.
- PHP error log and cron output log pages.
```

### Phase 12 — Backups
```
Implement backups with restic (encrypted, deduplicated):
- Agent actions: backup.create (files + all account DBs via mysqldump --single-transaction), backup.list, backup.restore (full account / files only / one DB / one path), backup.delete, backup.verify.
- Panel: Backups page — manual backup, schedule (daily/weekly/monthly + time), retention from package, restore wizard with a clear "this overwrites X" confirmation, download a backup archive.
- Scheduler runs via Laravel scheduler -> jobs; track size and duration; webhook backup.completed/backup.failed.
- Store repos locally and support an off-server target (S3-compatible / SFTP) configured by admin per server.
- Tests for restore into a temporary directory.
```

### Phase 13 — Git deploy, WordPress, protected dirs
```
Implement:
1. Git deployment: customer adds a repo URL; panel generates a deploy key (shown as public key); agent clones/pulls into a chosen directory as the account user; branch selection; optional deploy script from a safe list (composer install --no-dev, npm ci && npm run build, php artisan migrate --force) running under the account's limits with timeout; webhook URL (with secret) for GitHub/GitLab push events; deployment history with logs and rollback to previous release (releases/ + current symlink).
2. WordPress installer: agent uses WP-CLI as the account user: create DB + user, download core, configure wp-config with salts, install with admin credentials, apply the wordpress Nginx profile. Include update/uninstall and a "staging copy" option later.
3. Password-protected directories: structured UI (path + users); agent generates htpasswd files outside the web root and the Nginx `auth_basic` location blocks from structured data.
Add tests and docs for each.
```

### Phase 14 — Usage metering and limit enforcement
```
Implement usage tracking:
- Agent action usage.collect: storage (quota report), inodes, bandwidth (parse Nginx access logs per domain with offset tracking, or use a log format with bytes_sent aggregated), DB sizes, FPM worker stats.
- Scheduler pulls every 15 min into usage_snapshots; monthly bandwidth reset.
- Panel: usage dashboard with charts per account, warnings at 80/90/100%, admin overview.
- Enforcement policies (configurable): over storage/inode -> block uploads via quota; over bandwidth -> suspend or throttle; send email + webhook usage.limit_reached.
- Feed GET /api/v1/accounts/{id}/usage from this data.
```

### Phase 15 — Resellers and multi-server
```
Implement:
- Resellers: reseller dashboard, own packages (within admin-granted resource pool), create/suspend/terminate their customers, white-label (logo, name, custom panel domain), reseller limits (total accounts, total storage).
- Multi-server: server registration flow (admin generates a one-time token; installer script on the node enrolls the agent, exchanges certificates), server groups, a scheduler that picks a server by capacity/package/location, health monitoring with status badges, "migrate account to another server" job (backup -> restore -> switch DNS notice).
- Admin can disable a server for new accounts (drain mode).
- Tests for tenant isolation (reseller A vs reseller B) and scheduler selection.
```

### Phase 16 — UI/theme pass
```
Create a consistent design system for the panel: tokens (colors, spacing, radius, shadows) as Tailwind config + CSS variables, light/dark mode, responsive sidebar layout, reusable components (data table with filters/pagination, modal, toast, form fields, status badge, empty state, confirm-danger dialog, loading skeleton, code/log viewer).
Pages to restyle: dashboard, accounts, domains, SSL, PHP manager, files, databases, cron, backups, Git, usage, admin servers, API tokens, audit logs.
Add a customer dashboard with quick tiles (disk, bandwidth, inodes, sites, databases) and a global search. Keep accessibility (labels, focus states, keyboard navigation) and make all text translatable.
```

### Phase 17 — Hardening, tests, installer
```
Production readiness:
- Security review of every agent action against AGENTS.md rules; add fuzz tests for all validators; add a threat-model update.
- Rate limits, CSRF, CSP headers, secure cookies, brute-force protection, optional IP allow-list for admin.
- Installer script /deploy/install-node.sh: installs Nginx, PHP versions, MariaDB, Certbot, restic, quotas, firewall rules (ufw: only 22/80/443 public, agent port private), fail2ban, agent binary + systemd unit, initial config. Idempotent, with a --dry-run.
- /deploy/install-panel.sh for the panel host. Backup of the panel DB itself.
- Monitoring: Prometheus-style metrics from the agent, alerting hooks, log shipping.
- End-to-end test checklist document: create account -> add domain -> issue SSL -> deploy Laravel via Git -> create DB -> cron -> backup -> restore -> suspend -> unsuspend -> upgrade -> terminate.
```

---

## Part 4 — Review checklist (use after every phase)

- [ ] Does any Laravel code call `exec`, `shell_exec`, `Process`, or `proc_open` for provisioning? (Must be NO)
- [ ] Does the agent have any generic command action? (Must be NO)
- [ ] Is every user-supplied value validated by allow-list regex in both panel and agent?
- [ ] Are paths resolved with `realpath` and checked to stay inside `/home/<user>`?
- [ ] Are configs written atomically, tested (`nginx -t`, `php-fpm -t`), and rolled back on failure?
- [ ] Is there an audit log row for the action?
- [ ] Is the action idempotent (safe to retry)?
- [ ] Are there tests (including an attack test) and a doc page?
- [ ] Tested on a real throwaway VM, not just unit tests?

## Part 5 — Practical advice

- **Build the agent early and keep it small.** It is the part that can compromise the whole server, so review it by hand, not just with AI.
- **Do not skip per-account Linux users and PHP-FPM pools.** Shared-user hosting is how panels get breached.
- **Start with PHP/Laravel/WordPress/static React.** Node.js apps (proxy + process manager) can be a later module.
- **Email hosting, DNS zones and WAF** are separate big modules — add after the core is stable (you can use Cloudflare DNS via API first).
- **Cloudflare/Fail2ban/ufw** in front of everything; keep the agent port off the public internet.
- Consider a pilot with a small number of real customers on one server before multi-server work.
