#!/usr/bin/env bash
# ==============================================================================
# HPanel Node Installer Script
# Target OS: Ubuntu 24.04 LTS (Noble) / Debian 12 (Bookworm)
# ==============================================================================
set -euo pipefail

# Text formatting
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Defaults
DRY_RUN=false
PANEL_URL=""
ENROLLMENT_TOKEN=""
NODE_NAME="$(hostname -f 2>/dev/null || hostname)"
LOCATION="DEFAULT"
PRIVATE_IP=""
AGENT_PORT=8443
SKIP_FIREWALL=false
AGENT_VERSION="0.1.0"
AGENT_BIN_PATH="/usr/local/bin/panel-agent"
CONFIG_DIR="/etc/panel-agent"
CERTS_DIR="/etc/panel-agent/certs"
LOGS_DIR="/var/log/panel-agent"
DATA_DIR="/var/lib/panel-agent"

# Print banner
echo -e "${BLUE}"
echo "=============================================================="
echo "          HPANEL SERVER NODE AUTOMATED INSTALLER              "
echo "=============================================================="
echo -e "${NC}"

# Parse CLI flags
while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --panel-url)
            PANEL_URL="$2"
            shift 2
            ;;
        --enrollment-token)
            ENROLLMENT_TOKEN="$2"
            shift 2
            ;;
        --node-name)
            NODE_NAME="$2"
            shift 2
            ;;
        --private-ip)
            PRIVATE_IP="$2"
            shift 2
            ;;
        --location)
            LOCATION="$2"
            shift 2
            ;;
        --agent-port)
            AGENT_PORT="$2"
            shift 2
            ;;
        --skip-firewall)
            SKIP_FIREWALL=true
            shift
            ;;
        --help|-h)
            echo "Usage: $0 [OPTIONS]"
            echo "Options:"
            echo "  --dry-run               Simulate installation without making system modifications"
            echo "  --panel-url URL         HPanel Controller master URL (e.g. https://panel.example.com)"
            echo "  --enrollment-token TOK  One-time server enrollment token generated from panel"
            echo "  --node-name NAME        Custom node name identifier (default: hostname)"
            echo "  --private-ip IP         WireGuard / private interface IP for agent binding"
            echo "  --location REGION       Geographic / datacenter location tag (e.g. LON-1, NYC-1)"
            echo "  --agent-port PORT       Port for agent service (default: 8443)"
            echo "  --skip-firewall         Skip UFW configuration"
            exit 0
            ;;
        *)
            echo -e "${RED}Unknown argument: $1${NC}"
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

# 1. Environment & Pre-flight Checks
echo -e "${BLUE}[1/8] Verifying system environment...${NC}"

if [ "$EUID" -ne 0 ] && [ "$DRY_RUN" = false ]; then
    echo -e "${RED}Error: Installer must be run as root (use sudo).${NC}"
    exit 1
fi

# Detect OS
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=$ID
    VERSION_ID=${VERSION_ID:-""}
else
    echo -e "${RED}Error: Cannot identify Linux distribution via /etc/os-release.${NC}"
    exit 1
fi

if [[ "$OS" != "ubuntu" && "$OS" != "debian" ]]; then
    echo -e "${RED}Error: Unsupported distribution: $OS. Supported: Ubuntu 24.04+, Debian 12+.${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Operating System verified: $PRETTY_NAME${NC}"

# Detect Private IP if not provided
if [ -z "$PRIVATE_IP" ]; then
    PRIVATE_IP=$(ip -4 route get 8.8.8.8 2>/dev/null | awk '{print $7}' | tr -d '[:space:]' || hostname -I | awk '{print $1}')
    echo -e "${YELLOW}Private IP auto-detected: ${PRIVATE_IP}${NC}"
fi

# 2. Package Repositories Configuration
echo -e "${BLUE}[2/8] Configuring package repositories (PHP PPA, MariaDB)...${NC}"

