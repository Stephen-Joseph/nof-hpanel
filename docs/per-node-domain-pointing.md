# Per-Node Domain Pointing & Verification

This document specifies the per-node domain pointing, DNS verification, and SSL architecture in HPanel.

---

## 1. Overview & Problem Statement

In single-node hosting panels, a global edge hostname (e.g., `edge.nexorafox.in`) typically routes all incoming HTTP/HTTPS traffic. However, in a multi-node cluster:
- Each server node has its own public IPv4 and IPv6 addresses.
- Customer domains pointing to a single global edge hostname fail when provisioned on nodes other than Node 1.
- Global edge routing fails cross-node pointing checks and provides inaccurate DNS records to customers.

HPanel resolves this with **Per-Node Edge Addressing**:
1. Every server node maintains its own `public_ip` (required public IPv4), optional `public_ipv6`, and a unique `edge_host` (e.g., `edge-<node-slug>.<HPANEL_EDGE_BASE>`).
2. Domain verification strictly validates against the domain's assigned hosting account node.
3. Cross-node pointing detection identifies if a customer domain accidentally or maliciously points to another node in the cluster, returning an explicit diagnostic warning naming both nodes.
4. SSL preflight checks verify node IP reachability before calling Certbot.

---

## 2. Server Node Model & Configuration

### Model Fields (`servers` Table)
| Field | Type | Description |
|---|---|---|
| `public_ip` | string (IPv4) | Publicly routable IPv4 address. Private IPs (10.x, 172.16-31.x, 192.168.x, 127.x) are strictly rejected. |
| `public_ipv6` | string (IPv6), nullable | Optional public IPv6 address for AAAA records. |
| `edge_host` | string, nullable, unique | Dedicated edge hostname (e.g. `edge-fra-node-1.nexorafox.in`). |
| `edge_dns_checked_at` | timestamp, nullable | Last time edge DNS resolution was checked against `public_ip`. |
| `edge_dns_ok` | boolean, default `false` | Indicates whether `edge_host` resolves to `public_ip`. |

### Configuration (`config/hpanel.php`)
```php
'edge_base' => env('HPANEL_EDGE_BASE', 'nexorafox.in'),
'edge_host' => env('HPANEL_EDGE_HOST', 'edge.nexorafox.in'),
```

When a node is created or enrolled, its default `edge_host` is auto-generated:
`edge-<slug-of-server-name>.<HPANEL_EDGE_BASE>`

---

## 3. Node Enrollment & Public IP Detection

When enrolling a server node using `deploy/install-node.sh` or `deploy/enroll.sh`:
1. **CLI Flag `--public-ip`**: Explicitly specify the node's public IPv4.
2. **Auto-Detection**: If omitted, the installer queries `https://api.ipify.org`.
3. **Safety Fallback**: The panel inspects the request source IP. Private and reserved IPs (RFC 1918 / RFC 1122) are rejected with HTTP 422 `INVALID_PUBLIC_IP`.
4. **Summary**: The node installer outputs the registered public IP and certificate fingerprint in its final verification summary.

---

## 4. Domain Verification (`DomainVerificationService`)

Verification is performed per-domain against `$domain->account->server`:

### Step 1: Ownership Verification
- **DNS TXT Record**: `_hpanel-verify.<domain>` must match `hpanel-verify=<verification_token>`.
- **Fallback HTTP Proof**: The panel commands the account node agent to place a temporary verification token via `domain.http_probe`. The panel then fetches the probe URL:
  1. Using `--resolve <domain>:80:<node.public_ip>`
  2. Through public DNS

### Step 2: Routing Verification
- **CNAME Chain**: Traces up to 5 hops. Must terminate at the assigned node's `edge_host`.
- **A / AAAA Records**: Resolved domain IPs must match `node.public_ip`, `node.public_ipv6`, or live resolved IPs of `node.edge_host`.

### Step 3: Cross-Node Conflict Detection
If routing fails, the system checks whether the domain points to any *other* server node in the cluster. If detected:
- `dns_last_error`:
  `"Domain points to another node: <other_node> (<ip>) but belongs to <assigned_node> (<ip>)."`
- The domain is prevented from hijacking another node's vhost.

### Step 4: Account Node Migration
When `accounts.server_id` is updated, the domain's `verification_status` is automatically reset to `pending_dns`, forcing re-verification for the new node.

---

## 5. Automated SSL Preflight

Before requesting Let's Encrypt certificates via Certbot:
1. `IssueSslCertificateJob` passes `node_public_ip` to the Go agent.
2. The agent and panel verify that public DNS resolves to `node.public_ip`.
3. If public DNS differs from `node.public_ip`, the process stops with `DNS_NOT_POINTING`, preventing rate-limiting by Let's Encrypt.

---

## 6. Billing API & Scheduler Integration

### Account Provisioning (`POST /api/v1/accounts`)
- When `server_id` is omitted in the request, `ServerScheduler::selectServer()` selects the active server node with the lowest account allocation that has available capacity.
- The API response and `account.created` webhook payload return the `dns` object:
```json
{
  "dns": {
    "node_name": "Node 1 (Frankfurt)",
    "edge_host": "edge-node-1.nexorafox.in",
    "a_records": ["198.51.100.10", "2001:db8:1::1"],
    "txt_name": "_hpanel-verify.example.com",
    "txt_value": "hpanel-verify=abcdef1234567890abcdef1234567890",
    "verification_status": "pending_dns"
  }
}
```

---

## 7. Operational & Maintenance Commands

### Backfill Node Edge Data
```bash
php artisan hpanel:backfill-nodes [--force]
```
- Extracts `public_ip` from `agent_url` or prompts interactively.
- Generates `edge_host` for nodes missing one.
- Re-runs DNS verification for all domains whose node addressing changed.
