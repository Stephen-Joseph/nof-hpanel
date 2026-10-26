#!/usr/bin/env bash
# ==============================================================================
# HPanel Node Installer Script
# Target OS: Ubuntu 24.04 LTS (Noble) / Debian 12 (Bookworm)
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Text formatting
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Defaults
DRY_RUN=false
PANEL_URL="${PANEL_URL:-}"
ENROLLMENT_TOKEN="${ENROLLMENT_TOKEN:-}"
NODE_NAME="${NODE_NAME:-$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo "node")}"
NODE_PUBLIC_IP="${NODE_PUBLIC_IP:-}"
LOCATION="${LOCATION:-DEFAULT}"
PRIVATE_IP="${PRIVATE_IP:-}"
PANEL_IP="${PANEL_IP:-}"
AGENT_PORT=8443
AGENT_LISTEN="${AGENT_LISTEN:-127.0.0.1:8443}"
SKIP_FIREWALL=false
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

ENABLE_QUOTA="${ENABLE_QUOTA:-false}"

# Parse CLI flags
while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --enable-quota)
            ENABLE_QUOTA=true
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
        --public-ip)
            NODE_PUBLIC_IP="$2"
            shift 2
            ;;
        --private-ip)
            PRIVATE_IP="$2"
            shift 2
            ;;
        --panel-ip)
            PANEL_IP="$2"
            shift 2
            ;;
        --listen)
            AGENT_LISTEN="$2"
            shift 2
            ;;
        --location)
            LOCATION="$2"
            shift 2
            ;;
        --agent-port)
            AGENT_PORT="$2"
            AGENT_LISTEN="127.0.0.1:${AGENT_PORT}"
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
            echo "  --panel-ip IP           Panel server IP allowed to reach agent port 8443"
            echo "  --listen ADDR:PORT      Listen address for agent (default: 127.0.0.1:8443)"
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
    # shellcheck disable=SC1091
    . /etc/os-release
    OS=$ID
else
    echo -e "${RED}Error: Cannot identify Linux distribution via /etc/os-release.${NC}"
    exit 1
fi

if [[ "$OS" != "ubuntu" && "$OS" != "debian" ]]; then
    echo -e "${RED}Error: Unsupported distribution: $OS. Supported: Ubuntu 24.04+, Debian 12+.${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Operating System verified: ${PRETTY_NAME:-$OS}${NC}"

# Detect Private IP if not provided
if [ -z "$PRIVATE_IP" ]; then
    PRIVATE_IP=$(ip -4 route get 8.8.8.8 2>/dev/null | awk '{print $7}' | tr -d '[:space:]' || hostname -I 2>/dev/null | awk '{print $1}' || echo "127.0.0.1")
    echo -e "${YELLOW}Private IP auto-detected: ${PRIVATE_IP}${NC}"
fi

# 2. Package Repositories Configuration
echo -e "${BLUE}[2/8] Configuring package repositories (PHP PPA, MariaDB)...${NC}"

execute apt-get update -y

# Debian 12/13 does not have software-properties-common; install it only on Ubuntu
if [ "$OS" = "ubuntu" ]; then
    execute apt-get install -y lsb-release ca-certificates apt-transport-https software-properties-common gnupg curl jq ufw fail2ban restic quota quotatool
    execute add-apt-repository -y ppa:ondrej/php
    execute add-apt-repository -y ppa:ondrej/nginx-mainline
elif [ "$OS" = "debian" ]; then
    execute apt-get install -y lsb-release ca-certificates apt-transport-https gnupg curl jq ufw fail2ban restic quota quotatool
    if [ ! -f /etc/apt/trusted.gpg.d/php.gpg ]; then
        if [ "$DRY_RUN" = false ]; then
            curl -sSLo /tmp/debsury.gpg https://packages.sury.org/php/apt.gpg
            gpg --dearmor -o /etc/apt/trusted.gpg.d/php.gpg /tmp/debsury.gpg
            rm -f /tmp/debsury.gpg
            echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/sury-php.list
        else
            echo -e "${YELLOW}[DRY-RUN] Would configure Debian Sury PHP repository${NC}"
        fi
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
if [ "$ENABLE_QUOTA" = true ]; then
    echo -e "${YELLOW}Enabling usrquota and grpquota on root filesystem...${NC}"
    if [ "$DRY_RUN" = false ]; then
        if grep -q ' / ' /etc/fstab && ! grep ' / ' /etc/fstab | grep -q 'usrquota'; then
            sed -i '/ \/ / s/defaults/defaults,usrquota,grpquota/' /etc/fstab || true
            mount -o remount / || true
        fi
        quotacheck -avugm || true
        quotaon -avug || true
    fi
    execute systemctl enable --now quota.service || true
    echo -e "${GREEN}✓ Disk quota subsystem configured and enabled.${NC}"