execute apt-get update -y
execute apt-get install -y lsb-release ca-certificates apt-transport-https software-properties-common gnupg curl jq ufw fail2ban restic quota quotatool

if [ "$OS" == "ubuntu" ]; then
    execute add-apt-repository -y ppa:ondrej/php
    execute add-apt-repository -y ppa:ondrej/nginx-mainline
elif [ "$OS" == "debian" ]; then
    if [ ! -f /etc/apt/trusted.gpg.d/php.gpg ]; then
        curl -sSLo /tmp/debsury.gpg https://packages.sury.org/php/apt.gpg
        execute gpg --dearmor -o /etc/apt/trusted.gpg.d/php.gpg /tmp/debsury.gpg
        execute rm -f /tmp/debsury.gpg
        echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" | execute tee /etc/apt/sources.list.d/sury-php.list
    fi
fi

execute apt-get update -y

# 3. Installing System Dependencies
echo -e "${BLUE}[3/8] Installing Nginx, multi-version PHP-FPM, MariaDB, and Certbot...${NC}"

PHP_VERSIONS=("8.2" "8.3" "8.4")
PHP_PACKAGES=()
for ver in "${PHP_VERSIONS[@]}"; do
    PHP_PACKAGES+=(
        "php${ver}-fpm"
        "php${ver}-cli"
        "php${ver}-common"
        "php${ver}-mysql"
        "php${ver}-mbstring"
        "php${ver}-xml"
        "php${ver}-curl"
        "php${ver}-zip"
        "php${ver}-gd"
        "php${ver}-intl"
        "php${ver}-bcmath"
        "php${ver}-opcache"
        "php${ver}-readline"
    )
done

execute apt-get install -y \
    nginx \
    mariadb-server \
    mariadb-client \
    certbot \
    python3-certbot-nginx \
    "${PHP_PACKAGES[@]}"

echo -e "${GREEN}✓ Core server packages installed successfully.${NC}"

# 4. Enabling Filesystem Quotas
echo -e "${BLUE}[4/8] Initializing disk quota subsystem...${NC}"
execute systemctl enable --now quota.service || true

# 5. Configuring UFW Firewall
if [ "$SKIP_FIREWALL" = false ]; then
    echo -e "${BLUE}[5/8] Configuring UFW firewall rules...${NC}"
    execute ufw --force reset
    execute ufw default deny incoming
    execute ufw default allow outgoing
    execute ufw allow 22/tcp comment 'SSH'
    execute ufw allow 80/tcp comment 'HTTP'
    execute ufw allow 443/tcp comment 'HTTPS'
    
    # Restrict agent port to private subnet / IP if possible
    execute ufw allow from "$PRIVATE_IP/24" to any port "$AGENT_PORT" proto tcp comment 'HPanel Agent Private'
    execute ufw --force enable
    echo -e "${GREEN}✓ Firewall configured (ports 22, 80, 443 public; agent on private subnet).${NC}"
else
    echo -e "${YELLOW}[5/8] Skipping UFW firewall configuration (--skip-firewall specified).${NC}"
fi

# 6. Directory Structure Setup
echo -e "${BLUE}[6/8] Establishing Agent file structure...${NC}"
execute mkdir -p "$CONFIG_DIR" "$CERTS_DIR" "$LOGS_DIR" "$DATA_DIR" "/home"
execute chmod 750 "$CONFIG_DIR" "$CERTS_DIR" "$LOGS_DIR" "$DATA_DIR"

# 7. Installing and Hardening Agent Systemd Service
echo -e "${BLUE}[7/8] Configuring systemd service...${NC}"

cat << 'EOF' > /tmp/hpanel-agent.service
[Unit]
Description=HPanel Server Node Agent
After=network.target nginx.service mariadb.service
Wants=network.target

[Service]
Type=simple
User=root
Group=root
ExecStart=/usr/local/bin/panel-agent -config /etc/panel-agent/config.yaml
Restart=always
RestartSec=5s
LimitNOFILE=65535

