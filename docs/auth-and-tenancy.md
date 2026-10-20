# Authentication, Authorization, Tenancy & Audit Logging

## Overview

The Hosting Panel implements multi-tenancy and role-based access control (RBAC) across three primary tiers:
1. **Administrator (`admin`)**: Complete visibility and administrative authority across all system nodes, packages, accounts, and audit records.
2. **Reseller (`reseller`)**: Scoped access restricted strictly to their own subtree (`parent_id = $reseller->id`). Resellers can manage customers and hosting accounts they create, but cannot inspect or modify accounts owned by other resellers or administrators.
3. **Customer (`customer`)**: Self-service portal access restricted strictly to their own account resources, primary domains, and security settings.

---

## Security & Authentication Model

### 1. Two-Factor Authentication (TOTP - RFC 6238)
- Zero third-party runtime dependencies (`TotpService` utilizes PHP native `hash_hmac` and Base32 dynamic truncation).
- 30-second time counter with +/- 1 window discrepancy allowance for client clock drift.
- Secrets are encrypted at rest using Laravel's `'encrypted'` model cast.
- Emergency recovery codes are cryptographically generated, encrypted at rest, and permanently consumed upon use.

### 2. Login Throttling & Protection
- Throttles failed authentication attempts via Laravel's `RateLimiter` (5 attempts per minute per email + IP key).
- Suspended user accounts (`status = 'suspended'`) are rejected prior to session establishment.
- Password resets employ signed expiration tokens delivered via standard notifications.

### 3. Session Management
- Active sessions are recorded in the `sessions` database table.
- Users can view active browser sessions with IP address, device user-agent, and last activity timestamps.
- Users can revoke individual sessions or purge all other active sessions with password verification.

---

## Multi-Tenancy Authorization Matrix

| Actor | Action / Resource | Permission | Enforcement |
|---|---|---|---|
| **Admin** | View all users / audit logs | Allowed | `UserPolicy::viewAny`, `AuditLogPolicy::viewAny` |
| **Admin** | Update / Delete any user | Allowed (except delete self) | `UserPolicy::update`, `UserPolicy::delete` |
| **Reseller** | View users index | Allowed (filtered to own subtree) | `UserPolicy::viewAny` |
| **Reseller** | Manage customer in own subtree | Allowed | `UserPolicy::view`, `update`, `delete` |
| **Reseller** | Manage customer of another reseller | **Denied (403)** | `UserPolicy` verifies `isDescendantOf` |
| **Reseller** | Manage or delete admin | **Denied (403)** | `UserPolicy` rejects non-descendant |
| **Customer** | View user index | **Denied (403)** | `UserPolicy::viewAny` returns false |
| **Customer** | View / Update other customers | **Denied (403)** | `UserPolicy` checks `$auth->id === $target->id` |
| **Customer** | View / Update self | Allowed | `UserPolicy::view`, `UserPolicy::update` |

---

## Audit Logging

All security-sensitive operations (logins, logouts, 2FA activations, session terminations, profile updates) are recorded in the `audit_logs` table via `App\Services\AuditService`.

### Redaction Rules
To adhere strictly to zero-cleartext secrets policies, `AuditService` automatically filters and redacts:
- `password`, `password_confirmation`, `current_password`
- `two_factor_secret`, `two_factor_recovery_codes`
- `token`, `api_token`, `secret`, `remember_token`

Admins can inspect the immutable log trail via the `/admin/audit-logs` Livewire interface, featuring live search, action categorization, date range filters, and structured JSON diff modals.

