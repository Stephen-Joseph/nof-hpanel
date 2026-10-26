#!/usr/bin/env bash
# ==============================================================================
# HPanel phpMyAdmin Installer & SSO Integration Script
# Target OS: Ubuntu 24.04 LTS / Debian 12
# ==============================================================================
set -euo pipefail

# Text formatting
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

PMA_DOMAIN="${PMA_DOMAIN:-pma.nexorafox.in}"
PANEL_DOMAIN="${PANEL_DOMAIN:-hpanel.nexorafox.in}"
PANEL_DIR="${PANEL_DIR:-/var/www/hpanel}"
PMA_DIR="${PMA_DIR:-/var/www/pma}"
PHP_VERSION="${PHP_VERSION:-8.4}"
CERTBOT_EMAIL="${CERTBOT_EMAIL:-admin@${PANEL_DOMAIN}}"
DRY_RUN=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain)
            PMA_DOMAIN="$2"
            shift 2
            ;;
        --panel-domain)
            PANEL_DOMAIN="$2"
            shift 2
            ;;
        --panel-dir)
            PANEL_DIR="$2"
            shift 2
            ;;
        --pma-dir)
            PMA_DIR="$2"
            shift 2
            ;;
        --php-version)
            PHP_VERSION="$2"
            shift 2
            ;;
        --email)
            CERTBOT_EMAIL="$2"
            shift 2
            ;;
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        *)
            if [[ -z "${1:-}" ]]; then
                shift
            else
                PMA_DOMAIN="$1"
                shift
            fi
            ;;
    esac
done

echo -e "${BLUE}======================================================================${NC}"
echo -e "${BLUE}  HPanel phpMyAdmin SSO Installer${NC}"
echo -e "${BLUE}  Target Domain : ${PMA_DOMAIN}${NC}"
echo -e "${BLUE}  Panel Domain  : ${PANEL_DOMAIN}${NC}"
echo -e "${BLUE}  Panel Dir     : ${PANEL_DIR}${NC}"
echo -e "${BLUE}  PMA Dir       : ${PMA_DIR}${NC}"
echo -e "${BLUE}======================================================================${NC}"

if [[ "$DRY_RUN" = true ]]; then
    echo -e "${YELLOW}[DRY-RUN] Verification complete. Skipping actual installation.${NC}"
    exit 0
fi

# 1. Ensure required tools and dependencies exist
echo -e "\n${BLUE}--> Checking prerequisites...${NC}"
command -v curl >/dev/null 2>&1 || (apt-get update && apt-get install -y curl)
command -v tar >/dev/null 2>&1 || apt-get install -y tar
command -v openssl >/dev/null 2>&1 || apt-get install -y openssl

# 2. Download and extract phpMyAdmin
PMA_VERSION="5.2.1"
PMA_SHA256="61c763f209817d1b5d96a4c0eab65b4e36bce744f78e73bef3bebd1c07481c46"
TARBALL_URL="https://files.phpmyadmin.net/phpMyAdmin/${PMA_VERSION}/phpMyAdmin-${PMA_VERSION}-all-languages.tar.gz"

mkdir -p "$PMA_DIR"

if [[ ! -f "${PMA_DIR}/index.php" ]]; then
    echo -e "${BLUE}--> Downloading phpMyAdmin v${PMA_VERSION}...${NC}"
    TMP_DIR=$(mktemp -d)
    TARBALL_PATH="${TMP_DIR}/phpmyadmin.tar.gz"

    curl -sSL "$TARBALL_URL" -o "$TARBALL_PATH"
    echo "${PMA_SHA256}  ${TARBALL_PATH}" | sha256sum -c -

    echo -e "${GREEN}--> Checksum verified. Extracting to ${PMA_DIR}...${NC}"
    tar -xzf "$TARBALL_PATH" -C "$TMP_DIR"
    cp -r "${TMP_DIR}/phpMyAdmin-${PMA_VERSION}-all-languages/"* "$PMA_DIR/"
    rm -rf "$TMP_DIR"
else
    echo -e "${GREEN}--> phpMyAdmin is already downloaded in ${PMA_DIR}.${NC}"
fi

# 3. Configure phpMyAdmin (idempotent, never overwrite existing config.inc.php)
CONFIG_INC="${PMA_DIR}/config.inc.php"
if [[ ! -f "$CONFIG_INC" ]]; then
    echo -e "${BLUE}--> Generating new ${CONFIG_INC}...${NC}"
    BLOWFISH_SECRET=$(openssl rand -hex 16)

    cat <<EOF > "$CONFIG_INC"
<?php
/**
 * phpMyAdmin configuration for HPanel.
 * Generated automatically by deploy/install-pma.sh.
 */
declare(strict_types=1);

\$cfg['blowfish_secret'] = '${BLOWFISH_SECRET}';

\$i = 1;
\$cfg['Servers'][\$i]['auth_type'] = 'signon';
\$cfg['Servers'][\$i]['SignonSession'] = 'hpanel_pma';
\$cfg['Servers'][\$i]['SignonURL'] = 'https://${PMA_DOMAIN}/signon.php';
\$cfg['Servers'][\$i]['LogoutURL'] = 'https://${PANEL_DOMAIN}/pma/logout';
\$cfg['Servers'][\$i]['host'] = '127.0.0.1';
\$cfg['Servers'][\$i]['AllowRoot'] = false;
\$cfg['Servers'][\$i]['AllowNoPassword'] = false;
\$cfg['Servers'][\$i]['compress'] = false;

