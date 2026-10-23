#!/usr/bin/env bash
# ==============================================================================
# HPanel Controller Database Automated Backup Script
# ==============================================================================
set -euo pipefail

BACKUP_DIR="/var/backups/hpanel"
DATE=$(date +"%Y%m%d_%H%M%S")
DB_NAME="${DB_DATABASE:-hpanel}"
DB_USER="${DB_USERNAME:-hpanel}"
DB_PASS="${DB_PASSWORD:-}"
RETENTION_DAYS=14

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

BACKUP_FILE="${BACKUP_DIR}/hpanel_db_${DATE}.sql.gz"

echo "[$(date -u +'%Y-%m-%dT%H:%M:%SZ')] Starting HPanel database snapshot: ${BACKUP_FILE}"

if [ -n "$DB_PASS" ]; then
    mysqldump --single-transaction --quick -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" | gzip -9 > "${BACKUP_FILE}"
else
    mysqldump --single-transaction --quick -u "${DB_USER}" "${DB_NAME}" | gzip -9 > "${BACKUP_FILE}"
fi

chmod 600 "${BACKUP_FILE}"
echo "[$(date -u +'%Y-%m-%dT%H:%M:%SZ')] Snapshot created successfully. Size: $(du -sh "${BACKUP_FILE}" | awk '{print $1}')"

# Retention prune
echo "[$(date -u +'%Y-%m-%dT%H:%M:%SZ')] Pruning snapshots older than ${RETENTION_DAYS} days..."
find "$BACKUP_DIR" -type f -name "hpanel_db_*.sql.gz" -mtime +"${RETENTION_DAYS}" -delete

echo "[$(date -u +'%Y-%m-%dT%H:%M:%SZ')] Panel database backup finished."

