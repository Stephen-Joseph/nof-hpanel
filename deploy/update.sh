#!/usr/bin/env bash
# ==============================================================================
# HPanel Auto-Deployment & Safe Update Script with Automated Rollback
# Executed by: root (via hpanel-deploy.service or manual invocation)
# ==============================================================================
set -Eeuo pipefail

# Configuration & Directories
APP_DIR="${APP_DIR:-/var/www/hpanel}"
RELEASES_DIR="${RELEASES_DIR:-/var/lib/hpanel/releases}"
DEPLOY_DIR="${DEPLOY_DIR:-/var/lib/hpanel/deploy}"
TRIGGER_FILE="${DEPLOY_DIR}/trigger.json"
CURRENT_RELEASE_FILE="${DEPLOY_DIR}/current_release.json"
ORIGIN_STATUS_FILE="${DEPLOY_DIR}/origin_status.json"
LOCK_FILE="${LOCK_FILE:-/var/lock/hpanel-deploy.lock}"
LOG_FILE="${LOG_FILE:-/var/log/hpanel-deploy.log}"
AGENT_BIN="/usr/local/bin/panel-agent"
AGENT_SRC_BUILD="/usr/local/src/panel-agent-build"

# Select PHP 8.4 binary
if command -v php8.4 &>/dev/null; then
    PHP_BIN="php8.4"
elif command -v php &>/dev/null; then
    PHP_BIN="php"
else
    PHP_BIN="/usr/bin/php"
fi

DRY_RUN=false
IS_ROLLBACK=false
TARGET_SHA=""
FAILED_STEP="initialization"
START_TIME=$(date +%s)
CURRENT_COMMIT=""
PREV_AGENT_SAVED=false

# Parse arguments
while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --rollback)
            IS_ROLLBACK=true
            shift
            ;;
        --target-sha=*)
            TARGET_SHA="${1#*=}"
            shift
            ;;
        --target-sha)
            TARGET_SHA="$2"
            shift 2
            ;;
        *)
            shift
            ;;
    esac
done

# Logging helper: timestamped, sanitized, outputs to stdout and LOG_FILE
log() {
    local ts
    ts="$(date '+%Y-%m-%d %H:%M:%S')"
    # Strip any potential sensitive environment variables or secrets
    local clean_msg="$*"
    clean_msg="${clean_msg//${DEPLOY_WEBHOOK_SECRET:-__EMPTY_SECRET__}/[REDACTED_SECRET]}"
    echo "[${ts}] ${clean_msg}"
    if [ "$DRY_RUN" = false ]; then
        mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
        echo "[${ts}] ${clean_msg}" >> "$LOG_FILE" 2>/dev/null || true
    fi
}

execute() {
    if [ "$DRY_RUN" = true ]; then
        log "[DRY-RUN] Would execute: $*"
    else
        "$@"
    fi
}

# Error handler for automatic rollback
handle_error() {
    local exit_code=$?
    local line_no=$1
    trap - ERR

    local duration=$(( $(date +%s) - START_TIME ))
    log "======================================================================"
    log "ERROR: Deployment failed at step '${FAILED_STEP}' (line ${line_no}, exit code: ${exit_code})"
    log "======================================================================"

    if [ "$DRY_RUN" = true ]; then
        log "[DRY-RUN] Would perform automatic rollback to commit '${CURRENT_COMMIT}'"
        return $exit_code
    fi

    # Perform automated rollback
    log "Initiating automatic rollback to previous commit '${CURRENT_COMMIT}'..."
    cd "${APP_DIR}"

    if [ -n "${CURRENT_COMMIT}" ]; then
        git reset --hard "${CURRENT_COMMIT}" || log "Warning: Git reset to previous commit failed"
    fi

    local snapshot_dir="${RELEASES_DIR}/${CURRENT_COMMIT}"
    if [ "$PREV_AGENT_SAVED" = true ] && [ -f "${snapshot_dir}/panel-agent" ]; then
        log "Restoring previous agent binary from snapshot..."
        install -m 0755 "${snapshot_dir}/panel-agent" "${AGENT_BIN}" || log "Warning: Failed to restore agent binary"
        ln -sf "${AGENT_BIN}" /usr/local/bin/hpanel-agent 2>/dev/null || true
    fi

    # Attempt to clear caches and ensure services are running
    ${PHP_BIN} artisan optimize:clear 2>/dev/null || true
    ${PHP_BIN} artisan config:cache 2>/dev/null || true
    ${PHP_BIN} artisan route:cache 2>/dev/null || true
    ${PHP_BIN} artisan view:cache 2>/dev/null || true
    chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

    log "Restarting system services..."
    systemctl restart php8.4-fpm hpanel-queue hpanel-agent 2>/dev/null || true

    # Record rolled_back status in database
    ${PHP_BIN} artisan deploy:record-status \
        --status="rolled_back" \
        --commit="${CURRENT_COMMIT}" \
        --step="${FAILED_STEP}" \
        --duration="${duration}" \
        --log-file="${LOG_FILE}" 2>/dev/null || true

    # Remove trigger file so systemd doesn't re-trigger immediately
    rm -f "${TRIGGER_FILE}" 2>/dev/null || true

    log "Automatic rollback completed. System state restored to '${CURRENT_COMMIT}'."
    exit $exit_code
}

