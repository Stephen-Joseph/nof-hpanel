# Resource Usage Metering and Quota Enforcement

## 1. Overview
Phase 14 delivers real-time, tamper-resistant usage telemetry and quota enforcement across hosting accounts:
- **Disk Storage**: Measured via `du -sb /home/<user>` or Linux filesystem quotas.
- **File Inodes**: Monitored across account directories.
- **Monthly Bandwidth**: Byte-accurate incremental aggregation from Nginx combined access logs using offset tracking.
- **MariaDB Databases**: Real-time schema footprint querying from `information_schema.tables`.
- **PHP-FPM Workers**: Active and total process telemetry.
- **Automated Enforcement**: Multi-tier alert webhooks (`usage.warning` at 80% and 90%, `usage.limit_reached` at 100%), traffic throttling, and monthly bandwidth counter resets.

---

## 2. Agent Action: `usage.collect`

The Go server agent action `usage.collect` runs with strict parameter validation:
```json
{
  "username": "alicecorp",
  "domains": ["alicecorp.com", "blog.alicecorp.com"],
  "databases": ["alicecorp_wp"],
  "log_offsets": {
    "alicecorp.com": 1048576
  }
}
```

### Log Parsing with Byte Offset Tracking
Bandwidth calculation does not re-read entire access log files on every cycle:
1. The agent stores the previous byte position (`start_offset`) for each domain's log at `/home/<user>/logs/<domain>_access.log`.
2. If the current file size is smaller than `start_offset` (e.g. following logrotate), `start_offset` automatically resets to 0.
3. The agent seeks to `start_offset` and scans line-by-line in Nginx combined format:
   `$remote_addr - $remote_user [$time_local] "$request" $status $body_bytes_sent "$http_referer" "$http_user_agent"`
4. `$body_bytes_sent` is accumulated and returned alongside the new file end offset.

### Returned Telemetry Structure
```json
{
  "username": "alicecorp",
  "storage_bytes": 268435456,
  "storage_mb": 256.0,
  "inodes": 4120,
  "bandwidth_bytes_total": 52428800,
  "domain_bandwidth": {
    "alicecorp.com": 52428800
  },
  "new_log_offsets": {
    "alicecorp.com": 1101004
  },
  "database_bytes_total": 15728640,
  "database_bytes": {
    "alicecorp_wp": 15728640
  },
  "fpm_active_workers": 1,
  "fpm_idle_workers": 3,
  "fpm_total_workers": 4
}
```

---

## 3. Scheduled Telemetry Pipeline

### 15-Minute Polling (`usage:collect`)
- Configured in `routes/console.php`:
  `Schedule::command('usage:collect')->everyFifteenMinutes();`
- Dispatches `CollectAccountUsageJob` for each active account.
- Stores historical telemetry in `usage_snapshots`:
  - `account_id`
  - `storage_mb`, `inodes`, `bandwidth_bytes`, `database_bytes`
  - `fpm_active_workers`, `fpm_total_workers`
  - `recorded_at`
- Atomically updates current account aggregates in the `accounts` table.

### Monthly Reset (`bandwidth:reset`)
- Configured in `routes/console.php`:
  `Schedule::command('bandwidth:reset')->monthlyOn(1, '00:00');`
- Resets accumulated monthly bandwidth to 0:
  - `current_bandwidth_bytes = 0`
  - `bandwidth_reset_at = now()`
  - `is_throttled = false`
  - `usage_warning_level = 0`

---

## 4. Quota Enforcement & Webhooks

### Enforcement Tiers
| Threshold | Warning Level | Action |
| :--- | :--- | :--- |
| **80%** | `80` | Dispatches `usage.warning` (tier 80) webhook |
| **90%** | `90` | Dispatches `usage.warning` (tier 90) webhook |
| **100%+** | `100` | Dispatches `usage.limit_reached` webhook, marks account `is_throttled = true` |
| **< 80%** | `0` | Resets warning level to 0, clears throttling |

---

## 5. API v1 Integration

`GET /api/v1/accounts/{id}/usage` returns current live telemetry alongside package allowances:
```json
{
  "account_id": 1,
  "username": "alicecorp",
  "is_throttled": false,
  "warning_level": 0,
  "usage": {
    "storage_mb": {
      "used": 256.0,
      "limit": 10240,
      "percentage": 2.5
    },
    "inodes": {
      "used": 4120,
      "limit": 100000,
      "percentage": 4.1
    },
    "bandwidth_gb": {
      "used": 0.05,
      "limit": 100,
      "percentage": 0.1
    },
    "websites": {
      "used": 1,
      "limit": 5
    },
    "databases": {
      "used": 1,
      "limit": 5
    }
  }
}
```