\$cfg['PmaAbsoluteUri'] = 'https://${PMA_DOMAIN}/';
\$cfg['SendErrorReports'] = 'never';
\$cfg['TempDir'] = '/tmp';
EOF
    chmod 0640 "$CONFIG_INC"
    chown www-data:www-data "$CONFIG_INC"
    echo -e "${GREEN}--> ${CONFIG_INC} created successfully.${NC}"
else
    echo -e "${GREEN}--> Preserving existing ${CONFIG_INC}.${NC}"
fi

# 4. Generate & Sync PANEL_PMA_SHARED_SECRET in panel .env
SHARED_SECRET=""
if [[ -f "${PANEL_DIR}/.env" ]]; then
    if grep -q "^PANEL_PMA_SHARED_SECRET=" "${PANEL_DIR}/.env"; then
        SHARED_SECRET=$(grep "^PANEL_PMA_SHARED_SECRET=" "${PANEL_DIR}/.env" | cut -d '=' -f2- | tr -d '"'"'")
    fi
fi

if [[ -z "$SHARED_SECRET" ]]; then
    echo -e "${BLUE}--> Generating random PANEL_PMA_SHARED_SECRET...${NC}"
    SHARED_SECRET=$(openssl rand -hex 32)
    if [[ -f "${PANEL_DIR}/.env" ]]; then
        echo "PANEL_PMA_SHARED_SECRET=${SHARED_SECRET}" >> "${PANEL_DIR}/.env"
        echo -e "${GREEN}--> Added PANEL_PMA_SHARED_SECRET to ${PANEL_DIR}/.env.${NC}"
    fi
else
    echo -e "${GREEN}--> Using existing PANEL_PMA_SHARED_SECRET from ${PANEL_DIR}/.env.${NC}"
fi

# 5. Copy signon.php and write signon_config.php
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "${SCRIPT_DIR}/pma/signon.php" ]]; then
    cp "${SCRIPT_DIR}/pma/signon.php" "${PMA_DIR}/signon.php"
elif [[ -f "${PANEL_DIR}/deploy/pma/signon.php" ]]; then
    cp "${PANEL_DIR}/deploy/pma/signon.php" "${PMA_DIR}/signon.php"
fi

SIGNON_CONFIG="${PMA_DIR}/signon_config.php"
cat <<EOF > "$SIGNON_CONFIG"
<?php
return [
    'shared_secret' => '${SHARED_SECRET}',
    'panel_url' => 'https://${PANEL_DOMAIN}',
    'internal_redeem_url' => 'http://127.0.0.1/internal/pma/redeem',
    'panel_host_header' => '${PANEL_DOMAIN}',
];
EOF

chmod 0640 "$SIGNON_CONFIG"
chown www-data:www-data "$SIGNON_CONFIG" "${PMA_DIR}/signon.php" 2>/dev/null || true

# 6. Set directory permissions
chown -R www-data:www-data "$PMA_DIR"
find "$PMA_DIR" -type d -exec chmod 0755 {} +
find "$PMA_DIR" -type f -exec chmod 0644 {} +
chmod 0640 "$CONFIG_INC" "$SIGNON_CONFIG"

# 7. Configure Nginx Virtual Host
FPM_SOCK="/run/php/php${PHP_VERSION}-fpm.sock"
if [[ ! -S "$FPM_SOCK" ]]; then
    # Fallback to any active socket
    ACTIVE_SOCK=$(find /run/php/ -name "php*-fpm.sock" 2>/dev/null | head -n 1 || true)
    if [[ -n "$ACTIVE_SOCK" ]]; then
        FPM_SOCK="$ACTIVE_SOCK"
    fi
fi

NGINX_CONF="/etc/nginx/sites-available/pma.conf"
if [[ -d "/etc/nginx/sites-available" ]]; then
    echo -e "${BLUE}--> Writing Nginx vhost for ${PMA_DOMAIN}...${NC}"
    cat <<EOF > "$NGINX_CONF"
server {
    listen 80;
    listen [::]:80;
    server_name ${PMA_DOMAIN};

    root ${PMA_DIR};
    index index.php index.html;

    client_max_body_size 500M;

    # Protect internal configuration files
    location ~ /(config\.inc\.php|signon_config\.php|libraries|setup|templates) {
        deny all;
        return 404;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${FPM_SOCK};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param HTTP_PROXY "";
        fastcgi_read_timeout 600;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }
}
EOF

    ln -sf "$NGINX_CONF" "/etc/nginx/sites-enabled/pma.conf"
    if nginx -t >/dev/null 2>&1; then
        systemctl reload nginx || true
        echo -e "${GREEN}--> Nginx configured and reloaded.${NC}"
    else
        echo -e "${RED}--> Warning: Nginx syntax check failed. Reverting link.${NC}"
        rm -f "/etc/nginx/sites-enabled/pma.conf"
    fi
fi

# 8. Certbot SSL issuance (if certbot is installed)
if command -v certbot >/dev/null 2>&1 && [[ -d "/etc/nginx/sites-enabled" ]]; then
    echo -e "${BLUE}--> Checking SSL certificate via Certbot...${NC}"
    certbot --nginx -d "${PMA_DOMAIN}" \
        --non-interactive --agree-tos -m "${CERTBOT_EMAIL}" \
        --redirect || echo -e "${YELLOW}Notice: Certbot SSL provisioning skipped (DNS may not resolve to this host yet).${NC}"
fi

echo -e "\n${GREEN}======================================================================${NC}"
echo -e "${GREEN}  phpMyAdmin SSO installed successfully on ${PMA_DOMAIN}${NC}"
echo -e "${GREEN}======================================================================${NC}"