trap 'handle_error $LINENO' ERR

log "======================================================================"
log "Starting HPanel update runner (dry_run=${DRY_RUN}, rollback=${IS_ROLLBACK})"
log "======================================================================"

# 9. Concurrency Lock via flock on /var/lock/hpanel-deploy.lock
if [ "$DRY_RUN" = false ]; then
    mkdir -p "$(dirname "$LOCK_FILE")"
    exec 200>"$LOCK_FILE"
    if ! flock -n 200; then
        log "Another deployment process is currently holding the lock (${LOCK_FILE}). Exiting safely."
        exit 0
    fi
else
    log "[DRY-RUN] Would acquire exclusive lock on ${LOCK_FILE}"
fi

# Ensure directories exist
if [ "$DRY_RUN" = false ]; then
    mkdir -p "${RELEASES_DIR}" "${DEPLOY_DIR}"
else
    log "[DRY-RUN] Would ensure directories exist: ${RELEASES_DIR}, ${DEPLOY_DIR}"
fi

# Inspect trigger file if present
TRIGGER_COMMIT=""
TRIGGER_ACTION="deploy"
TRIGGER_DELIVERY=""
if [ -f "${TRIGGER_FILE}" ]; then
    log "Trigger file detected: ${TRIGGER_FILE}"
    TRIGGER_ACTION=$(grep -o '"action"[^,]*' "${TRIGGER_FILE}" | head -1 | cut -d'"' -f4 || echo "deploy")
    TRIGGER_COMMIT=$(grep -o '"commit_sha"[^,]*' "${TRIGGER_FILE}" | head -1 | cut -d'"' -f4 || echo "")
    TRIGGER_DELIVERY=$(grep -o '"delivery_id"[^,]*' "${TRIGGER_FILE}" | head -1 | cut -d'"' -f4 || echo "")
    if [ "$TRIGGER_ACTION" = "rollback" ]; then
        IS_ROLLBACK=true
        TARGET_SHA=$(grep -o '"target_sha"[^,]*' "${TRIGGER_FILE}" | head -1 | cut -d'"' -f4 || echo "")
    fi
fi

# Ensure safe git directory ownership for root
git config --global --add safe.directory "${APP_DIR}" 2>/dev/null || true
cd "${APP_DIR}"

# 10. Record Current Commit
FAILED_STEP="record current commit"
CURRENT_COMMIT=$(git rev-parse HEAD)
CURRENT_COMMIT_MSG=$(git log -1 --pretty=%s | tr -d '"\n')
CURRENT_AUTHOR=$(git log -1 --pretty=%an | tr -d '"\n')
log "Current commit: ${CURRENT_COMMIT} (${CURRENT_COMMIT_MSG})"

# 11. Create Release Snapshot of Previous State (last known good)
FAILED_STEP="create release snapshot"
SNAPSHOT_DIR="${RELEASES_DIR}/${CURRENT_COMMIT}"
if [ "$DRY_RUN" = false ]; then
    mkdir -p "${SNAPSHOT_DIR}"
    echo "${CURRENT_COMMIT}" > "${SNAPSHOT_DIR}/commit"
    echo "${CURRENT_COMMIT_MSG}" > "${SNAPSHOT_DIR}/message"
    echo "${CURRENT_AUTHOR}" > "${SNAPSHOT_DIR}/author"
    date -u +"%Y-%m-%dT%H:%M:%SZ" > "${SNAPSHOT_DIR}/created_at"
    if [ -f "${AGENT_BIN}" ]; then
        cp -f "${AGENT_BIN}" "${SNAPSHOT_DIR}/panel-agent"
        PREV_AGENT_SAVED=true
    fi
    log "Release snapshot saved at: ${SNAPSHOT_DIR}"
