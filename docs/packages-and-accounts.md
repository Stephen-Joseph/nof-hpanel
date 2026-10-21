# Packages, Servers & Accounts (Database & Management Layer)

## Overview

Phase 2 establishes the core multi-tenant data model and administrative interfaces for hosting resources:
1. **Server Nodes (`servers`)**: Linux host instances configured with the Go agent daemon, managed over private/WireGuard network interfaces.
2. **Hosting Packages (`packages`)**: Defined resource limits, quotas (storage, inodes, bandwidth), PHP runtime settings (workers, memory limit, allowed versions), and feature flags (SSH, Git).
3. **Hosting Accounts (`accounts`)**: Isolated Linux customer environments mapped to a user, package, and target server node.

---

## Data Model & Schemas

### Servers
- `name`: Human-readable label (e.g. `Primary Production Node`).
- `hostname`: Fully qualified domain name on internal network.
- `private_ip`: Dedicated IP on private/WireGuard network (no public agent ports).
- `agent_url`: mTLS HTTPS endpoint for typed agent actions (e.g. `https://10.0.0.1:8443`).
- `status`: `active`, `maintenance`, `draining`, `offline`.
- `agent_public_cert_fingerprint`: SHA-256 fingerprint of server node certificate.
- Capacity bounds: `max_accounts`, `cpu_cores`, `memory_mb`, `storage_mb`.

### Packages
- Storage & Quotas: `storage_mb`, `inodes`, `bandwidth_gb`.
- Web & Database limits: `max_websites`, `max_subdomains`, `max_databases`, `max_db_users`, `max_ftp_accounts`, `max_cron_jobs`.
- PHP Pool parameters: `php_workers` (`pm.max_children`), `php_memory_mb` (`memory_limit`), `allowed_php_versions` (JSON list).
- Feature flags: `ssh_enabled`, `git_enabled`, `backups_retained`.
- Reseller ownership: `reseller_owner_id` (null indicates a system-wide global package).

### Accounts
- `user_id`: Customer owner.
- `package_id`: Assigned package limits.
- `server_id`: Target node.
- `linux_username`: Strict allow-list regex `^[a-z][a-z0-9]{2,15}$`. Reserved system accounts are blocked.
- `primary_domain`: RFC-compliant lowercase domain name.
- `status`: `pending`, `active`, `suspended`, `terminating`, `terminated`, `failed`.
- `suspended_reason`: Reason recorded when suspended by admin or reseller.

---

## Provisioning Workflow

When an account is created via the web interface or API:
1. Input is strictly validated (regex on Linux username and domain, customer tenancy checks, server availability).
2. The account record is created in the database with status `pending`.
3. The `ProvisionAccount` queue job is dispatched.
4. An immutable audit log row is recorded (`account.created`).
5. (In Phase 3/4, the server agent will receive signed mTLS job payloads to create the Linux user, directory tree, quotas, and PHP-FPM pools).

---

## Multi-Tenancy & Authorization Matrix

| Actor | Packages | Servers | Accounts |
|---|---|---|---|
| **Admin** | View all, create global packages, edit/delete any | View all, register, edit, delete (if empty) | View all, create for any customer, suspend/delete any |
| **Reseller** | View system packages + own packages; create/edit/delete own packages | No access (403) | View/create/manage accounts only for customers in own reseller subtree |
| **Customer** | View available packages | No access (403) | View only own accounts; cannot create or delete |