else
    echo -e "${YELLOW}Notice: Filesystem quotas not enabled (--enable-quota not specified). Storage limits are not enforced at filesystem level.${NC}"
    execute systemctl enable --now quota.service || true
fi

# 5. Configuring UFW Firewall
# Non-negotiable security: port 8443 opened ONLY to PANEL_IP, never to 0.0.0.0/0
if [ "$SKIP_FIREWALL" = false ]; then
    echo -e "${BLUE}[5/8] Configuring UFW firewall rules...${NC}"
    execute ufw default deny incoming
    execute ufw default allow outgoing
    execute ufw allow "${SSH_PORT:-22}/tcp" comment 'SSH'
    execute ufw allow 80/tcp comment 'HTTP'
    execute ufw allow 443/tcp comment 'HTTPS'

    if [ -n "$PANEL_IP" ]; then
        execute ufw allow from "$PANEL_IP" to any port 8443 proto tcp comment 'HPanel Agent to Panel'
        echo -e "${GREEN}✓ Firewall rule added: port 8443 allowed from PANEL_IP (${PANEL_IP})${NC}"
    else
        echo -e "${YELLOW}Notice: PANEL_IP not set; port 8443 not opened in UFW. Agent binds securely to private/local interface.${NC}"
    fi

    execute ufw --force enable
    echo -e "${GREEN}✓ Firewall configured.${NC}"
else
    echo -e "${YELLOW}[5/8] Skipping UFW firewall configuration (--skip-firewall specified).${NC}"
fi

# 6. Directory Structure Setup
echo -e "${BLUE}[6/8] Establishing Agent file structure...${NC}"
execute mkdir -p "$CONFIG_DIR" "$CERTS_DIR" "$LOGS_DIR" "$DATA_DIR" "/home"
execute chmod 750 "$CONFIG_DIR" "$LOGS_DIR" "$DATA_DIR"
execute chmod 755 "$CERTS_DIR"

# Ensure certificates exist (generate ONE internal CA, server cert, and panel client cert)
if [ ! -f "${CERTS_DIR}/ca.crt" ] || [ ! -f "${CERTS_DIR}/ca.key" ]; then
    if [ "$DRY_RUN" = false ]; then
        openssl req -x509 -newkey rsa:4096 -sha256 -days 3650 -nodes \
            -keyout "${CERTS_DIR}/ca.key" \
            -out "${CERTS_DIR}/ca.crt" \
            -subj "/CN=HPanel-Internal-CA"
        chmod 600 "${CERTS_DIR}/ca.key"
        chmod 644 "${CERTS_DIR}/ca.crt"
    fi
fi

if [ ! -f "${CERTS_DIR}/server.crt" ] || [ ! -f "${CERTS_DIR}/server.key" ]; then
    if [ "$DRY_RUN" = false ]; then
        openssl req -newkey rsa:2048 -nodes \
            -keyout "${CERTS_DIR}/server.key" \
            -out "${CERTS_DIR}/server.csr" \
            -subj "/CN=${NODE_NAME}"

        SAN="IP:127.0.0.1"
        if [ -n "${PRIVATE_IP:-}" ] && [ "${PRIVATE_IP}" != "127.0.0.1" ]; then
            SAN="${SAN},IP:${PRIVATE_IP}"
        fi
        SAN="${SAN},DNS:localhost,DNS:${NODE_NAME}"

        openssl x509 -req -in "${CERTS_DIR}/server.csr" \
            -CA "${CERTS_DIR}/ca.crt" -CAkey "${CERTS_DIR}/ca.key" -CAcreateserial \
            -out "${CERTS_DIR}/server.crt" -days 3650 -sha256 \
            -extfile <(printf "subjectAltName=%s\nextendedKeyUsage=serverAuth\n" "$SAN")

        rm -f "${CERTS_DIR}/server.csr"
        chmod 600 "${CERTS_DIR}/server.key"
        chmod 644 "${CERTS_DIR}/server.crt"
    fi
