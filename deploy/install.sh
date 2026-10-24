#!/usr/bin/env bash
# =============================================================================
# HPanel installer for the PANEL server
# Supports: Ubuntu 24.04 LTS, Debian 12, Debian 13
#
# Run:
#   sudo PANEL_DOMAIN=panel.example.com ADMIN_EMAIL=you@example.com \
#        REPO_URL=https://github.com/you/hpanel.git bash install.sh
#
# Flags (instead of env vars):
#   --domain, --email, --repo, --branch, --dir, --php, --ssh-port
#   --dry-run, --no-progress, -q/--quiet
#
# Safe to re-run. An existing .env, database and admin are not overwritten.
# =============================================================================
set -Eeuo pipefail

# ---- Settings (env vars or flags) -------------------------------------------
PANEL_DOMAIN="${PANEL_DOMAIN:-}"
ADMIN_EMAIL="${ADMIN_EMAIL:-}"
REPO_URL="${REPO_URL:-}"
BRANCH="${BRANCH:-main}"
APP_DIR="${APP_DIR:-/var/www/hpanel}"
PHP_VERSION="${PHP_VERSION:-8.4}"
SSH_PORT="${SSH_PORT:-22}"
DB_NAME="${DB_NAME:-hpanel}"
DB_USER="${DB_USER:-hpanel}"
DB_PASS="${DB_PASS:-$(openssl rand -hex 24)}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-$(openssl rand -base64 18 | tr -d '/+=')}"
DRY_RUN=false
NO_PROGRESS=false
QUIET=false
CREDS_FILE="/root/hpanel-install.txt"
LOG_FILE="/var/log/hpanel-install.log"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain)      PANEL_DOMAIN="$2"; shift 2 ;;
    --email)       ADMIN_EMAIL="$2"; shift 2 ;;
    --repo)        REPO_URL="$2"; shift 2 ;;
    --branch)      BRANCH="$2"; shift 2 ;;
    --dir)         APP_DIR="$2"; shift 2 ;;
    --php)         PHP_VERSION="$2"; shift 2 ;;
    --ssh-port)    SSH_PORT="$2"; shift 2 ;;
    --dry-run)     DRY_RUN=true; shift ;;
    --no-progress) NO_PROGRESS=true; shift ;;
    -q|--quiet)    QUIET=true; shift ;;
    -h|--help)     sed -n '2,16p' "$0"; exit 0 ;;
    *) echo "Unknown option: $1" >&2; exit 1 ;;
  esac
done

[[ -n $PANEL_DOMAIN && -n $ADMIN_EMAIL ]] || {
  echo "PANEL_DOMAIN and ADMIN_EMAIL are required (env vars or --domain/--email)." >&2
  exit 1
}

if [[ $EUID -ne 0 ]]; then
  if [[ $DRY_RUN == true ]]; then
    LOG_FILE="/tmp/hpanel-install.log"
  else
    echo "Run as root: sudo bash $0" >&2
    exit 1
  fi
fi

mkdir -p "$(dirname "$LOG_FILE")"
printf '\n===== HPanel install started %s =====\n' "$(date)" >>"$LOG_FILE"

PHP_BIN="/usr/bin/php${PHP_VERSION}"
COMPOSER_BIN="/usr/local/bin/composer"
ENV_FILE="$APP_DIR/.env"
NGINX_SITE="/etc/nginx/sites-available/hpanel"
PHP_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
export DEBIAN_FRONTEND=noninteractive
export COMPOSER_ALLOW_SUPERUSER=1

