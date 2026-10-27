# Module Documentation: Domains, Subdomains, Redirects & Nginx Virtual Hosts

## 1. Overview
The Domains and Virtual Hosts module provides complete multi-domain hosting management for accounts on HPanel. It allows customers, resellers, and administrators to:
- Manage Primary, Addon, and Subdomain web hosts.
- Configure profile-specific web server configurations (`php-generic`, `laravel`, `wordpress`, `static-spa`).
- Enforce canonical redirects (e.g., `example.com` &rarr; `www.example.com` or vice versa) and HTTPS enforcement.
- Manage custom URL redirect rules (301 Permanent / 302 Temporary) without editing Nginx server files directly.
- Inspect and verify live DNS A and CNAME record propagation via an interactive DNS helper.

---

## 2. Security Architecture & Path Isolation

### 2.1 No Direct Shell Execution
In adherence to core security rules, Laravel never invokes `exec`, `shell_exec`, or `Process` directly. All virtual host operations are dispatched via Laravel queued jobs (`WriteVhostJob`, `RemoveVhostJob`) over signed, encrypted mTLS + HMAC-SHA256 connections to the Go Agent.

### 2.2 Strict Path Isolation & Traversal Guards
- Every document root is validated to ensure it resides strictly within `/home/<username>/`.
- Any path containing `..`, null bytes (`\0`), or symlink escapes outside the user's home directory is rejected at both the Laravel validation layer and the Go Agent layer.
- Document roots for subdomains default to `/home/<username>/subdomains/<subdomain>`, while addon domains default to `/home/<username>/public_html/<domain>`.

### 2.3 Strict Argument Arrays & Sanitization
All agent executions invoke external commands strictly with argument arrays (e.g. `exec.Command("nginx", "-t")`), completely preventing shell injection attacks.

---

## 3. Go Agent Virtual Host Actions

| Action | Input Schema | Purpose |
|---|---|---|
| `vhost.write` | `username`, `domain`, `document_root`, `app_profile`, `php_version`, `force_https`, `www_redirect`, `redirects[]` | Renders template, tests config, atomically moves to `sites-available`, creates symlink, reloads Nginx. |
| `vhost.remove` | `username`, `domain` | Unlinks from `sites-enabled`, removes `/etc/nginx/sites-available/<domain>.conf`, reloads Nginx. |
| `vhost.reload` | _None_ | Validates `nginx -t` and reloads `systemctl reload nginx`. |
| `nginx.config.list` | _None_ | Lists virtual hosts in `sites-available` and `sites-enabled` with enabled state, owner, size, and mtime. |
| `nginx.config.read` | `name` | Reads file content up to 256 KB. Access to `nginx.conf` and path traversal are rejected. |
| `nginx.config.write` | `name`, `content`, `admin_confirmed` | Backs up file, writes atomically to temp file, validates with `nginx -t`. Rolls back on failure, reloads on success. Keeps last 20 backups. |
| `nginx.config.enable` | `name` | Creates symlink in `sites-enabled/`, validates with `nginx -t`, rolls back on failure, reloads on success. |
| `nginx.config.disable` | `name` | Removes symlink in `sites-enabled/`, validates with `nginx -t`, rolls back on failure, reloads on success. Typed confirmation for panel vhost. |
| `nginx.config.backups` | `name` | Lists up to 20 timestamped revision backups stored in `/var/lib/panel-agent/config-backups/`. |
| `nginx.config.restore` | `name`, `backup_id` | Backs up current file, restores selected backup, validates with `nginx -t`, rolls back on failure, reloads on success. |
| `nginx.config.test` | _None_ | Read-only syntax check (`nginx -t`) returning console output and validation status. |
| `nginx.custom.read` | `domain` | Reads `/etc/nginx/hpanel.d/<domain>.custom.conf`. |
| `nginx.custom.write` | `domain`, `settings` | Validates settings against injection, renders custom include, backs up, verifies via `nginx -t`, rolls back on failure, reloads on success. |

---

## 4. Web Server Config Manager (Admin Full Edit & Customer Structured Edit)