fi

if [ ! -f "${CERTS_DIR}/panel-client.crt" ] || [ ! -f "${CERTS_DIR}/panel-client.key" ]; then
    if [ "$DRY_RUN" = false ]; then
        openssl req -newkey rsa:2048 -nodes \
            -keyout "${CERTS_DIR}/panel-client.key" \
            -out "${CERTS_DIR}/panel-client.csr" \
            -subj "/CN=hpanel-panel-client"

        openssl x509 -req -in "${CERTS_DIR}/panel-client.csr" \
            -CA "${CERTS_DIR}/ca.crt" -CAkey "${CERTS_DIR}/ca.key" -CAcreateserial \
            -out "${CERTS_DIR}/panel-client.crt" -days 3650 -sha256 \
            -extfile <(printf "extendedKeyUsage=clientAuth\n")

        rm -f "${CERTS_DIR}/panel-client.csr"
        chmod 644 "${CERTS_DIR}/panel-client.crt"
        chmod 640 "${CERTS_DIR}/panel-client.key"
        if getent group www-data >/dev/null 2>&1; then
            chown root:www-data "${CERTS_DIR}/panel-client.key"
        fi
    fi
fi

if [ "$DRY_RUN" = false ]; then
    openssl verify -CAfile "${CERTS_DIR}/ca.crt" "${CERTS_DIR}/server.crt" "${CERTS_DIR}/panel-client.crt" >/dev/null 2>&1 || {
        echo -e "${RED}Error: Certificate verification failed (openssl verify -CAfile ca.crt server.crt panel-client.crt)${NC}" >&2
        exit 1
    }
fi

# Write /etc/panel-agent/config.yaml (JSON format as loader requires) ONLY if it does not exist
CONFIG_FILE="${CONFIG_DIR}/config.yaml"
if [ ! -f "$CONFIG_FILE" ]; then
    HMAC_SECRET=$(openssl rand -hex 32)
    if [ "$DRY_RUN" = false ]; then
        cat << EOF > "$CONFIG_FILE"
{
  "server": {
    "listen_address": "${AGENT_LISTEN}",
    "tls_cert_file": "${CERTS_DIR}/server.crt",
    "tls_key_file": "${CERTS_DIR}/server.key",
    "client_ca_file": "${CERTS_DIR}/ca.crt"
  },
  "auth": {
    "hmac_secret": "${HMAC_SECRET}",
    "max_timestamp_skew_seconds": 60,
    "nonce_ring_file": "/var/lib/panel-agent/nonces.dat"
  },
  "logging": {
    "log_file": "/var/log/panel-agent/agent.log",
    "level": "info",
    "format": "json"
  },
  "system": {
    "temp_dir": "/var/lib/panel-agent/tmp"
  }
}
EOF
        chmod 600 "$CONFIG_FILE"
    else
        echo -e "${YELLOW}[DRY-RUN] Would create ${CONFIG_FILE} with generated HMAC secret${NC}"
    fi
fi

# Print secret path ONLY (never the secret itself)
echo "HMAC Secret Configuration: ${CONFIG_FILE}"

# 7. Build and Install Agent Binary & Systemd Service
echo -e "${BLUE}[7/8] Building and configuring panel-agent binary and systemd service...${NC}"

