# End-to-End Operational Lifecycle Verification Checklist

This document details the step-by-step verification lifecycle for testing a production HPanel deployment on real Linux server nodes (Ubuntu 24.04 LTS / Debian 12).

---

## 1. Node Provisioning & Service Health
- [ ] **Step 1: Install Node**
  - Run: `bash /deploy/install-node.sh --dry-run` to preview installation commands.
  - Run without dry-run on target server: `sudo bash /deploy/install-node.sh`.
  - Verify installed services:
    ```bash
    systemctl status nginx
    systemctl status php8.2-fpm php8.3-fpm php8.4-fpm
    systemctl status mariadb
    systemctl status hpanel-agent
    ```
- [ ] **Step 2: Verify Prometheus Metrics & Health**
  - Query healthz: `curl http://127.0.0.1:8443/healthz` &rarr; `{"status":"ok"}`
  - Query metrics: `curl http://127.0.0.1:8443/metrics` &rarr; verifies `hpanel_agent_uptime_seconds` and `hpanel_agent_requests_total`.
- [ ] **Step 3: Verify UFW Firewall Rules**
  - Run: `sudo ufw status verbose`
  - Ports 22, 80, 443 must be `ALLOW IN Anywhere`.
  - Agent port 8443 must NOT be reachable from public WAN interface.

---

## 2. Server Enrollment & Capacity Scheduling
- [ ] **Step 4: Generate One-Time Token**
  - In Panel: Navigate to `Servers` &rarr; click **Generate Enrollment Token**.
  - Token is 64 hex characters with expiration timestamp.
- [ ] **Step 5: Automated Node Enrollment**
  - Run `bash /deploy/install-node.sh --panel-url https://panel.example.com --enrollment-token <TOKEN>`.
  - Panel verifies token, records server record with status `ACTIVE`, and stores agent certificates.
  - Verify server appears in Server List with status `Active`.

---

## 3. Account Provisioning & Isolation
- [ ] **Step 6: Create Hosting Package**
  - Name: `Standard Plan`, Storage: `10240 MB`, Bandwidth: `100 GB`, Inodes: `100,000`, PHP Workers: `4`.
- [ ] **Step 7: Provision Account (`account.create`)**
  - Linux Username: `demoacct`, Primary Domain: `demo.example.com`.
  - Check node filesystem:
    - User exists: `id demoacct`
    - Home exists: `ls -la /home/demoacct` (permissions `0750`, owned by `demoacct:demoacct`)
    - PHP-FPM pool active: `/etc/php/8.3/fpm/pool.d/demoacct.conf` running as user `demoacct`
    - Document root: `/home/demoacct/public_html`

---

## 4. Virtual Host Configuration & SSL
- [ ] **Step 8: Add Domain & Render Vhost (`vhost.write`)**
  - Add domain `demo.example.com`.
  - Verify Nginx configuration created at `/etc/nginx/sites-available/demo.example.com.conf`.
  - Test syntax: `nginx -t`.
  - Verify symlink in `/etc/nginx/sites-enabled/`.
- [ ] **Step 9: SSL Certificate Issuance (`ssl.issue`)**
  - Issue Let's Encrypt certificate via Certbot HTTP-01 challenge.
  - Verify HTTPS certificate active and auto-renewal cron registered.

---

## 5. Application Deployments & Database
- [ ] **Step 10: Git Deployment (`git.deploy`)**
  - Provide repository URL, generate deploy key.
  - Trigger deployment webhook (`POST /api/v1/git-deploy/{secret}`).
  - Verify release directory structure: `/home/demoacct/git_releases/<id>/releases/` and `/current` symlink.
  - Test deployment rollback.
- [ ] **Step 11: WordPress Automated Install (`wordpress.install`)**
  - Trigger one-click WordPress installation via panel.
  - Verify WP-CLI executes as `demoacct`.
  - Verify `wp-config.php` generated with secure database credentials and unique salts.
- [ ] **Step 12: Database & SSO Access (`db.create` + phpMyAdmin)**
  - Create database `demoacct_app` and user `demoacct_dbuser`.
  - Launch one-click SSO into phpMyAdmin (`/accounts/{id}/databases/{user}/sso`).
  - Verify auto-login succeeds without manual password entry.

---

## 6. Automation, Logs & Backups
- [ ] **Step 13: Cron Jobs (`cron.write`)**
  - Add cron schedule: `0 * * * * php /home/demoacct/public_html/artisan schedule:run`.
  - Verify entry in user crontab: `crontab -u demoacct -l`.
- [ ] **Step 14: Log Streaming (`log.tail`)**
  - Inspect access and error logs in real-time from `/home/demoacct/logs/`.
- [ ] **Step 15: Encrypted Restic Backups (`backup.create` & `backup.restore`)**
  - Run manual snapshot. Verify files and MariaDB databases are captured.
  - Perform test restore into temporary path. Verify checksum integrity.

---

## 7. Metering, Multi-Server Drain & Termination
- [ ] **Step 16: Usage Collection (`usage:collect`)**
  - Run: `php artisan usage:collect`.
  - Verify disk usage and Nginx bandwidth parsed via offset tracking into `usage_snapshots`.
- [ ] **Step 17: Server Drain Mode & Account Migration**
  - Toggle node status to `DRAINING`.
  - Confirm `ServerScheduler` bypasses draining node for new account provisions.
  - Trigger `MigrateAccountServerJob` to transfer `demoacct` to target node.
  - Verify database pointers updated and audit log `account.migrated` written.
- [ ] **Step 18: Account Suspension & Termination**
  - Suspend account: verify PHP-FPM pool disabled and Nginx displays 503 Maintenance page.
  - Unsuspend account: verify normal traffic restores.
  - Terminate account: verify user, databases, cron jobs, and home directory removed.