### 4.1 Admin Full Configuration Management (`/admin/web-server`)
Administrators have access to full virtual host file editing across the node:
- **File Browser**: Lists virtual hosts across `sites-available` and `sites-enabled` with enabled chips, owner account, file size, and last modified date.
- **Monospace Code Editor**: In-browser editor with `nginx -t` syntax testing on save.
- **Panel Vhost Safety Guard**: Edits to the panel's own virtual host (`PANEL_DOMAIN`) require an explicit warning confirmation modal. Disabling the panel's own virtual host requires a typed confirmation prompt matching the exact domain name to prevent accidental lockout.
- **Automatic Syntax Rollback**: Every write or enable/disable operation runs `nginx -t`. If syntax validation fails, the file is automatically rolled back to its previous state and the exact syntax error is returned to the user without reloading Nginx.
- **Revision History & Restore**: Every save automatically copies the previous configuration to `/var/lib/panel-agent/config-backups/<name>.<timestamp>`. The last 20 revisions are retained and can be restored with a single click.

### 4.2 Customer Structured Configuration (`/domains/{domain}/web-server`)
Customers, resellers, and administrators can customize domain directives without writing raw Nginx syntax:
- **Directives & Execution**:
  - Max upload body size: 1 MB to 128 MB (`client_max_body_size`).
  - Static asset cache lifetime: None, 1 day, 7 days, 30 days (`expires` with `Cache-Control: "public, no-transform"`).
  - Clean URLs: toggle on/off (uses profile `.php` or front-controller fallback).
  - Force HTTPS: toggle on/off (redirects non-TLS requests to HTTPS).
  - Default index files: allow-list selection of `index.php`, `index.html`, `index.htm`.
- **Custom Error Pages**: Map 404 and 403 errors to custom files inside `public_html`.
- **Custom URL Redirects**: Up to 20 redirects with 301/302 status codes, strict redirect loop detection, and path validation.
- **Generated Custom Include**: Directives are compiled into `/etc/nginx/hpanel.d/<domain>.custom.conf`, which is included directly inside the domain's virtual host block.
- **Audit Logging**: All custom writes and config updates are recorded in `audit_logs` without storing raw configuration contents.

---

## 5. Atomic Staging, Linking & Rollback Strategy

To ensure zero downtime and prevent misconfigured virtual hosts from breaking running services:
1. **Default Server Catch-All (`00-default.conf`)**:
   The agent automatically installs and links a hardened default server block on ports 80 and 443 (`server_name _; return 444;` with `ssl_reject_handshake on;` on port 443). Any unmapped domain, direct IP probing, or SNI mismatch is immediately dropped without responding.
2. **Canonical Server Names & Root Isolation**:
   Every account vhost automatically configures `server_name` for both the primary domain and `www` (e.g. `example.com www.example.com`), while ensuring the document root strictly resides within the account's home directory (`/home/<username>/...`).
3. **Atomic Placement & Symlinking**:
   Configurations are rendered to a destination-local temporary file (`.domain.conf.tmp-*`), fsynced, permissions set to `0640`, and atomically moved to `/etc/nginx/sites-available/<domain>.conf`. The file is then linked into `/etc/nginx/sites-enabled/<domain>.conf`.
4. **Post-Link Syntax Verification**:
   The agent invokes `nginx -t` **only after** the file is linked into `sites-enabled/`. This guarantees that Nginx tests the complete, active configuration graph. If validation fails, the newly created symlink and available configuration are immediately unlinked and removed (or previous configuration restored from backup), and the test output is captured without reloading.
5. **Graceful Reload**:
   Only after `nginx -t` succeeds with the file linked in `sites-enabled/`, `systemctl reload nginx` is executed. If reload fails, changes are cleanly rolled back.

---

## 5. Application Profiles

Customers can select the application profile for any domain in **Domains** (via the inline Profile button or modal, or in the PHP Manager):
- `php-generic`: Standard PHP web application with clean URLs and `.php` script fallback (`try_files $uri $uri/ $uri.php$is_args$args;`), fastcgi buffering, and socket routing to `/run/php/php<version>-fpm-<username>.sock`.
- `laravel`: Enforces document root `/public`, blocks `.env` and dotfile access, passes non-file requests to the front controller (`try_files $uri $uri/ /index.php?$query_string;`).
- `wordpress`: Standard WordPress routing rules (`try_files $uri $uri/ /index.php?$args;`), XML-RPC hardening, blocks direct access to `wp-config.php` and uploaded PHP files in `wp-content/uploads/`.
- `static-spa`: Single Page Application or static site with `try_files $uri $uri/ /index.html` fallback, cache-control headers, and gzip compression.

