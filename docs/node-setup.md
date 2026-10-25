# Node Setup & Secret Synchronization Guide

This document describes how to set up an HPanel worker node and synchronize cryptographic secrets between the node agent and the central Laravel panel.

---

## 1. Node Agent Secret Architecture

Communication between the central HPanel controller and node agents (`panel-agent`) is secured via HMAC-SHA256 request signatures and mTLS.

- **Node side**: The agent stores its secret configuration in `/etc/panel-agent/config.yaml`.
- **Panel side**: The panel reads the HMAC secret from `.env` via `PANEL_AGENT_HMAC_SECRET`.

> [!IMPORTANT]
> The HMAC secret must match on both the node and the panel. In production (`APP_ENV=production`), the panel refuses to execute actions and fails at boot if `PANEL_AGENT_HMAC_SECRET` is left empty or set to the fallback default.

---

## 2. Setting Up a New Node

### Step 2.1: Run the Node Installer

On the target Debian 12 / Ubuntu 24.04 server, execute the automated installer as root:

```bash
sudo ./deploy/install-node.sh --panel-ip <PANEL_IP> --listen <PRIVATE_IP>:8443
```

The installer performs the following actions:
1. Installs base dependencies (Nginx, PHP-FPM 8.2-8.4, MariaDB, Certbot, UFW).
2. Generates internal TLS certificates under `/etc/panel-agent/certs/` if missing.
3. Creates `/etc/panel-agent/config.yaml` with a randomly generated 64-character hexadecimal HMAC secret (mode `0600`).
4. Compiles and installs the Go agent to `/usr/local/bin/panel-agent`.
5. Installs and starts the `hpanel-agent.service` systemd service.
6. Configures UFW firewall to open port 8443 exclusively to `PANEL_IP`.

### Step 2.2: Copy HMAC Secret to Panel Controller

To retrieve the HMAC secret from the agent configuration:

```bash
sudo jq -r '.auth.hmac_secret' /etc/panel-agent/config.yaml
```

Copy this value into the Laravel controller `.env` file:

```dotenv
PANEL_AGENT_HMAC_SECRET=<secret-from-node-config>
```

After updating `.env` on the panel, clear config cache:

```bash
php artisan config:clear
```

---

## 3. Node Enrollment

Each server node is registered with the central panel using a one-time enrollment token generated from the panel admin interface or API (`POST /api/v1/servers/enroll`).

### Step 3.1: Automatic Enrollment via Installer

If you provide `--panel-url` and `--enrollment-token` during installation:

```bash
sudo ./deploy/install-node.sh \
  --panel-url https://hpanel.example.com \
  --enrollment-token <ONE_TIME_TOKEN> \
  --panel-ip <PANEL_IP>
```

### Step 3.2: Standalone Enrollment Script (`enroll.sh`)

If the node was installed without enrolling, run `deploy/enroll.sh`:

```bash
sudo ./deploy/enroll.sh \
  --panel-url https://hpanel.example.com \
  --token <ONE_TIME_TOKEN>
```

You can test the enrollment request without sending secrets using `--dry-run`:

```bash
./deploy/enroll.sh --dry-run \
  --panel-url https://hpanel.example.com \
  --token <ONE_TIME_TOKEN>
```

Upon successful enrollment:
- Server ID and metadata are saved to `/etc/panel-agent/enrolled.json` (mode `0600`).
- Subsequent runs of `enroll.sh` detect the existing enrollment and exit safely (`already enrolled`).

---

## 4. Verification and Troubleshooting

Check that the agent service is active:

```bash
sudo systemctl status hpanel-agent
```

Verify that port 8443 is listening:

```bash
ss -tlnp | grep 8443
```

Inspect agent logs:

```bash
sudo journalctl -u hpanel-agent -n 50 --no-pager
# or file logs:
sudo tail -n 50 /var/log/panel-agent/agent.log
```

Test connectivity from the panel server using the agent ping command:

```bash
php artisan tinker --execute="app(\App\Services\AgentClient::class)->ping(\App\Models\Server::first());"
```
