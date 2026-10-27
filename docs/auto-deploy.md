# Module Documentation: Automated GitHub Deployment & Safe Update Runner

## 1. Overview
HPanel provides an automated, zero-downtime deployment pipeline driven by GitHub push webhooks and automated background polling. The system ensures safe updates with automatic rollback, strict privilege separation, and additive-only database safety.

---

## 2. Architecture & Security Separation

### 2.1 The Web App Never Executes Direct Deployment Commands
To preserve architectural integrity and avoid dangerous privilege escalations:
- The Laravel web application **never** runs `git`, `composer`, `npm`, `go`, or `systemctl`.
- Web requests (webhook or admin panel button) only write an atomic trigger payload to `/var/lib/hpanel/deploy/trigger.json`.
- All privileged operations are executed strictly as `root` via systemd units (`hpanel-deploy.service`) using `flock` concurrency protection.

### 2.2 Webhook Security (`POST /internal/deploy/github`)
- **Signature Verification**: Validates the `X-Hub-Signature-256` header against `DEPLOY_WEBHOOK_SECRET` using HMAC-SHA256 and constant-time string comparison (`hash_equals`). Invalid or missing signatures return HTTP 403 with no explanatory details.
- **Event Filtering**: Only accepts the `push` event. Other events (e.g. `ping`) are safely acknowledged with HTTP 202.
- **Branch Filtering**: Only processes pushes to `refs/heads/main`. Other branches are acknowledged with HTTP 202 without creating triggers.
- **Rate Limiting**: Protected with rate limiting (`throttle:30,1`) outside session and CSRF middlewares.

---

## 3. Systemd Units & Polling Automation

| Unit File | Type | Purpose |
|---|---|---|
| `deploy/systemd/hpanel-deploy.path` | `path` | Watches `/var/lib/hpanel/deploy/trigger.json` via `PathChanged`. When written, activates `hpanel-deploy.service`. |
| `deploy/systemd/hpanel-deploy.service` | `service` (`oneshot`) | Runs `/var/www/hpanel/deploy/update.sh` as `root` with a 10-minute timeout (`TimeoutSec=600`). |
| `deploy/systemd/hpanel-deploy-poll.timer` | `timer` | Triggers every 10 minutes (`OnUnitActiveSec=10min`) to run `hpanel-deploy-poll.service`. |
| `deploy/systemd/hpanel-deploy-poll.service` | `service` (`oneshot`) | Runs `/var/www/hpanel/deploy/poll.sh` to check `git ls-remote origin main`. If a newer commit is found, writes `trigger.json` to cover missed webhooks. |

---

## 4. Safe Update Script Pipeline (`deploy/update.sh`)

The update script runs under `set -Eeuo pipefail` and executes the following sequential steps:

1. **Concurrency Lock**: Takes an exclusive non-blocking lock with `flock -n` on `/var/lock/hpanel-deploy.lock`. If locked, exits 0 cleanly.
2. **Commit Recording**: Records current commit SHA, message, and author.
3. **Release Snapshot**: Creates a rollback snapshot in `/var/lib/hpanel/releases/<sha>/` preserving the commit SHA, commit message, timestamp, and a copy of the active Go agent binary (`/usr/local/bin/panel-agent`).
4. **Git Reset**: Fetches `origin/main` and resets tracked files with `git reset --hard origin/main`. Verifies presence of `composer.json` and `artisan`.
5. **Composer Dependencies**: Runs `composer install --no-dev --optimize-autoloader --no-interaction`.
6. **Database Migrations**: Runs `php8.4 artisan migrate --force`.
7. **Frontend Build**: Runs `npm ci && npm run build` and verifies generation of `public/build/manifest.json`.
8. **Cache Optimization**: Runs `optimize:clear`, `config:cache`, `route:cache`, `view:cache`, and enforces `www-data:www-data` ownership on `storage/` and `bootstrap/cache/`.
9. **Agent Compilation & Test**: Runs `go vet ./...` and `go test ./...` in `agent/`, compiles static binary to `/usr/local/src/panel-agent-build/panel-agent`, and installs to `/usr/local/bin/panel-agent` with mode `0755`.
10. **Service Restart & Health Checks**: Gracefully restarts `php8.4-fpm`, `hpanel-queue`, and `hpanel-agent`. Checks `systemctl is-active` on each service and executes `php8.4 artisan hpanel:agent-check`.
11. **Automatic Rollback on Failure**: If any step after commit recording fails, the `handle_error` ERR trap is triggered. It resets git to the previous commit, reinstalls the previous agent binary from the snapshot, clears caches, restarts services, records status `rolled_back` in `deploy_runs` with the failed step name and last 30 log lines, and removes the trigger file.
12. **Success & Pruning**: On success, records status `success` in `deploy_runs`, writes `/var/lib/hpanel/deploy/current_release.json`, and prunes snapshots older than the last 5 in `/var/lib/hpanel/releases/`.

---

## 5. Rollback & Migration Guarantees

- **Additive Migrations**: Database migrations are strictly additive. Rollback reverts codebase and agent binary but does not roll back database migrations automatically.
- **UI Warnings**: The Updates page (`/admin/updates`) and webhook parser detect if incoming commits contain files in `database/migrations/` and display a prominent warning banner explaining migration non-reversibility.
- **Rollback Snapshots**: Preserves up to 5 releases in `/var/lib/hpanel/releases/` with one-click restoration via the panel interface or `update.sh --rollback --target-sha=<sha>`.

---

## 6. GitHub Webhook Configuration

When configuring the webhook in the GitHub repository (`https://github.com/Stephen-Joseph/nof-hpanel/settings/hooks`):
- **Payload URL**: `https://hpanel.nexorafox.in/internal/deploy/github`
- **Content type**: `application/json`
- **Secret**: Value of `DEPLOY_WEBHOOK_SECRET` in `.env`
- **SSL verification**: Enable SSL verification
- **Which events would you like to trigger this webhook?**: `Just the push event`