# Check or install Go if missing
if ! command -v go >/dev/null 2>&1; then
    echo -e "${YELLOW}Go binary not detected in PATH. Installing Go toolchain...${NC}"
    GO_MOD_PATH="${SCRIPT_DIR}/agent/go.mod"
    GO_VERSION="1.24.4"
    if [ -f "$GO_MOD_PATH" ]; then
        MOD_VER=$(awk '/^go /{print $2}' "$GO_MOD_PATH" | tr -d '[:space:]')
        if [ -n "$MOD_VER" ]; then
            GO_VERSION="${MOD_VER}"
            if [[ "$GO_VERSION" =~ ^[0-9]+\.[0-9]+$ ]]; then
                GO_VERSION="${GO_VERSION}.0"
            fi
        fi
    fi
    ARCH=$(dpkg --print-architecture 2>/dev/null || uname -m)
    case "$ARCH" in
        x86_64|amd64) GO_ARCH="amd64" ;;
        aarch64|arm64) GO_ARCH="arm64" ;;
        *) GO_ARCH="amd64" ;;
    esac
    GO_TAR="go${GO_VERSION}.linux-${GO_ARCH}.tar.gz"
    echo "Downloading https://go.dev/dl/${GO_TAR}..."
    curl -fsSL "https://go.dev/dl/${GO_TAR}" -o "/tmp/${GO_TAR}"
    rm -rf /usr/local/go
    tar -C /usr/local -xzf "/tmp/${GO_TAR}"
    rm -f "/tmp/${GO_TAR}"
    export PATH="/usr/local/go/bin:${PATH}"
fi

# Build agent static binary outside the temp directory
if [ -d "${SCRIPT_DIR}/agent" ]; then
    BUILD_DIR="/usr/local/src/panel-agent-build"
    execute mkdir -p "$BUILD_DIR"
    (
        cd "${SCRIPT_DIR}/agent"
        execute go build -o "${BUILD_DIR}/panel-agent" ./cmd/agent
    )
    execute chmod 755 "${BUILD_DIR}/panel-agent"
    execute cp -f "${BUILD_DIR}/panel-agent" /usr/local/bin/panel-agent
    execute /usr/local/bin/panel-agent -version
fi

# Configure systemd service
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

# Intent: The agent manages system accounts, configurations, and service reload.
# It requires these capabilities for useradd/usermod, chown, setquota, and writing /etc/php and /etc/nginx.
NoNewPrivileges=no
ProtectSystem=no
ProtectHome=no
PrivateTmp=no
CapabilityBoundingSet=CAP_CHOWN CAP_DAC_OVERRIDE CAP_DAC_READ_SEARCH CAP_FOWNER CAP_FSETID CAP_KILL CAP_SETGID CAP_SETUID CAP_SYS_ADMIN CAP_SYS_RESOURCE CAP_NET_BIND_SERVICE
AmbientCapabilities=CAP_CHOWN CAP_DAC_OVERRIDE CAP_DAC_READ_SEARCH CAP_FOWNER CAP_FSETID CAP_KILL CAP_SETGID CAP_SETUID CAP_SYS_ADMIN CAP_SYS_RESOURCE CAP_NET_BIND_SERVICE

[Install]
WantedBy=multi-user.target
EOF

execute cp /tmp/hpanel-agent.service /etc/systemd/system/hpanel-agent.service
execute rm -f /tmp/hpanel-agent.service
execute systemctl daemon-reload
execute systemctl enable --now hpanel-agent.service