# Process Sandboxing & Hardening
ProtectSystem=full
ProtectHome=no
PrivateTmp=true
CapabilityBoundingSet=CAP_NET_BIND_SERVICE CAP_CHOWN CAP_FOWNER CAP_DAC_OVERRIDE CAP_SETUID CAP_SETGID CAP_SYS_RESOURCE
NoNewPrivileges=false

[Install]
WantedBy=multi-user.target
EOF

execute cp /tmp/hpanel-agent.service /etc/systemd/system/hpanel-agent.service
execute rm -f /tmp/hpanel-agent.service
execute systemctl daemon-reload

# 8. Node Enrollment Call
if [ -n "$PANEL_URL" ] && [ -n "$ENROLLMENT_TOKEN" ]; then
    echo -e "${BLUE}[8/8] Enrolling node with HPanel Controller (${PANEL_URL})...${NC}"

    if [ "$DRY_RUN" = false ]; then
        # Generate self-signed agent TLS certificates for initial bootstrap if not present
        if [ ! -f "${CERTS_DIR}/server.crt" ]; then
            openssl req -x509 -newkey rsa:4096 -sha256 -days 3650 -nodes \
                -keyout "${CERTS_DIR}/server.key" \
                -out "${CERTS_DIR}/server.crt" \
                -subj "/CN=${NODE_NAME}" \
                -addext "subjectAltName=IP:${PRIVATE_IP},DNS:${NODE_NAME}"
            chmod 600 "${CERTS_DIR}/server.key"
            chmod 644 "${CERTS_DIR}/server.crt"
        fi

        AGENT_CA_CERT=$(cat "${CERTS_DIR}/server.crt")

        ENROLL_PAYLOAD=$(jq -n \
            --arg token "$ENROLLMENT_TOKEN" \
            --arg name "$NODE_NAME" \
            --arg ip "$PRIVATE_IP" \
            --argjson port "$AGENT_PORT" \
            --arg loc "$LOCATION" \
            --arg ca "$AGENT_CA_CERT" \
            '{token: $token, name: $name, ip_address: $ip, agent_port: $port, location: $loc, max_accounts: 150, agent_ca_cert: $ca}')

        HTTP_RESPONSE=$(curl -s -w "\n%{http_code}" -X POST "${PANEL_URL}/api/v1/servers/enroll" \
            -H "Content-Type: application/json" \
            -H "Accept: application/json" \
            -d "$ENROLL_PAYLOAD" || echo "FAILED")

        HTTP_BODY=$(echo "$HTTP_RESPONSE" | sed '$d')
        HTTP_CODE=$(echo "$HTTP_RESPONSE" | tail -n1)

        if [ "$HTTP_CODE" == "200" ] || [ "$HTTP_CODE" == "201" ]; then
            echo -e "${GREEN}✓ Node successfully registered with Controller! Response: ${HTTP_BODY}${NC}"
        else
            echo -e "${YELLOW}Warning: Node enrollment returned HTTP ${HTTP_CODE}: ${HTTP_BODY}${NC}"
            echo -e "${YELLOW}You can complete manual enrollment from the Panel UI at any time.${NC}"
        fi
    else
        echo -e "${YELLOW}[DRY-RUN] Would submit enrollment request to: ${PANEL_URL}/api/v1/servers/enroll${NC}"
    fi
else
    echo -e "${YELLOW}[8/8] No --panel-url or --enrollment-token provided. Skipping automatic enrollment.${NC}"
fi

echo -e "${GREEN}"
echo "=============================================================="
echo "          HPANEL NODE SETUP COMPLETED SUCCESSFULLY            "
echo "=============================================================="
echo -e "${NC}"
echo "Agent Directory:   $CONFIG_DIR"
echo "Systemd Unit:      hpanel-agent.service"
echo "Status Check:      systemctl status hpanel-agent"
echo "Metrics Endpoint:  http://${PRIVATE_IP}:${AGENT_PORT}/metrics"

