# Resellers and Multi-Server Management

## 1. Overview
Phase 15 provides complete multi-tenancy extension for resellers and enterprise multi-server orchestration:
- **Reseller Tenancy & Isolation**: Reseller accounts can manage their own customer portfolios, provision scoped hosting packages, and configure white-label branding (custom company names, vanity domains, and support emails).
- **Resource Pool Limits**: Hard boundaries on total accounts, aggregate disk storage (MB), and aggregate bandwidth (GB) assigned by root administrators.
- **Node Enrollment**: Secure, zero-touch server provisioning via cryptographically signed one-time enrollment tokens (`ServerEnrollmentToken`).
- **Server Capacity Scheduler**: Least-allocated scheduling algorithm (`ServerScheduler`) directing new account placement across server nodes, respecting server groups, geographic regions, and maximum account capacities.
- **Drain Mode**: Maintenance mode flag (`DRAINING`) ensuring servers complete existing traffic without receiving new account provisions.
- **Cross-Server Account Migration**: Automated background job (`MigrateAccountServerJob`) coordinating snapshot generation, remote account creation, data restoration, virtual host configuration, and database pointer updates.

---

## 2. Reseller Tenancy Model

### User Hierarchy and Isolation
The multi-tier tenancy model is enforced via the `parent_id` attribute on the `users` table:
- **Root Admin**: Full visibility over all servers, packages, resellers, and direct customers.
- **Reseller (`UserRole::RESELLER`)**:
  - Attached to a `ResellerProfile` containing max account count, storage pool limits, and branding settings.
  - Can only view and manage customers where `parent_id == reseller->id`.
  - Can only view and create packages where `reseller_owner_id == reseller->id`.
  - Enforced at the policy layer (`UserPolicy`, `PackagePolicy`) and within Livewire components.
- **Customer (`UserRole::CUSTOMER`)**:
  - Bound to their parent reseller (or root administrator if self-registered).
  - Cannot access the reseller control center (`/reseller`).

### Resource Pool Enforcement
When creating customer accounts or provisioning custom hosting packages, the reseller's aggregate usage is computed against their `ResellerProfile`:
- **Accounts Limit**: `customers()->withCount('accounts')->sum('accounts_count') < max_accounts`.
- **Package Limits**: Packages created by resellers cannot define resource caps higher than the reseller's total pool ceiling.

### White-Label Branding
Resellers can customize:
- `company_name`: Replaces default panel branding in communications.
- `custom_domain`: Vanity hostname for reseller client portal access.
- `support_email`: Direct routing for tenant customer support inquiries.

---

## 3. Multi-Server Node Enrollment

### Automated Enrollment via One-Time Tokens
Instead of storing SSH credentials or manual agent bootstrapping:
1. Root administrators generate a cryptographically random, 64-character enrollment token via the Panel UI or API (`ServerList` Livewire component).
2. Tokens have configurable expiration timestamps (e.g., 2 hours).
3. The server agent installer invokes the enrollment endpoint:
   `POST /api/v1/servers/enroll`
   ```json
   {
     "token": "d8e3b...64charToken...",
     "name": "node-lon-02.example.com",
     "ip_address": "198.51.100.22",
     "agent_port": 8443,
     "server_group_id": 1,
     "location": "LON-1",
     "max_accounts": 200,
     "agent_ca_cert": "-----BEGIN CERTIFICATE-----\n...",
     "agent_client_cert": "-----BEGIN CERTIFICATE-----\n...",
     "agent_client_key": "-----BEGIN EC PRIVATE KEY-----\n..."
   }
   ```
4. The panel validates the token, marks it as used, creates the `Server` record with status `ACTIVE`, securely stores the agent mTLS credentials, and returns `{ "success": true, "server_id": 4 }`.

---

## 4. Server Capacity Scheduler

The `ServerScheduler` (`App\Services\ServerScheduler`) handles intelligent node selection:

```php
$server = app(ServerScheduler::class)->selectServer(
    serverGroupId: $package->server_group_id,
    location: 'LON-1'
);
```

### Scheduling Rules:
1. **Health & State**: Strictly selects servers with `status = ServerStatus::ACTIVE`. Nodes marked `DRAINING`, `MAINTENANCE`, or `OFFLINE` are immediately excluded.
2. **Affinity Filters**: Optionally scopes down by `server_group_id` (e.g. SSD pool vs NVMe pool) and `location` (e.g., `NYC-1`, `LON-1`, `FRA-1`).
3. **Capacity Ceiling**: Verifies `accounts_count < max_accounts`.
4. **Least-Allocated Sort**: Orders candidates by `accounts_count ASC`, choosing the server with the lowest current workload.
5. **Failover**: If no server matches criteria or capacity is exhausted, throws `NoAvailableServerException`.

---

## 5. Drain Mode & Maintenance

Administrators can toggle any active server into `DRAINING` status:
- In-flight customer workloads and websites remain fully operational on the node.
- The `ServerScheduler` skips draining nodes for all subsequent account creation requests.
- Node is primed for decommission, maintenance, or staged migration.

---

## 6. Cross-Server Account Migration

The `MigrateAccountServerJob` transfers a hosting account between physical or virtual nodes without user data loss:

```mermaid
sequenceDiagram
    participant Panel as Laravel Panel
    participant Source as Source Agent Node
    participant Target as Target Agent Node

    Panel->>Source: 1. backup.create (full snapshot)
    Source-->>Panel: Returns snapshot ID & archive
    Panel->>Target: 2. account.create (user & pool isolation)
    Target-->>Panel: User created (/home/<user>)
    Panel->>Target: 3. backup.restore (snapshot unpacking)
    Target-->>Panel: Files, DBs & SSL restored
    Panel->>Target: 4. vhost.write (all account domains)
    Target-->>Panel: Nginx vhost configs active
    Panel->>Panel: 5. Atomically update account.server_id
    Panel->>Panel: 6. Record AuditLog (account.migrated)
```

### Rollback & Safeguards
- If any step fails during the migration pipeline, an exception is raised, keeping the account pointing to the source server.
- The source server account remains intact until explicit decommission or manual termination.
- An immutable audit trail entry (`account.migrated`) records old and new server identities, timestamps, and operator details.