# Verify service is active, capabilities are adequate, and listening
if [ "$DRY_RUN" = false ]; then
    sleep 2
    if ! systemctl is-active --quiet hpanel-agent; then
        echo -e "${RED}Error: hpanel-agent service failed to start or is inactive.${NC}" >&2
        journalctl -u hpanel-agent -n 30 --no-pager >&2 || true
        exit 1
    fi

    MAIN_PID="$(systemctl show -p MainPID --value hpanel-agent 2>/dev/null || true)"
    if [[ -z "$MAIN_PID" || "$MAIN_PID" -le 0 || ! -f "/proc/${MAIN_PID}/status" ]]; then
        echo -e "${RED}Error: Cannot inspect /proc/<MainPID>/status for hpanel-agent (PID: ${MAIN_PID:-unknown}).${NC}" >&2
        journalctl -u hpanel-agent -n 30 --no-pager >&2 || true
        exit 1
    fi

    CAP_EFF="$(grep -i '^CapEff:' "/proc/${MAIN_PID}/status" | awk '{print $2}')"
    if [[ -z "$CAP_EFF" ]] || ! (( 0x${CAP_EFF} != 0 && (0x${CAP_EFF} & 0x2) != 0 && (0x${CAP_EFF} & 0x200000) != 0 )); then
        echo -e "${RED}Error: hpanel-agent has insufficient effective capabilities (CapEff: ${CAP_EFF:-none}).${NC}" >&2
        echo -e "${RED}Agent requires non-zero CapEff including CAP_DAC_OVERRIDE (0x2) and CAP_SYS_ADMIN (0x200000) for useradd, quota, and config provisioning.${NC}" >&2
        journalctl -u hpanel-agent -n 30 --no-pager >&2 || true
        exit 1
    fi
    echo -e "${GREEN}✓ Verified hpanel-agent capabilities (CapEff: ${CAP_EFF}, CAP_DAC_OVERRIDE & CAP_SYS_ADMIN present).${NC}"

    LISTEN_PORT="${AGENT_LISTEN##*:}"
    if ! (ss -tlnp 2>/dev/null || netstat -tlnp 2>/dev/null) | grep -q ":${LISTEN_PORT} "; then
        echo -e "${RED}Error: Agent port ${LISTEN_PORT} is not listening.${NC}" >&2
        journalctl -u hpanel-agent -n 30 --no-pager >&2 || true
        exit 1
    fi
    echo -e "${GREEN}✓ hpanel-agent is active and listening on ${AGENT_LISTEN}.${NC}"

    if [ "$DRY_RUN" = false ]; then
        PANEL_DIR="/var/www/hpanel"
        if [ ! -f "${PANEL_DIR}/artisan" ] && [ -f "${SCRIPT_DIR}/artisan" ]; then
            PANEL_DIR="${SCRIPT_DIR}"
        fi
        if [ -f "${PANEL_DIR}/artisan" ]; then
            echo -e "${BLUE}Running agent health and configuration check (artisan hpanel:agent-check)...${NC}"
            if ! (cd "${PANEL_DIR}" && php artisan hpanel:agent-check); then
                echo -e "${RED}Error: hpanel:agent-check verification failed.${NC}" >&2
                journalctl -u hpanel-agent -n 30 --no-pager >&2 || true
                exit 1
            fi
            echo -e "${GREEN}✓ hpanel:agent-check passed successfully.${NC}"
        fi
    fi
fi

# 8. Node Enrollment Call
ENROLL_SCRIPT="${SCRIPT_DIR}/deploy/enroll.sh"
if [ -n "$PANEL_URL" ] && [ -n "$ENROLLMENT_TOKEN" ]; then
    echo -e "${BLUE}[8/8] Enrolling node with HPanel Controller (${PANEL_URL})...${NC}"
    if [ "$DRY_RUN" = true ]; then
        "$ENROLL_SCRIPT" --dry-run \
            --panel-url "$PANEL_URL" \
            --token "$ENROLLMENT_TOKEN" \
            --hostname "$NODE_NAME" \
            --private-ip "$PRIVATE_IP" \
            --location "$LOCATION"
    else
        "$ENROLL_SCRIPT" \
            --panel-url "$PANEL_URL" \
            --token "$ENROLLMENT_TOKEN" \
            --hostname "$NODE_NAME" \
            --private-ip "$PRIVATE_IP" \
            --location "$LOCATION"
    fi

    echo -e "${GREEN}"
    echo "=============================================================="
    echo "          HPANEL NODE SETUP COMPLETED SUCCESSFULLY            "
    echo "=============================================================="
    echo -e "${NC}"
    echo "Agent Config:      $CONFIG_FILE"
    echo "Systemd Unit:      hpanel-agent.service"
    echo "Status Check:      systemctl status hpanel-agent"
else
    echo -e "${YELLOW}"
    echo "=============================================================="
    echo "       HPANEL NODE STATUS: INSTALLED, NOT ENROLLED            "
    echo "=============================================================="
    echo -e "${NC}"
    echo "Agent service is installed and running, but has not enrolled."
    echo "To enroll this node with the HPanel controller, run:"
    echo ""
    echo "  ${ENROLL_SCRIPT} --panel-url <PANEL_URL> --token <ENROLLMENT_TOKEN>"
    echo ""
    echo "Agent Config:      $CONFIG_FILE"
    echo "Systemd Unit:      hpanel-agent.service"
    echo "Status Check:      systemctl status hpanel-agent"
fi
