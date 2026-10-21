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

---

## 4. Atomic Staging & Rollback Strategy

To prevent corrupted Nginx configurations from taking down active websites:
1. **Render to Staging**: The agent renders the virtual host template to a temporary staging path: `/tmp/panel-agent/<domain>.conf.tmp`.
2. **Pre-flight Syntax Test**: The agent executes `nginx -t`. If validation fails, the staging file is immediately removed and the error output is captured and returned in the job logs. The running Nginx process is untouched.
3. **Atomic Activation**: On validation success, the file is atomically moved to `/etc/nginx/sites-available/<domain>.conf` and symlinked to `/etc/nginx/sites-enabled/<domain>.conf`.
4. **Graceful Reload**: `systemctl reload nginx` is triggered. If the reload fails, the agent immediately removes the newly created symlink and restores previous configurations, ensuring zero downtime for existing domains.

---

## 5. Application Profiles

- `php-generic`: Standard PHP web application with `/index.php` front-controller, fastcgi buffering, and socket routing to `/run/php/php<version>-fpm-<username>.sock`.
- `laravel`: Enforces document root `/public`, blocks `.env` and dotfile access, passes `REQUEST_URI` to `index.php`.
- `wordpress`: Standard WordPress routing rules, XML-RPC hardening, blocks direct access to `wp-config.php` and uploaded PHP files in `wp-content/uploads/`.
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