STEPS=(
  "System check"
  "Base packages"
  "PHP ${PHP_VERSION} runtime"
  "Composer"
  "Application code & dependencies"
  "Environment file"
  "Database & migrations"
  "Caches & assets"
  "Nginx site"
  "HTTPS certificate"
  "Firewall & Fail2ban"
  "Queue worker & scheduler"
  "Final checks"
)
TOTAL=${#STEPS[@]}

# ---- State ------------------------------------------------------------------
CUR=0; PCT=0; STEP_NAME=""; STEP_T0=0; SPIN_PID=""
SCRIPT_T0=$(date +%s)
FAIL_MSG=""; NEW_ENV=false
WARNINGS=()
RES_NAME=(); RES_TIME=(); RES_STAT=()

# ---- Colours (real ESC bytes via $'...') -------------------------------------
if [[ -t 1 && $NO_PROGRESS == false && $QUIET == false ]]; then
  IS_TTY=true
  C_RESET=$'\033[0m'; C_BOLD=$'\033[1m'; C_BLUE=$'\033[38;5;39m'
  C_GREEN=$'\033[38;5;48m'; C_YELLOW=$'\033[38;5;220m'
  C_RED=$'\033[38;5;196m'; C_MUTED=$'\033[38;5;244m'
else
  IS_TTY=false
  C_RESET=""; C_BOLD=""; C_BLUE=""; C_GREEN=""; C_YELLOW=""; C_RED=""; C_MUTED=""
fi

# ---- Helpers ----------------------------------------------------------------
say()  { [[ $QUIET == true ]] || printf '%s\n' "$*"; }
log()  { printf '%s\n' "$*" >>"$LOG_FILE"; }
warn() { WARNINGS+=("$*"); log "WARN: $*"; }
fail() { FAIL_MSG="$*"; log "ERROR: $*"; return 1; }

fmt() { printf '%02d:%02d' $(($1 / 60)) $(($1 % 60)); }

# Run a command, send all output to the log. Does nothing in dry-run.
run() {
  log "\$ $*"
  if [[ $DRY_RUN == true ]]; then return 0; fi
  "$@" >>"$LOG_FILE" 2>&1
}

apt_install() { run apt-get install -y -o Dpkg::Options::=--force-confold "$@"; }

artisan() { run "$PHP_BIN" "$APP_DIR/artisan" "$@"; }

# Read file content from stdin and write it to $1 (skipped in dry-run)
write_file() {
  if [[ $DRY_RUN == true ]]; then
    cat >/dev/null
    log "[dry-run] would write $1"
    return 0
  fi
  mkdir -p "$(dirname "$1")"
  cat >"$1"
  log "wrote $1"
}

draw_bar() { # $1 pct  $2 cur  $3 total  $4 label  $5 seconds  $6 detail
  local pct=$1 filled i bar="" label=$4
  filled=$(( pct * 20 / 100 ))
  for ((i = 0; i < 20; i++)); do
    if (( i < filled )); then bar+="#"; else bar+="-"; fi
  done
  if (( ${#label} > 34 )); then label="${label:0:31}..."; fi
  printf '\r\033[K%s[%s]%s %s%3d%%%s (%s/%s) %-34s %s%s%s' \
    "$C_BLUE" "$bar" "$C_RESET" "$C_BOLD" "$pct" "$C_RESET" \
    "$2" "$3" "$label" "$C_MUTED" "$(fmt "$5")" "$C_RESET"
  if [[ -n ${6:-} ]]; then printf '  %s%s%s' "$C_MUTED" "${6:0:40}" "$C_RESET"; fi
}

spinner_loop() {
  set +e
  local frames='|/-\' i=0 now detail
  while :; do
    now=$(date +%s)
    detail=$(tail -n 1 "$LOG_FILE" 2>/dev/null | tr -cd '[:print:]' | tr -s ' ')
    draw_bar "$PCT" "$CUR" "$TOTAL" "${frames:$((i % 4)):1} $STEP_NAME" \
      $((now - STEP_T0)) "$detail"
    i=$((i + 1))
    sleep 0.2
  done
}

stop_spinner() {
  if [[ -n $SPIN_PID ]]; then
    kill "$SPIN_PID" 2>/dev/null || true
    wait "$SPIN_PID" 2>/dev/null || true
    SPIN_PID=""
  fi
  if [[ $IS_TTY == true ]]; then printf '\033[?25h'; fi
}

step_begin() {
  CUR=$((CUR + 1))
  STEP_NAME="${STEPS[$((CUR - 1))]}"
  STEP_T0=$(date +%s)
  PCT=$(( (CUR - 1) * 100 / TOTAL ))
  log "--- [$CUR/$TOTAL] $STEP_NAME"
  if [[ $IS_TTY == true ]]; then
    printf '\033[?25l'
    spinner_loop &
    SPIN_PID=$!
  else
    say "[$CUR/$TOTAL] $STEP_NAME..."
  fi
}

step_end() {
  local status=${1:-ok} el=$(( $(date +%s) - STEP_T0 ))
  stop_spinner
  PCT=$(( CUR * 100 / TOTAL ))
  RES_NAME+=("$STEP_NAME"); RES_TIME+=("$el"); RES_STAT+=("$status")
  log "--- done: $STEP_NAME ($status, $(fmt "$el"))"
  if [[ $QUIET == true ]]; then return 0; fi
  if [[ $IS_TTY == true ]]; then
    draw_bar "$PCT" "$CUR" "$TOTAL" "$STEP_NAME" "$el" ""
    if [[ $status == ok ]]; then
      printf '  %s[OK]%s\n' "$C_GREEN" "$C_RESET"
    else
      printf '  %s[FAIL]%s\n' "$C_RED" "$C_RESET"
    fi
  else
    say "    done ($(fmt "$el")) [$status]"
  fi
}

on_error() {
  local code=$1 line=$2
  stop_spinner
  printf '\n%s======================================================================%s\n' "$C_RED" "$C_RESET"
  printf '%s  INSTALLATION FAILED%s\n' "$C_BOLD$C_RED" "$C_RESET"
  printf '======================================================================\n'
  printf 'Failed step : %s\n' "${STEP_NAME:-initialisation}"
  printf 'Reason      : %s\n' "${FAIL_MSG:-command failed (exit $code, script line $line)}"
  printf 'Log file    : %s\n' "$LOG_FILE"
  printf -- '----- last 20 log lines -----\n'
  tail -n 20 "$LOG_FILE" 2>/dev/null | sed 's/^/  | /' || true
  printf '======================================================================\n'
  printf 'Fix the problem and run the same command again. The installer is safe to repeat.\n'
  exit "$code"
}

print_summary() {
  local total=$(( $(date +%s) - SCRIPT_T0 )) i
  say ""
  say "======================================================================"
  say "  HPANEL INSTALLATION SUMMARY"
  say "======================================================================"
  printf ' %-3s | %-34s | %-8s | %s\n' "#" "Step" "Time" "Status"
  say "-----+------------------------------------+----------+--------"
  for i in "${!RES_NAME[@]}"; do
    printf ' %-3d | %-34s | %-8s | %s\n' "$((i + 1))" "${RES_NAME[$i]:0:34}" \
      "$(fmt "${RES_TIME[$i]}")" "${RES_STAT[$i]}"
  done
  say "-----+------------------------------------+----------+--------"
  say "Total time: $(fmt "$total")"
  if (( ${#WARNINGS[@]} > 0 )); then
    say ""
    say "Warnings (fix these):"
    for w in "${WARNINGS[@]}"; do say "  - $w"; done
  fi
  say ""
  say "Panel URL:    https://${PANEL_DOMAIN}"
  say "Admin email:  ${ADMIN_EMAIL}"
  say "Credentials:  ${CREDS_FILE} (root only)"
  say "Log file:     ${LOG_FILE}"
}

trap 'on_error $? $LINENO' ERR
trap 'stop_spinner' EXIT

if [[ $QUIET == false ]]; then
  printf '%s%s' "$C_BLUE" "$C_BOLD"
  echo "  HPanel Cloud Hosting Control Panel Installer"
  printf '%s' "$C_RESET"
  echo "  Domain: ${PANEL_DOMAIN}   Dir: ${APP_DIR}   PHP: ${PHP_VERSION}"
  [[ $DRY_RUN == true ]] && echo "  [DRY-RUN: no system changes will be made]"
  echo ""
fi

# =============================================================================
step_begin
# 1. System check
. /etc/os-release
OS_ID=$ID
OS_MAJOR=${VERSION_ID%%.*}
case "$OS_ID:$OS_MAJOR" in
  ubuntu:24|debian:12|debian:13) ;;
  *) fail "Unsupported OS: ${PRETTY_NAME:-unknown}. Use Ubuntu 24.04, Debian 12 or Debian 13." ;;
esac
log "OS: $PRETTY_NAME ($VERSION_CODENAME)"
FREE_GB=$(df -BG --output=avail / | tail -n 1 | tr -dc '0-9')
if (( FREE_GB < 5 )); then warn "Only ${FREE_GB}GB free on /. At least 5GB is recommended."; fi
step_end ok

# =============================================================================
step_begin
# 2. Base packages
run apt-get update -y
apt_install ca-certificates curl git unzip openssl gnupg lsb-release \
  nginx mariadb-server redis-server certbot python3-certbot-nginx ufw fail2ban
run systemctl enable --now nginx mariadb redis-server
step_end ok

# =============================================================================
step_begin
# 3. PHP runtime
if [[ $DRY_RUN == false ]] && ! apt-cache show "php${PHP_VERSION}-fpm" >/dev/null 2>&1; then
  log "php${PHP_VERSION} not in default repos. Adding third-party repository."
  if [[ $OS_ID == ubuntu ]]; then
    apt_install software-properties-common
    run add-apt-repository -y ppa:ondrej/php
  else
    run curl -fsSL -o /tmp/sury-keyring.deb https://packages.sury.org/debsuryorg-archive-keyring.deb
    run dpkg -i /tmp/sury-keyring.deb
    echo "deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ ${VERSION_CODENAME} main" \
      | write_file /etc/apt/sources.list.d/sury-php.list
    run apt-get update -y
  fi
fi

PHP_PKGS=()
for p in fpm cli common mysql mbstring xml curl zip bcmath intl gd opcache redis; do
  PHP_PKGS+=("php${PHP_VERSION}-${p}")
done
apt_install "${PHP_PKGS[@]}"
run systemctl enable --now "php${PHP_VERSION}-fpm"
step_end ok

# =============================================================================
step_begin
# 4. Composer (installed with the target PHP version)
if [[ ! -x $COMPOSER_BIN ]]; then
  run curl -fsSL -o /tmp/composer-setup.php https://getcomposer.org/installer
  run "$PHP_BIN" /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  if [[ $DRY_RUN == false ]]; then rm -f /tmp/composer-setup.php; fi
fi
step_end ok

# =============================================================================
step_begin
# 5. Application code & dependencies
run git config --global --add safe.directory "$APP_DIR"
if [[ -d $APP_DIR/.git ]]; then
  run git -C "$APP_DIR" fetch --all --prune
  run git -C "$APP_DIR" checkout "$BRANCH"
  run git -C "$APP_DIR" reset --hard "origin/$BRANCH"
elif [[ -f $APP_DIR/artisan ]]; then
  log "Using existing application at $APP_DIR (not a git checkout)"
else
  if [[ $DRY_RUN == false && -z $REPO_URL ]]; then
    fail "No application at $APP_DIR and REPO_URL is not set."
  fi
  run mkdir -p "$(dirname "$APP_DIR")"
  run git clone --branch "$BRANCH" "$REPO_URL" "$APP_DIR"
fi

if [[ $DRY_RUN == false && ! -f $APP_DIR/composer.json ]]; then
  fail "composer.json not found in $APP_DIR. The Laravel app must be at the root of the repo."
fi
run "$PHP_BIN" "$COMPOSER_BIN" --working-dir="$APP_DIR" install \
  --no-dev --optimize-autoloader --no-interaction --no-progress
step_end ok

# =============================================================================
step_begin
# 6. Environment file (only created on first run)
if [[ $DRY_RUN == false && ! -f $ENV_FILE ]]; then
  [[ -f $APP_DIR/.env.example ]] || fail ".env.example missing in $APP_DIR"
  cp "$APP_DIR/.env.example" "$ENV_FILE"
  NEW_ENV=true
fi

if [[ $DRY_RUN == false && $NEW_ENV == false ]]; then
  existing_pass=$(grep -E '^DB_PASSWORD=' "$ENV_FILE" | head -n 1 | cut -d= -f2- || true)
  if [[ -n $existing_pass ]]; then DB_PASS=$existing_pass; fi
fi

set_env() {
  local key=$1 val=$2
  if [[ $DRY_RUN == true ]]; then return 0; fi
  if grep -q "^${key}=" "$ENV_FILE"; then
    sed -i "s|^${key}=.*|${key}=${val}|" "$ENV_FILE"
  else
    echo "${key}=${val}" >>"$ENV_FILE"
  fi
}

set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "https://${PANEL_DOMAIN}"
set_env DB_CONNECTION mysql
set_env DB_HOST 127.0.0.1
set_env DB_PORT 3306
set_env DB_DATABASE "$DB_NAME"
set_env DB_USERNAME "$DB_USER"
if [[ $NEW_ENV == true ]]; then set_env DB_PASSWORD "$DB_PASS"; fi
set_env QUEUE_CONNECTION database
set_env SESSION_DRIVER database
set_env CACHE_STORE redis
set_env REDIS_HOST 127.0.0.1

if [[ $DRY_RUN == false ]] && ! grep -Eq '^APP_KEY=base64:' "$ENV_FILE"; then
  artisan key:generate --force
fi
run chgrp www-data "$ENV_FILE"
run chmod 640 "$ENV_FILE"
step_end ok

# =============================================================================
step_begin
# 7. Database & migrations
run systemctl enable --now mariadb
if [[ $DRY_RUN == false ]]; then
  mysql -uroot >>"$LOG_FILE" 2>&1 <<SQL || fail "MariaDB setup failed. Check that you can run 'mysql -uroot'."
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
fi

artisan migrate --force

if [[ $DRY_RUN == false ]]; then
  if [[ $NEW_ENV == true ]]; then
    cmds=$(php_list=$("$PHP_BIN" "$APP_DIR/artisan" list --raw 2>/dev/null || true); echo "$php_list")
    if grep -q 'panel:create-admin' <<<"$cmds"; then
      # Password is passed as an argument. The output is discarded so it is not logged.
      if "$PHP_BIN" "$APP_DIR/artisan" panel:create-admin "$ADMIN_EMAIL" "$ADMIN_PASSWORD" >/dev/null 2>&1; then
        log "Admin account created"
      else
        warn "panel:create-admin failed. The admin may already exist."
      fi
    else
      warn "The panel:create-admin command is missing. Create the admin user manually with php artisan tinker."
    fi
    umask 077
    cat >"$CREDS_FILE" <<EOF
Panel URL:      https://${PANEL_DOMAIN}
Admin email:    ${ADMIN_EMAIL}
Admin password: ${ADMIN_PASSWORD}
Database:       ${DB_NAME} / ${DB_USER}
DB password:    stored in ${ENV_FILE}
EOF
    chmod 600 "$CREDS_FILE"
  fi
fi
step_end ok

# =============================================================================
step_begin
# 8. Caches, permissions & frontend assets
artisan storage:link || true
artisan optimize:clear
artisan config:cache
artisan route:cache
artisan view:cache
run chown -R www-data:www-data "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"
run chmod -R ug+rwX "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

if [[ $DRY_RUN == false && -f $APP_DIR/package.json && ! -f $APP_DIR/public/build/manifest.json ]]; then
  if command -v npm >/dev/null 2>&1; then
    (cd "$APP_DIR" && npm ci && npm run build) >>"$LOG_FILE" 2>&1 \
      || warn "Frontend build failed. See the log."
  else
    warn "public/build is missing and npm is not installed. Build locally and commit public/build, or install Node.js 20+ and re-run."
  fi
fi
step_end ok

# =============================================================================
step_begin
# 9. Nginx site
sed -e "s|__DOMAIN__|${PANEL_DOMAIN}|g" \
    -e "s|__ROOT__|${APP_DIR}/public|g" \
    -e "s|__SOCK__|${PHP_SOCK}|g" <<'NGINX' | write_file "$NGINX_SITE"
server {
    listen 80;
    listen [::]:80;
    server_name __DOMAIN__;
    root __ROOT__;
    index index.php;
    client_max_body_size 64M;

    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:__SOCK__;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
NGINX
run ln -sfn "$NGINX_SITE" /etc/nginx/sites-enabled/hpanel
run rm -f /etc/nginx/sites-enabled/default
run nginx -t
run systemctl reload nginx
step_end ok

# =============================================================================
step_begin
# 10. HTTPS (non-fatal: needs DNS pointing at this server)
if [[ $DRY_RUN == true ]]; then
  log "[dry-run] skipping certbot"
elif run certbot --nginx -d "$PANEL_DOMAIN" -m "$ADMIN_EMAIL" --agree-tos -n --redirect; then
  log "HTTPS certificate issued"
else
  warn "HTTPS not enabled. Point DNS for ${PANEL_DOMAIN} at this server, then run: certbot --nginx -d ${PANEL_DOMAIN}"
fi
step_end ok

# =============================================================================
step_begin
# 11. Firewall & Fail2ban
run ufw allow "${SSH_PORT}/tcp"
run ufw allow 80/tcp
run ufw allow 443/tcp
run ufw --force enable
run systemctl enable --now fail2ban
step_end ok

# =============================================================================
step_begin
# 12. Queue worker & scheduler
write_file /etc/systemd/system/hpanel-queue.service <<UNIT
[Unit]
Description=HPanel queue worker
After=network.target mariadb.service redis-server.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=${APP_DIR}
ExecStart=${PHP_BIN} ${APP_DIR}/artisan queue:work --sleep=3 --tries=3 --timeout=900
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
write_file /etc/cron.d/hpanel <<CRON
* * * * * www-data ${PHP_BIN} ${APP_DIR}/artisan schedule:run >> /dev/null 2>&1
CRON
run systemctl daemon-reload
run systemctl enable --now hpanel-queue
step_end ok

# =============================================================================
step_begin
# 13. Final checks
if [[ $DRY_RUN == false ]]; then
  for svc in nginx mariadb redis-server "php${PHP_VERSION}-fpm" hpanel-queue fail2ban; do
    if systemctl is-active --quiet "$svc"; then
      log "service OK: $svc"
    else
      fail "Service is not running: $svc. Check: journalctl -u $svc -n 50"
    fi
  done
  code=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: ${PANEL_DOMAIN}" http://127.0.0.1/ || true)
  log "Local HTTP check returned ${code}"
  case "$code" in
    200|301|302) ;;
    *) warn "The panel returned HTTP ${code} locally. Check ${APP_DIR}/storage/logs/laravel.log" ;;
  esac
fi
step_end ok

print_summary