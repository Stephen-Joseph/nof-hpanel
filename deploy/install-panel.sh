#!/usr/bin/env bash
# ==============================================================================
# HPanel Controller (Panel Master) Installer Script
# Target OS: Ubuntu 24.04 LTS / Debian 12
# ==============================================================================
set -euo pipefail

# Text formatting
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

DRY_RUN=false
PANEL_DOMAIN="panel.example.com"
DB_NAME="hpanel"
DB_USER="hpanel"
DB_PASS="$(openssl rand -hex 16 2>/dev/null || echo 'SecretDBPass123!')"
APP_DIR="/var/www/hpanel"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --domain)
            PANEL_DOMAIN="$2"
            shift 2
            ;;
        --db-name)
            DB_NAME="$2"
            shift 2
            ;;
        --db-user)
            DB_USER="$2"
            shift 2
            ;;
        --db-pass)
            DB_PASS="$2"
            shift 2
            ;;
        --dir)
            APP_DIR="$2"
            shift 2
            ;;
        --help|-h)
            echo "Usage: $0 [OPTIONS]"
            echo "Options:"
            echo "  --dry-run         Simulate panel installation"
            echo "  --domain DOMAIN   FQDN for panel interface (e.g. panel.example.com)"
            echo "  --db-name NAME    Database name (default: hpanel)"
            echo "  --db-user USER    Database username (default: hpanel)"
            echo "  --db-pass PASS    Database password (default: random 32-char hex)"
            echo "  --dir PATH        Target application directory (default: /var/www/hpanel)"
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown option: $1${NC}"
            exit 1
            ;;
    esac
done

execute() {
    if [ "$DRY_RUN" = true ]; then
        echo -e "${YELLOW}[DRY-RUN] Would execute:${NC} $*"
    else
        "$@"
    fi
}

echo -e "${BLUE}"
echo "=============================================================="
echo "         HPANEL CONTROLLER (MASTER) INSTALLER                 "
echo "=============================================================="
echo -e "${NC}"

if [ "$EUID" -ne 0 ] && [ "$DRY_RUN" = false ]; then
    echo -e "${RED}Error: Installer must be run as root (use sudo).${NC}"
    exit 1
fi

# 1. Repositories & Base Packages
echo -e "${BLUE}[1/6] Installing PHP 8.4, Composer, MariaDB, and Redis...${NC}"

execute apt-get update -y
execute apt-get install -y curl git unzip software-properties-common ca-certificates lsb-release

# Add Ondřej Surý PHP repository
if command -v add-apt-repository &>/dev/null; then
    execute add-apt-repository -y ppa:ondrej/php
fi

execute apt-get update -y
execute apt-get install -y \
    nginx \
    mariadb-server \
    redis-server \
    php8.4-fpm \
    php8.4-cli \
    php8.4-common \
    php8.4-mysql \
    php8.4-mbstring \
    php8.4-xml \
    php8.4-curl \
    php8.4-zip \
    php8.4-bcmath \
    php8.4-intl \
    php8.4-opcache \
    php8.4-redis \
    certbot \
    python3-certbot-nginx

# Install Composer globally if not present
if ! command -v composer &>/dev/null; then
    echo -e "${BLUE}Installing Composer...${NC}"
    curl -sS https://getcomposer.org/installer | execute php -- --install-dir=/usr/local/bin --filename=composer
fi

# 2. Database & User Provisioning
echo -e "${BLUE}[2/6] Configuring MariaDB panel database...${NC}"
if [ "$DRY_RUN" = false ]; then
    mariadb -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mariadb -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
    mariadb -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
    mariadb -e "FLUSH PRIVILEGES;"
    echo -e "${GREEN}✓ Database '${DB_NAME}' and user '${DB_USER}' configured.${NC}"
else
    echo -e "${YELLOW}[DRY-RUN] Would create database '${DB_NAME}' and grant privileges.${NC}"
fi

# 3. Application Deployment & Migrations
echo -e "${BLUE}[3/6] Deploying application and running database migrations...${NC}"
execute mkdir -p "$APP_DIR"
execute chown -R www-data:www-data "$APP_DIR"