else
    log "[DRY-RUN] Would snapshot current release to ${SNAPSHOT_DIR}"
fi

# ==============================================================================
# MODE: ROLLBACK
# ==============================================================================
if [ "$IS_ROLLBACK" = true ]; then
    FAILED_STEP="rollback preparation"
    log "Executing rollback flow..."

    # If no target SHA provided, find the most recent previous release in releases directory
    if [ -z "$TARGET_SHA" ]; then
        # Find directory other than CURRENT_COMMIT
        TARGET_SHA=$(find "${RELEASES_DIR}" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' 2>/dev/null \
            | sort -nr \
            | awk '{print $2}' \
            | grep -v "/${CURRENT_COMMIT}$" \
            | head -n 1 \
            | xargs -r basename || true)
    fi

    if [ -z "$TARGET_SHA" ]; then
        log "Error: No target release snapshot found for rollback."
        rm -f "${TRIGGER_FILE}"
        exit 1
    fi

    log "Target rollback commit: ${TARGET_SHA}"

    FAILED_STEP="rollback git reset"
    execute git reset --hard "${TARGET_SHA}"

    FAILED_STEP="rollback agent restore"
    if [ -f "${RELEASES_DIR}/${TARGET_SHA}/panel-agent" ]; then
        execute install -m 0755 "${RELEASES_DIR}/${TARGET_SHA}/panel-agent" "${AGENT_BIN}"
        execute ln -sf "${AGENT_BIN}" /usr/local/bin/hpanel-agent
    fi

    FAILED_STEP="rollback composer"
    execute composer install --no-dev --optimize-autoloader --no-interaction

    FAILED_STEP="rollback frontend build"
    if [ -f "package.json" ]; then
        execute npm ci
        execute npm run build
    fi

    FAILED_STEP="rollback optimize and cache"
    execute ${PHP_BIN} artisan optimize:clear
    execute ${PHP_BIN} artisan config:cache
    execute ${PHP_BIN} artisan route:cache
    execute ${PHP_BIN} artisan view:cache
    execute chown -R www-data:www-data storage bootstrap/cache
    execute chmod -R 775 storage bootstrap/cache

    FAILED_STEP="rollback restart services"
    execute systemctl restart php8.4-fpm hpanel-queue hpanel-agent
    execute systemctl is-active --quiet php8.4-fpm
    execute systemctl is-active --quiet hpanel-queue
    execute systemctl is-active --quiet hpanel-agent
    execute ${PHP_BIN} artisan hpanel:agent-check

    # Update database record and files
    duration=$(( $(date +%s) - START_TIME ))
    if [ "$DRY_RUN" = false ]; then
        ${PHP_BIN} artisan deploy:record-status \
            --status="success" \
            --action="rollback" \
            --commit="${TARGET_SHA}" \
            --duration="${duration}" || true

        cat << EOF > "${CURRENT_RELEASE_FILE}"
{
    "commit": "${TARGET_SHA}",
    "message": "Rollback to ${TARGET_SHA}",
    "deployed_at": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")",
    "status": "rolled_back"
}
EOF
        rm -f "${TRIGGER_FILE}" 2>/dev/null || true
    fi

    log "Rollback to commit '${TARGET_SHA}' successfully completed."
    exit 0
fi

# ==============================================================================
# MODE: STANDARD DEPLOYMENT
# ==============================================================================
FAILED_STEP="git fetch"
log "Fetching latest changes from origin/main..."
execute git fetch origin main

FAILED_STEP="git reset"
TARGET_COMMIT=$(git rev-parse origin/main)
log "Target commit on origin/main: ${TARGET_COMMIT}"

# Record running status in deploy_runs
if [ "$DRY_RUN" = false ]; then
    ${PHP_BIN} artisan deploy:record-status \
        --status="running" \
        --commit="${TARGET_COMMIT}" \
        --action="deploy" 2>/dev/null || true
fi

log "Resetting tracked files to origin/main..."
execute git reset --hard origin/main

# Verify critical files
FAILED_STEP="verify repository integrity"
if [ ! -f "composer.json" ] || [ ! -f "artisan" ]; then
    log "FATAL: Required files composer.json or artisan missing after reset. Aborting."
    exit 1
fi

# 12. Run build pipeline in order
FAILED_STEP="composer install"
log "[1/7] Installing PHP dependencies via Composer..."
execute composer install --no-dev --optimize-autoloader --no-interaction

FAILED_STEP="database migrations"
log "[2/7] Running database migrations..."
execute ${PHP_BIN} artisan migrate --force

FAILED_STEP="frontend build"
log "[3/7] Building frontend assets (npm ci && npm run build)..."
execute npm ci
execute npm run build
if [ "$DRY_RUN" = false ] && [ ! -f "public/build/manifest.json" ]; then
    log "FATAL: public/build/manifest.json was not generated by Vite build. Aborting."
    exit 1
fi

FAILED_STEP="cache optimization"
log "[4/7] Clearing and warming Laravel caches..."
execute ${PHP_BIN} artisan optimize:clear
execute ${PHP_BIN} artisan config:cache
execute ${PHP_BIN} artisan route:cache
execute ${PHP_BIN} artisan view:cache
execute chown -R www-data:www-data storage bootstrap/cache
execute chmod -R 775 storage bootstrap/cache

# 13. Build & test the Go server agent
FAILED_STEP="agent build and test"
log "[5/7] Testing and building Go server agent..."
if [ "$DRY_RUN" = false ]; then
    mkdir -p "${AGENT_SRC_BUILD}"
    if [ -d "agent" ]; then
        cd agent
        log "Running go vet ./..."
        go vet ./...
        log "Running go test ./..."
        go test ./...
        log "Compiling agent binary to ${AGENT_SRC_BUILD}/panel-agent..."
        go build -o "${AGENT_SRC_BUILD}/panel-agent" ./cmd/agent
        cd "${APP_DIR}"

        log "Installing agent binary to ${AGENT_BIN}..."
        install -m 0755 "${AGENT_SRC_BUILD}/panel-agent" "${AGENT_BIN}"
        ln -sf "${AGENT_BIN}" /usr/local/bin/hpanel-agent
    else
        log "Warning: agent/ directory not found in repository. Skipping agent build."
    fi
else
    log "[DRY-RUN] Would test and build Go agent from agent/ into ${AGENT_SRC_BUILD}/panel-agent"
    log "[DRY-RUN] Would install agent binary to ${AGENT_BIN}"
fi

# 14. Restart services and perform health check
FAILED_STEP="service restart and health check"
log "[6/7] Restarting application and agent services..."
execute systemctl restart php8.4-fpm hpanel-queue hpanel-agent

log "Verifying service states..."
execute systemctl is-active --quiet php8.4-fpm
execute systemctl is-active --quiet hpanel-queue
execute systemctl is-active --quiet hpanel-agent

log "[7/7] Running agent connectivity and TLS health check..."
execute ${PHP_BIN} artisan hpanel:agent-check

# 16. Record success and prune older releases
FAILED_STEP="post-deploy finalize"
DURATION=$(( $(date +%s) - START_TIME ))
log "Deployment succeeded in ${DURATION}s."

if [ "$DRY_RUN" = false ]; then
    # Record success in database
    ${PHP_BIN} artisan deploy:record-status \
        --status="success" \
        --commit="${TARGET_COMMIT}" \
        --action="deploy" \
        --duration="${DURATION}" 2>/dev/null || true

    # Write current release file
    cat << EOF > "${CURRENT_RELEASE_FILE}"
{
    "commit": "${TARGET_COMMIT}",
    "message": "$(git log -1 --pretty=%s | tr -d '"\n')",
    "author": "$(git log -1 --pretty=%an | tr -d '"\n')",
    "deployed_at": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")",
    "status": "success"
}
EOF

    # Prune releases older than the last 5
    log "Pruning releases older than the last 5..."
    find "${RELEASES_DIR}" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' 2>/dev/null \
        | sort -nr \
        | tail -n +6 \
        | awk '{print $2}' \
        | xargs -r rm -rf || true

    # Clean up trigger and pending files
    rm -f "${TRIGGER_FILE}" "${ORIGIN_STATUS_FILE}" 2>/dev/null || true
fi

log "======================================================================"
log "Deployment to commit '${TARGET_COMMIT}' completed successfully."
log "======================================================================"
exit 0
