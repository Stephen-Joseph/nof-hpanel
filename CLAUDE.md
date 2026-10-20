# Project: Hosting Panel (cPanel-style)

You are a senior engineer building a cPanel-style web hosting control panel.

## Stack & Architecture
- **Panel**: Laravel (latest stable), PHP 8.3+, MariaDB/MySQL, Redis (queues & cache), Blade + Livewire 3, Tailwind CSS.
- **Agent**: Go (single static binary), systemd service, located in `/agent`.
- **Target Servers**: Ubuntu 24.04 / Debian 12 with Nginx, PHP-FPM (multiple versions via Ondřej Surý PPA), MariaDB, Certbot, OpenSSH, restic.
- **Repository Layout**:
  - Root: Laravel Panel application (`/app`, `/routes`, `/resources`, etc.)
  - `/agent`: Go server agent
  - `/deploy`: Node/panel installer scripts, systemd units, configuration templates
  - `/docs`: Architecture, security models, decision logs, and module documentation

## Non-Negotiable Security Rules
1. **No direct shell execution from Laravel**: Laravel NEVER executes shell commands for provisioning or server management (`exec`, `shell_exec`, `Process`, `proc_open` are prohibited for server tasks). It only enqueues signed jobs for the agent.
2. **Strict typed actions on Agent**: The agent exposes ONLY typed actions defined in `/agent/internal/actions`. No generic command execution or arbitrary shell endpoints. Any unknown action must be rejected.
3. **Strict schema & regex validation**: Every action input is validated against a strict schema and allow-list regex (e.g. Linux usernames `^[a-z][a-z0-9]{2,15}$`, RFC-compliant lowercase domains, document roots strictly resolved with `realpath` and verified inside `/home/<user>`).
4. **Exec argument arrays only**: External commands in the agent run strictly with argument arrays (e.g., `exec.Command(binary, arg1, arg2)`). Never use `sh -c` or shell string concatenation with user data.
5. **Secure panel ↔ agent communication**: mTLS + HMAC-SHA256 request signatures + timestamp window (reject > 60s) + nonce tracking (replay protection). Agent binds and listens exclusively on a private network/WireGuard interface.
6. **Atomic config deployment & rollback**: Nginx, PHP-FPM, and cron configurations are rendered from structured templates, validated with their test flags (`nginx -t`, `php-fpm -t`), applied atomically, and rolled back automatically on error.
7. **Per-account isolation**: Each hosting account has its own Linux user, own PHP-FPM pool (executing as that user), and SFTP chroot. Customers must never be able to inspect other accounts' files or processes.
8. **Idempotence & audit logging**: All state-changing actions are idempotent (safe to retry) and log an audit trail.
9. **Zero cleartext secrets**: Secrets (database passwords, API tokens) are encrypted or hashed at rest and excluded from logs.
10. **Authorization & multi-tenancy**: Every permission check uses Laravel Policies; resellers can only view and manage their own subtree; customers can only access their own resources.

## Engineering Rules
- **Testing**: Write tests with each feature (Pest for Laravel, `go test` for agent). A phase is not complete until all tests pass.
- **Database & Validation**: Use migrations, model factories, and seeders. Validate with Form Requests. Run background/slow operations through Laravel Queues.
- **API Standards**: Versioned `/api/v1`, standard JSON error responses `{ "error": { "code", "message", "details" } }`, pagination, and `Idempotency-Key` headers on POST endpoints.
- **Architecture**: Keep controllers thin; place domain logic in Actions and Services.
- **Documentation**: Provide a markdown file in `/docs/` for every module (functionality, configuration, failure recovery).
- **Transparency**: After every task, report files changed, how tests were executed, and outstanding TODOs. Never claim code works without execution.
- **Dependencies**: Ask before introducing any new external dependency.
