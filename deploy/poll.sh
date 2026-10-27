#!/usr/bin/env bash
# ==============================================================================
# HPanel GitHub Poll Script
# Executed by: root (via hpanel-deploy-poll.timer / service every 10 minutes)
# Checks origin/main for newer commits and writes deployment trigger if found.
# ==============================================================================
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/hpanel}"
DEPLOY_DIR="${DEPLOY_DIR:-/var/lib/hpanel/deploy}"
TRIGGER_FILE="${DEPLOY_DIR}/trigger.json"
ORIGIN_STATUS_FILE="${DEPLOY_DIR}/origin_status.json"
LOCK_FILE="${LOCK_FILE:-/var/lock/hpanel-deploy.lock}"
LOG_FILE="${LOG_FILE:-/var/log/hpanel-deploy.log}"

# Select PHP 8.4 binary
if command -v php8.4 &>/dev/null; then
    PHP_BIN="php8.4"
elif command -v php &>/dev/null; then
    PHP_BIN="php"
else
    PHP_BIN="/usr/bin/php"
fi

DRY_RUN=false
while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        *)
            shift
            ;;
    esac
done

log() {
    local ts
    ts="$(date '+%Y-%m-%d %H:%M:%S')"
    echo "[${ts}] [poll] $*"
    if [ "$DRY_RUN" = false ]; then
        mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
        echo "[${ts}] [poll] $*" >> "$LOG_FILE" 2>/dev/null || true
    fi
}

execute() {
    if [ "$DRY_RUN" = true ]; then
        log "[DRY-RUN] Would execute: $*"
    else
        "$@"
    fi
}

# If an update is currently running, exit safely
if [ -f "$LOCK_FILE" ] && [ "$DRY_RUN" = false ]; then
    if ! flock -n "$LOCK_FILE" true 2>/dev/null; then
        log "Update in progress (lock held). Skipping poll."
        exit 0
    fi
fi

# Ensure safe git directory ownership for root
git config --global --add safe.directory "${APP_DIR}" 2>/dev/null || true
cd "${APP_DIR}"

CURRENT_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "")
if [ -z "$CURRENT_COMMIT" ]; then
    log "Error: Unable to determine current HEAD commit."
    exit 1
fi

log "Querying remote repository (origin/main)..."
REMOTE_REF_LINE=$(git ls-remote origin refs/heads/main 2>/dev/null || true)
REMOTE_COMMIT=$(echo "$REMOTE_REF_LINE" | awk '{print $1}')

if [ -z "$REMOTE_COMMIT" ]; then
    log "Warning: Failed to reach GitHub or empty response for refs/heads/main."
    exit 0
fi

log "Current commit: ${CURRENT_COMMIT}, Remote commit: ${REMOTE_COMMIT}"

if [ "$CURRENT_COMMIT" != "$REMOTE_COMMIT" ]; then
    log "Newer commit detected on origin/main (${REMOTE_COMMIT}). Inspecting changes..."

    # Fetch to inspect commits and detect migrations
    if [ "$DRY_RUN" = false ]; then
        git fetch --quiet origin main 2>/dev/null || true
        MIGRATIONS=$(git diff --name-only "${CURRENT_COMMIT}" "${REMOTE_COMMIT}" 2>/dev/null | grep '^database/migrations/' || true)
        HAS_MIGRATIONS=$([ -n "$MIGRATIONS" ] && echo true || echo false)
        COMMIT_MSG=$(git log -1 --pretty=%s "${REMOTE_COMMIT}" 2>/dev/null | tr -d '"\n')
        AUTHOR=$(git log -1 --pretty=%an "${REMOTE_COMMIT}" 2>/dev/null | tr -d '"\n')

        mkdir -p "${DEPLOY_DIR}"

        # Write origin_status.json
        cat << EOF > "${ORIGIN_STATUS_FILE}.tmp.$$"
{
    "current_sha": "${CURRENT_COMMIT}",
    "remote_sha": "${REMOTE_COMMIT}",
    "commit_message": "${COMMIT_MSG}",
    "author": "${AUTHOR}",
    "up_to_date": false,
    "has_migrations": ${HAS_MIGRATIONS},
    "last_checked_at": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
}
EOF
        mv -f "${ORIGIN_STATUS_FILE}.tmp.$$" "${ORIGIN_STATUS_FILE}"

        # Atomic write to trigger.json to initiate update via hpanel-deploy.path
        cat << EOF > "${TRIGGER_FILE}.tmp.$$"
{
    "action": "deploy",
    "commit_sha": "${REMOTE_COMMIT}",
    "commit_message": "${COMMIT_MSG}",
    "author": "${AUTHOR}",
    "delivery_id": "poll-$(date +%s)",
    "has_migrations": ${HAS_MIGRATIONS},
    "queued_at": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
}
EOF
        chmod 644 "${TRIGGER_FILE}.tmp.$$"
        mv -f "${TRIGGER_FILE}.tmp.$$" "${TRIGGER_FILE}"

        # Record queued status in database
        ${PHP_BIN} artisan deploy:record-status \
            --status="queued" \
            --commit="${REMOTE_COMMIT}" \
            --action="deploy" 2>/dev/null || true

        log "Deployment trigger written for commit ${REMOTE_COMMIT} (migrations=${HAS_MIGRATIONS})."
    else
        log "[DRY-RUN] Would fetch origin main, detect migrations, write ${TRIGGER_FILE}, and record queued deploy run"
    fi
else
    log "Repository is up to date at commit ${CURRENT_COMMIT}."
    if [ "$DRY_RUN" = false ]; then
        mkdir -p "${DEPLOY_DIR}"
        cat << EOF > "${ORIGIN_STATUS_FILE}.tmp.$$"
{
    "current_sha": "${CURRENT_COMMIT}",
    "remote_sha": "${REMOTE_COMMIT}",
    "up_to_date": true,
    "has_migrations": false,
    "last_checked_at": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
}
EOF
        mv -f "${ORIGIN_STATUS_FILE}.tmp.$$" "${ORIGIN_STATUS_FILE}"
    else
        log "[DRY-RUN] Would update ${ORIGIN_STATUS_FILE} with up_to_date=true"
    fi
fi

exit 0