if [ -f "${APP_DIR}/composer.json" ]; then
    cd "$APP_DIR"
    execute sudo -u www-data composer install --no-dev --optimize-autoloader --no-interaction
    
    if [ ! -f "${APP_DIR}/.env" ] && [ -f "${APP_DIR}/.env.example" ]; then
        execute cp "${APP_DIR}/.env.example" "${APP_DIR}/.env"
        execute sed -i "s/APP_ENV=local/APP_ENV=production/" "${APP_DIR}/.env"
        execute sed -i "s/APP_DEBUG=true/APP_DEBUG=false/" "${APP_DIR}/.env"
        execute sed -i "s/DB_DATABASE=.*/DB_DATABASE=${DB_NAME}/" "${APP_DIR}/.env"
        execute sed -i "s/DB_USERNAME=.*/DB_USERNAME=${DB_USER}/" "${APP_DIR}/.env"
        execute sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=${DB_PASS}/" "${APP_DIR}/.env"
        execute sed -i "s/QUEUE_CONNECTION=.*/QUEUE_CONNECTION=redis/" "${APP_DIR}/.env"
        execute sed -i "s/CACHE_STORE=.*/CACHE_STORE=redis/" "${APP_DIR}/.env"
        execute sudo -u www-data php artisan key:generate --force
    fi

    execute sudo -u www-data php artisan migrate --force
    execute sudo -u www-data php artisan config:cache
    execute sudo -u www-data php artisan route:cache
    execute sudo -u www-data php artisan view:cache
fi

# 4. Systemd Background Worker
echo -e "${BLUE}[4/6] Setting up background queue workers (hpanel-queue.service)...${NC}"
cat << EOF > /tmp/hpanel-queue.service
[Unit]
Description=HPanel Background Queue Worker
After=network.target mariadb.service redis.service

[Service]
Type=simple
User=www-data
Group=www-data
Restart=always
RestartSec=3s
ExecStart=/usr/bin/php ${APP_DIR}/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
EOF

execute cp /tmp/hpanel-queue.service /etc/systemd/system/hpanel-queue.service
execute rm -f /tmp/hpanel-queue.service
execute systemctl daemon-reload
execute systemctl enable --now hpanel-queue.service

# 5. Cron Scheduler
echo -e "${BLUE}[5/6] Registering Laravel Task Scheduler in cron...${NC}"
CRON_LINE="* * * * * www-data cd ${APP_DIR} && /usr/bin/php artisan schedule:run >> /dev/null 2>&1"
if [ "$DRY_RUN" = false ]; then
    echo "$CRON_LINE" > /etc/cron.d/hpanel-scheduler
    chmod 644 /etc/cron.d/hpanel-scheduler
else
    echo -e "${YELLOW}[DRY-RUN] Would create /etc/cron.d/hpanel-scheduler:${NC} $CRON_LINE"
fi

# 6. Nginx Virtual Host
echo -e "${BLUE}[6/6] Configuring Nginx virtual host for ${PANEL_DOMAIN}...${NC}"
cat << EOF > /tmp/hpanel-nginx.conf
server {
    listen 80;
    listen [::]:80;
    server_name ${PANEL_DOMAIN};
    root ${APP_DIR}/public;

    add_header X-Frame-Options "DENY";
    add_header X-Content-Type-Options "nosniff";
    add_header X-XSS-Protection "1; mode=block";
    add_header Referrer-Policy "strict-origin-when-cross-origin";

    index index.php;
    charset utf-8;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF

execute cp /tmp/hpanel-nginx.conf "/etc/nginx/sites-available/${PANEL_DOMAIN}"
execute rm -f /tmp/hpanel-nginx.conf
execute ln -sf "/etc/nginx/sites-available/${PANEL_DOMAIN}" "/etc/nginx/sites-enabled/${PANEL_DOMAIN}"
execute nginx -t || true
execute systemctl reload nginx || true

echo -e "${GREEN}"
echo "=============================================================="
echo "        HPANEL CONTROLLER SETUP COMPLETED SUCCESSFULLY        "
echo "=============================================================="
echo -e "${NC}"
echo "URL:             http://${PANEL_DOMAIN}"
echo "Database:        ${DB_NAME}"
echo "Queue Worker:    hpanel-queue.service"
echo "Task Scheduler:  /etc/cron.d/hpanel-scheduler"