---

## 6. Failure Recovery

### Virtual Host Validation Error
1. Check Agent Job logs in Admin &rarr; Agent Jobs to inspect the exact `nginx -t` error message.
2. Verify PHP-FPM pool socket exists in `/run/php/php<version>-fpm-<username>.sock`.
3. If an invalid custom redirect caused a syntax conflict, edit or delete the redirect from the panel: the agent will re-render and reload the configuration automatically.

### DNS Verification Issues
1. Verify nameserver delegation at your domain registrar.
2. Ensure the A record points to the server IP displayed in the DNS Helper view.
3. If using Cloudflare or a reverse-proxy CDN, ensure SSL mode is set to "Full" or "Full (Strict)" to prevent redirect loops.

---

## 7. .htaccess Compatibility Layer

### 7.1 Purpose & Execution Model
Nginx does not natively read `.htaccess` files. HPanel solves this transparently for customers migrating from Apache or cPanel:
1. **Parser Location**: The parser and converter run in the Go agent (`htaccess.sync`). This allows direct atomic filesystem operations on `/home/<user>/public_html/.htaccess`, validation of the generated `/etc/nginx/hpanel.d/<domain>.htaccess.conf` with `nginx -t`, and automatic rollback in a single atomic transaction.
2. **Virtual Host Include**: Virtual hosts include the configuration via optional glob inclusion: `include /etc/nginx/hpanel.d/<domain>.htaccess.con[f];`. If the file exists, it is loaded; if deleted or absent, Nginx starts cleanly without errors.
3. **Hardened Blocking**: The `.htaccess` file itself is never served directly to the web; it remains blocked by Nginx's hidden dotfile location block (`location ~ /\.(?!well-known).* { deny all; }`).
4. **No Raw Directives Passed**: The converter parses lines into typed directive models and renders structured Nginx blocks. Unsupported or malicious directives are never passed to Nginx verbatim.

### 7.2 Supported Directives
- `RewriteEngine`, `RewriteCond`, `RewriteRule`: Supports standard front-controller patterns (`try_files`), HTTPS enforcement, host canonicalization, and regular rewrites with flags `L`, `R=301`, `R=302`, `F`, `G`, `NC`, `QSA`, and `NE`. Includes circular loop protection.
- `Redirect`, `RedirectMatch`: Permanent (301), temporary (302), see other (303), temporary redirect (307), permanent redirect (308), and gone (410).
- `ErrorDocument`: Custom error paths or external URLs for status codes 400 through 504.
- `DirectoryIndex`: Allow-listed index file lists.
- `Header`: Allow-listed security and cache headers (`X-Frame-Options`, `Content-Security-Policy`, `Cache-Control`, etc.). CRLF injections and `Set-Cookie` are explicitly rejected.
- `ExpiresActive`, `ExpiresByType`: MIME-type cache expiration times converted to Nginx `expires` directives.
- `Options`: `-Indexes` (`autoindex off;`) and `+Indexes` (`autoindex on;`).
- `Require`, `Deny`: Access control for `Require all granted/denied` and CIDR/IP allow/deny rules.
- `AuthType Basic`, `AuthName`, `AuthUserFile`: Basic HTTP authentication mapped to standard Nginx `auth_basic`.

### 7.3 Unsupported Directive Reporting
Unsupported directives are tracked with line numbers and explanatory guidance in the UI:
- `php_value`, `php_flag`: Directs user to the panel's PHP Manager (`/domains/{domain}/php`).
- `SetEnv`: Recommends `.env` or PHP Manager environment options.
- `AddHandler`, `AddType`: Managed automatically by Nginx fastcgi wrappers.
- `mod_security`, `Order`: Apache 2.2 access orders or legacy modules.

### 7.4 Automatic Sync Lifecycle
- **File Manager**: Saving, uploading, or deleting `.htaccess` triggers an instant background sync.
- **Online SFTP**: Committing file changes, uploading, or removing `.htaccess` triggers background sync.
- **Git Deploy**: Automated deployments trigger `SyncHtaccessRulesJob` upon successful branch checkout.
- **Scheduled Reconciliation**: `php artisan htaccess:sync` runs every 15 minutes via the Laravel scheduler.


