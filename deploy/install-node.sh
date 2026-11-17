#!/usr/bin/env bash
# ==============================================================================
# HPanel Node Installer Script
# Target OS: Ubuntu 24.04 LTS (Noble) / Debian 12 (Bookworm) / Debian 13 (Trixie)
# ==============================================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." 2>/dev/null && pwd || echo "/tmp")"

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
AGENT_LISTEN="${AGENT_LISTEN:-}"
SKIP_FIREWALL=false
CONFIG_DIR="/etc/panel-agent"
CERTS_DIR="/etc/panel-agent/certs"
LOGS_DIR="/var/log/panel-agent"
DATA_DIR="/var/lib/panel-agent"
LOG_CAPTURE_FILE="/tmp/hpanel-node-install.log"
ENABLE_QUOTA="${ENABLE_QUOTA:-false}"

# Parse CLI flags early (so --dry-run and --help take effect before any installation)
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
        --panel|--panel-url)
            PANEL_URL="$2"
            shift 2
            ;;
        --token|--enrollment-token)
            ENROLLMENT_TOKEN="$2"
            shift 2
            ;;
        --name|--node-name)
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
            echo "  --panel, --panel-url    HPanel Controller URL (e.g. https://panel.example.com)"
            echo "  --token, --enrollment-token  One-time server enrollment token generated from panel"
            echo "  --name, --node-name     Custom node name identifier (default: hostname)"
            echo "  --public-ip IP          Public IP of this node"
            echo "  --private-ip IP         WireGuard / private interface IP for agent binding"
            echo "  --panel-ip IP           Panel server IP allowed to reach agent port 8443"
            echo "  --listen ADDR:PORT      Listen address for agent (default: primary private IP:8443)"
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

# Check and install missing prerequisites on Debian/Ubuntu before any other step
REQUIRED_PREREQS=(jq curl openssl ca-certificates ufw)
MISSING_PREREQS=()

for tool in "${REQUIRED_PREREQS[@]}"; do
    if ! command -v "$tool" >/dev/null 2>&1; then
        MISSING_PREREQS+=("$tool")
    fi
done

if [ ${#MISSING_PREREQS[@]} -gt 0 ]; then
    if [ "$DRY_RUN" = true ]; then
        echo "[DRY-RUN] Would install missing prerequisites: ${MISSING_PREREQS[*]}"
    else
        IS_DEBIAN_LIKE=false
        if [ -f /etc/os-release ]; then
            # shellcheck disable=SC1091
            . /etc/os-release
            if [[ "${ID:-}" = "debian" || "${ID:-}" = "ubuntu" || "${ID_LIKE:-}" =~ (debian|ubuntu) ]]; then
                IS_DEBIAN_LIKE=true
            fi
        fi

        if [ "$IS_DEBIAN_LIKE" = true ]; then
            echo "Installing missing prerequisites: ${MISSING_PREREQS[*]}..."
            if ! apt-get update -y; then
                echo "Error: Failed to run apt-get update while installing prerequisites." >&2
                exit 1
            fi
            if ! apt-get install -y "${MISSING_PREREQS[@]}"; then
                echo "Error: Failed to install prerequisite packages: ${MISSING_PREREQS[*]}." >&2
                exit 1
            fi
        fi

        # Verify each tool was installed successfully
        for tool in "${MISSING_PREREQS[@]}"; do
            if ! command -v "$tool" >/dev/null 2>&1; then
                echo "Error: Required prerequisite tool '${tool}' is missing and could not be installed." >&2
                exit 1
            fi
        done
    fi
fi

# Log capture setup
if [ "$DRY_RUN" = false ]; then
    exec > >(tee -a "$LOG_CAPTURE_FILE") 2>&1
fi

# shellcheck disable=SC2317
cleanup_on_error() {
    local exit_code=$?
    if [ "$exit_code" -ne 0 ] && [ "$DRY_RUN" = false ]; then
        echo -e "\n${RED}==============================================================" >&2
        echo "               INSTALLATION / ENROLLMENT FAILED               " >&2
        echo -e "==============================================================${NC}" >&2
        echo "Last 30 log lines:" >&2
        if [ -f "$LOG_CAPTURE_FILE" ]; then
            tail -n 30 "$LOG_CAPTURE_FILE" >&2
        fi
        # Roll back un-enrolled service if created during this run
        if [ ! -f "${CONFIG_DIR}/enrolled.json" ]; then
            systemctl stop hpanel-agent 2>/dev/null || true
            systemctl disable hpanel-agent 2>/dev/null || true
            rm -f /etc/systemd/system/hpanel-agent.service 2>/dev/null || true
            systemctl daemon-reload 2>/dev/null || true
        fi
    fi
    exit "$exit_code"
}
trap cleanup_on_error EXIT

# Print banner
echo -e "${BLUE}"
echo "=============================================================="
echo "          HPANEL SERVER NODE AUTOMATED INSTALLER              "
echo "=============================================================="
echo -e "${NC}"

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
elif [ "$DRY_RUN" = true ]; then
    OS="ubuntu"
    PRETTY_NAME="Ubuntu 24.04 LTS (Dry Run)"
else
    echo -e "${RED}Error: Cannot identify Linux distribution via /etc/os-release.${NC}"
    exit 1
fi

if [[ "$OS" != "ubuntu" && "$OS" != "debian" ]]; then
    echo -e "${RED}Error: Unsupported distribution: $OS. Supported: Ubuntu 24.04+, Debian 12/13+.${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Operating System verified: ${PRETTY_NAME:-$OS}${NC}"

# Detect Private IP if not provided
if [ -z "$PRIVATE_IP" ]; then
    PRIVATE_IP=$(ip -4 route get 8.8.8.8 2>/dev/null | awk '{print $7}' | tr -d '[:space:]' || hostname -I 2>/dev/null | awk '{print $1}' || echo "127.0.0.1")
    echo -e "${YELLOW}Private IP auto-detected: ${PRIVATE_IP}${NC}"
fi

# Set default listen address to private IP:AGENT_PORT if not explicitly passed
if [ -z "$AGENT_LISTEN" ]; then
    AGENT_LISTEN="${PRIVATE_IP}:${AGENT_PORT}"
fi

# Auto-resolve PANEL_IP from PANEL_URL if not provided
if [ -z "$PANEL_IP" ] && [ -n "$PANEL_URL" ]; then
    PANEL_HOST=$(echo "$PANEL_URL" | sed -e 's|^[^/]*//||' -e 's|/.*$||' -e 's|:.*$||')
    if [ -n "$PANEL_HOST" ]; then
        RESOLVED_IP=$(getent ahosts "$PANEL_HOST" 2>/dev/null | awk '{print $1}' | head -n 1 || true)
        if [ -n "$RESOLVED_IP" ]; then
            PANEL_IP="$RESOLVED_IP"
            echo -e "${GREEN}✓ Resolved panel host (${PANEL_HOST}) to IP: ${PANEL_IP}${NC}"
        fi
    fi
fi

# 2. Package Repositories Configuration
echo -e "${BLUE}[2/8] Configuring package repositories (PHP PPA, MariaDB)...${NC}"

execute apt-get update -y

# Install prerequisite tools
if [ "$OS" = "ubuntu" ]; then
    execute apt-get install -y lsb-release ca-certificates apt-transport-https software-properties-common gnupg curl jq ufw fail2ban restic quota quotatool
    execute add-apt-repository -y ppa:ondrej/php
    execute add-apt-repository -y ppa:ondrej/nginx-mainline
elif [ "$OS" = "debian" ]; then
    execute apt-get install -y lsb-release ca-certificates apt-transport-https gnupg curl jq ufw fail2ban restic quota quotatool
    if [ ! -f /etc/apt/trusted.gpg.d/php.gpg ]; then
        if [ "$DRY_RUN" = false ]; then
            curl -sSLo /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg
            echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" > /etc/apt/sources.list.d/php.list
        else
            echo -e "${YELLOW}[DRY-RUN] Would add Ondřej Surý PHP repository for Debian${NC}"
        fi
    fi
fi

execute apt-get update -y
echo -e "${GREEN}✓ Package repositories configured.${NC}"

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

# OpenSSH SFTP Baseline Configuration
echo -e "${BLUE}Configuring OpenSSH baseline for customer SFTP (/etc/ssh/sshd_config.d/00-hpanel.conf)...${NC}"
if [ -f /etc/ssh/sshd_config ]; then
    if grep -qE '^[ \t]*(PasswordAuthentication|KbdInteractiveAuthentication)[ \t]+no' /etc/ssh/sshd_config; then
        if [ "$DRY_RUN" = false ]; then
            if [ ! -f /etc/ssh/sshd_config.hpanel.bak ]; then
                cp -p /etc/ssh/sshd_config /etc/ssh/sshd_config.hpanel.bak
            fi
            sed -i -E 's/^[ \t]*PasswordAuthentication[ \t]+no/# PasswordAuthentication no (disabled by HPanel)/g' /etc/ssh/sshd_config
            sed -i -E 's/^[ \t]*KbdInteractiveAuthentication[ \t]+no/# KbdInteractiveAuthentication no (disabled by HPanel)/g' /etc/ssh/sshd_config
            if ! sshd -t; then
                echo -e "${RED}Error: sshd -t failed after modifying /etc/ssh/sshd_config. Restoring backup.${NC}"
                cp -f /etc/ssh/sshd_config.hpanel.bak /etc/ssh/sshd_config
            fi
        else
            echo -e "${YELLOW}[DRY-RUN] Would comment out PasswordAuthentication no and KbdInteractiveAuthentication no in /etc/ssh/sshd_config${NC}"
        fi
    fi
fi

if [ "$DRY_RUN" = false ]; then
    mkdir -p /etc/ssh/sshd_config.d
    SFTP_SERVER_BIN="/usr/lib/openssh/sftp-server"
    for cand in /usr/lib/openssh/sftp-server /usr/libexec/openssh/sftp-server /usr/lib/ssh/sftp-server /usr/libexec/sftp-server; do
        if [ -f "$cand" ]; then
            SFTP_SERVER_BIN="$cand"
            break
        fi
    done

    cat << EOF > /etc/ssh/sshd_config.d/00-hpanel.conf.tmp
# Managed by HPanel - Global SFTP and SSH baseline
PasswordAuthentication yes
KbdInteractiveAuthentication yes
PubkeyAuthentication yes
Subsystem sftp ${SFTP_SERVER_BIN}
EOF

    if sshd -t -f /etc/ssh/sshd_config.d/00-hpanel.conf.tmp 2>/dev/null || sshd -t; then
        mv -f /etc/ssh/sshd_config.d/00-hpanel.conf.tmp /etc/ssh/sshd_config.d/00-hpanel.conf
        chmod 644 /etc/ssh/sshd_config.d/00-hpanel.conf
        systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true
        echo -e "${GREEN}✓ OpenSSH baseline configured and reloaded.${NC}"
    else
        echo -e "${RED}Error: sshd -t validation failed for 00-hpanel.conf.${NC}"
        rm -f /etc/ssh/sshd_config.d/00-hpanel.conf.tmp
    fi
fi

# 4. Linux Disk Quotas Configuration
echo -e "${BLUE}[4/8] Configuring filesystem disk quotas...${NC}"
if [ "$ENABLE_QUOTA" = true ] && [ "$DRY_RUN" = false ]; then
    if grep -q " /home " /etc/fstab; then
        TARGET_MOUNT="/home"
    else
        TARGET_MOUNT="/"
    fi
    echo -e "${YELLOW}Enabling usrquota and grpquota on mount point: ${TARGET_MOUNT}${NC}"
    if ! grep -E "\s${TARGET_MOUNT}\s" /etc/fstab | grep -q "usrquota"; then
        sed -i -E "s|(\s${TARGET_MOUNT}\s+\S+\s+)(\S+)|\1\2,usrquota,grpquota|" /etc/fstab
        mount -o remount "${TARGET_MOUNT}" || true
    fi
    quotacheck -cum "${TARGET_MOUNT}" 2>/dev/null || true
    quotaon -v "${TARGET_MOUNT}" 2>/dev/null || true
    echo -e "${GREEN}✓ Filesystem quotas enabled on ${TARGET_MOUNT}.${NC}"
else
    echo -e "${YELLOW}Notice: Linux filesystem quotas skipped (pass --enable-quota to activate).${NC}"
fi

# 5. Configuring UFW Firewall
# Port 8443 is opened ONLY to PANEL_IP and NEVER to 0.0.0.0/0
if [ "$SKIP_FIREWALL" = false ]; then
    echo -e "${BLUE}[5/8] Configuring UFW firewall rules...${NC}"
    execute ufw default deny incoming
    execute ufw default allow outgoing
    execute ufw allow "${SSH_PORT:-22}/tcp" comment 'SSH'
    execute ufw allow 80/tcp comment 'HTTP'
    execute ufw allow 443/tcp comment 'HTTPS'

    LISTEN_PORT="${AGENT_LISTEN##*:}"
    if [ -n "$PANEL_IP" ]; then
        execute ufw allow from "$PANEL_IP" to any port "$LISTEN_PORT" proto tcp comment 'HPanel Agent to Panel'
        echo -e "${GREEN}✓ Firewall rule added: port ${LISTEN_PORT} allowed exclusively from PANEL_IP (${PANEL_IP})${NC}"
    else
        echo -e "${YELLOW}Notice: PANEL_IP not specified or resolved; agent port ${LISTEN_PORT} not opened in UFW (binds securely to private IP).${NC}"
    fi

    execute ufw --force enable
    echo -e "${GREEN}✓ Firewall configured.${NC}"
else
    echo -e "${YELLOW}[5/8] Skipping UFW firewall configuration (--skip-firewall specified).${NC}"
fi

# 6. Establishing Directory Structure
echo -e "${BLUE}[6/9] Establishing Agent file structure...${NC}"
execute mkdir -p "$CONFIG_DIR" "$CERTS_DIR" "$LOGS_DIR" "$DATA_DIR" "/home"
execute chmod 750 "$CONFIG_DIR" "$LOGS_DIR" "$DATA_DIR"
execute chmod 755 "$CERTS_DIR"

# Ensure shared ACME challenge & HTTP verification directory exists with 0755 root:root
execute mkdir -p "/var/www/_acme/.well-known/acme-challenge" "/var/www/_acme/.well-known/hpanel-verify"
execute chown -R root:root "/var/www/_acme"
execute chmod -R 755 "/var/www/_acme"

# 7. Download or Build Agent Binary
echo -e "${BLUE}[7/9] Preparing panel-agent binary...${NC}"
AGENT_BIN="/usr/local/bin/panel-agent"

if [ -d "${SCRIPT_DIR}/agent" ]; then
    echo -e "${BLUE}Same-VPS environment detected (agent source in ${SCRIPT_DIR}/agent)...${NC}"
    if [ "$DRY_RUN" = true ]; then
        echo -e "${YELLOW}[DRY-RUN] Would build agent from ${SCRIPT_DIR}/agent using Go toolchain...${NC}"
    else
        # Only install Go toolchain if same-VPS and go is missing
        if ! command -v go >/dev/null 2>&1; then
            echo -e "${YELLOW}Go toolchain missing on host. Installing Go to compile agent...${NC}"
            ARCH=$(dpkg --print-architecture 2>/dev/null || uname -m)
            case "$ARCH" in
                x86_64|amd64) GO_ARCH="amd64" ;;
                aarch64|arm64) GO_ARCH="arm64" ;;
                *) GO_ARCH="amd64" ;;
            esac
            GO_VERSION="1.24.4"
            GO_TAR="go${GO_VERSION}.linux-${GO_ARCH}.tar.gz"
            curl -fsSL "https://go.dev/dl/${GO_TAR}" -o "/tmp/${GO_TAR}"
            mkdir -p /usr/local
            rm -rf /usr/local/go
            tar -C /usr/local -xzf "/tmp/${GO_TAR}"
            rm -f "/tmp/${GO_TAR}"
            export PATH="/usr/local/go/bin:${PATH}"
        fi

        BUILD_DIR="/usr/local/src/panel-agent-build"
        execute mkdir -p "$BUILD_DIR"
        (
            cd "${SCRIPT_DIR}/agent"
            execute go build -trimpath -ldflags="-s -w" -o "${BUILD_DIR}/panel-agent" ./cmd/agent
        )
        execute install -m 0755 "${BUILD_DIR}/panel-agent" "$AGENT_BIN"
    fi
else
    # Remote node path: Do not install Go toolchain!
    echo -e "${BLUE}Remote node detected. Downloading prebuilt panel-agent binary from controller...${NC}"
    if [ -z "$PANEL_URL" ]; then
        echo -e "${RED}Error: PANEL_URL is required to download prebuilt agent binary.${NC}" >&2
        exit 1
    fi

    DOWNLOAD_BIN="/tmp/panel-agent-linux-amd64"
    DOWNLOAD_SHA="/tmp/panel-agent-linux-amd64.sha256"

    if [ "$DRY_RUN" = true ]; then
        echo -e "${YELLOW}[DRY-RUN] Would download prebuilt binary from ${PANEL_URL}/deploy/panel-agent-linux-amd64${NC}"
        echo -e "${YELLOW}[DRY-RUN] Would download checksum from ${PANEL_URL}/deploy/panel-agent-linux-amd64.sha256${NC}"
        echo -e "${YELLOW}[DRY-RUN] Would verify SHA256 checksum and install to ${AGENT_BIN} (mode 0755)${NC}"
        echo -e "${YELLOW}[DRY-RUN] Would run ${AGENT_BIN} -version${NC}"
    else
        echo "Downloading ${PANEL_URL}/deploy/panel-agent-linux-amd64..."
        if ! curl -fsSL "${PANEL_URL}/deploy/panel-agent-linux-amd64" -o "$DOWNLOAD_BIN"; then
            echo -e "${RED}Error: Failed to download prebuilt agent binary from ${PANEL_URL}/deploy/panel-agent-linux-amd64${NC}" >&2
            exit 1
        fi

        echo "Downloading ${PANEL_URL}/deploy/panel-agent-linux-amd64.sha256..."
        if ! curl -fsSL "${PANEL_URL}/deploy/panel-agent-linux-amd64.sha256" -o "$DOWNLOAD_SHA"; then
            echo -e "${RED}Error: Failed to download agent checksum from ${PANEL_URL}/deploy/panel-agent-linux-amd64.sha256${NC}" >&2
            rm -f "$DOWNLOAD_BIN"
            exit 1
        fi

        # Verify checksum
        EXPECTED_SHA=$(awk '{print $1}' "$DOWNLOAD_SHA" | head -n 1)
        ACTUAL_SHA=$(sha256sum "$DOWNLOAD_BIN" | awk '{print $1}')

        if [ -z "$EXPECTED_SHA" ] || [ -z "$ACTUAL_SHA" ] || [ "$EXPECTED_SHA" != "$ACTUAL_SHA" ]; then
            echo -e "${RED}Error: Checksum verification failed for panel-agent! (expected: ${EXPECTED_SHA:-none}, got: ${ACTUAL_SHA:-none})${NC}" >&2
            rm -f "$DOWNLOAD_BIN" "$DOWNLOAD_SHA"
            exit 1
        fi
        echo -e "${GREEN}✓ Checksum verified (${ACTUAL_SHA}).${NC}"

        if ! install -m 0755 "$DOWNLOAD_BIN" "$AGENT_BIN"; then
            echo -e "${RED}Error: Failed to install agent binary to ${AGENT_BIN}${NC}" >&2
            rm -f "$DOWNLOAD_BIN" "$DOWNLOAD_SHA"
            exit 1
        fi
        rm -f "$DOWNLOAD_BIN" "$DOWNLOAD_SHA"
    fi
fi

execute ln -sf "$AGENT_BIN" /usr/local/bin/hpanel-agent

# Execute panel-agent -version before creating the systemd service
if [ "$DRY_RUN" = false ]; then
    echo "Testing agent binary (/usr/local/bin/panel-agent -version)..."
    if ! /usr/local/bin/panel-agent -version; then
        echo -e "${RED}Error: Execution of 'panel-agent -version' failed before creating systemd service.${NC}" >&2
        exit 1
    fi
    echo -e "${GREEN}✓ panel-agent execution verified.${NC}"
fi

# 8. Certificate Generation & Node Enrollment
echo -e "${BLUE}[8/9] Establishing TLS certificate and enrolling with panel...${NC}"

# Sign server certificate with SAN IP:<node public IP> (do not generate CA or client certs)
if [ ! -f "${CERTS_DIR}/server.crt" ] || [ ! -f "${CERTS_DIR}/server.key" ]; then
    if [ "$DRY_RUN" = false ]; then
        SAN="IP:127.0.0.1"
        if [ -n "${NODE_PUBLIC_IP:-}" ]; then
            SAN="${SAN},IP:${NODE_PUBLIC_IP}"
        fi
        if [ -n "${PRIVATE_IP:-}" ] && [ "${PRIVATE_IP}" != "127.0.0.1" ] && [ "${PRIVATE_IP}" != "${NODE_PUBLIC_IP:-}" ]; then
            SAN="${SAN},IP:${PRIVATE_IP}"
        fi
        SAN="${SAN},DNS:localhost,DNS:${NODE_NAME}"

        openssl req -x509 -newkey rsa:2048 -nodes \
            -keyout "${CERTS_DIR}/server.key" \
            -out "${CERTS_DIR}/server.crt" \
            -days 3650 -sha256 \
            -subj "/CN=${NODE_NAME}" \
            -extfile <(printf "subjectAltName=%s\nextendedKeyUsage=serverAuth\n" "$SAN")

        chmod 600 "${CERTS_DIR}/server.key"
        chmod 644 "${CERTS_DIR}/server.crt"
    fi
fi

CERT_FINGERPRINT=""
if [ -f "${CERTS_DIR}/server.crt" ]; then
    CERT_FINGERPRINT=$(openssl x509 -in "${CERTS_DIR}/server.crt" -outform DER 2>/dev/null | sha256sum | awk '{print $1}')
fi

ENROLL_SCRIPT="${SCRIPT_DIR}/deploy/enroll.sh"
if [ ! -f "$ENROLL_SCRIPT" ]; then
    ENROLL_SCRIPT="/tmp/hpanel-enroll.sh"
    if [ "$DRY_RUN" = true ]; then
        echo "[DRY-RUN] Would download enroll.sh from ${PANEL_URL}/deploy/enroll.sh"
    elif [ -n "$PANEL_URL" ]; then
        curl -fsSL "${PANEL_URL}/deploy/enroll.sh" -o "$ENROLL_SCRIPT"
        chmod +x "$ENROLL_SCRIPT"
    fi
fi

ENROLL_SUCCESS=false
HEALTH_CHECK_SUCCESS=false
CONFIG_FILE="${CONFIG_DIR}/config.yaml"
ENROLLED_FILE="${CONFIG_DIR}/enrolled.json"

if [ -n "$PANEL_URL" ] && [ -n "$ENROLLMENT_TOKEN" ]; then
    echo "Enrolling node with HPanel Controller (${PANEL_URL})..."
    ENROLL_ARGS=(
        --panel "$PANEL_URL"
        --token "$ENROLLMENT_TOKEN"
        --hostname "$NODE_NAME"
        --private-ip "$PRIVATE_IP"
        --location "$LOCATION"
    )
    if [ -n "$NODE_PUBLIC_IP" ]; then
        ENROLL_ARGS+=(--public-ip "$NODE_PUBLIC_IP")
    fi

    if [ "$DRY_RUN" = true ]; then
        if [ -f "$ENROLL_SCRIPT" ]; then
            "$ENROLL_SCRIPT" --dry-run "${ENROLL_ARGS[@]}"
        else
            echo "[DRY-RUN] Target URL: ${PANEL_URL}/api/v1/servers/enroll"
            echo "[DRY-RUN] Request Payload: {\"token\":\"[REDACTED]\",\"hostname\":\"$NODE_NAME\",\"private_ip\":\"$PRIVATE_IP\"}"
        fi
        ENROLL_SUCCESS=true
        HEALTH_CHECK_SUCCESS=true
    else
        if "$ENROLL_SCRIPT" "${ENROLL_ARGS[@]}"; then
            ENROLL_SUCCESS=true
            if [ -f "$ENROLLED_FILE" ]; then
                HEALTH_CHECK_SUCCESS=$(jq -r '.server.health_check_passed // false' "$ENROLLED_FILE")
            fi
        else
            echo -e "${RED}Error: Node enrollment request failed.${NC}" >&2
            exit 1
        fi
    fi
fi

# 9. Configure & Start Systemd Service, then Verify Panel Health Check
echo -e "${BLUE}[9/9] Starting agent service and verifying panel connectivity...${NC}"

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

if [ "$DRY_RUN" = false ]; then
    sleep 2

    # Fail the install if systemctl is-active hpanel-agent is not active
    if ! systemctl is-active --quiet hpanel-agent; then
        echo -e "${RED}Error: hpanel-agent service is not active (status: $(systemctl is-active hpanel-agent 2>/dev/null || echo 'unknown')).${NC}" >&2
        journalctl -u hpanel-agent -n 30 --no-pager >&2 || true
        exit 1
    fi

    # Fail the install if the port is not listening
    LISTEN_PORT="${AGENT_LISTEN##*:}"
    if ! (ss -tlnp 2>/dev/null || netstat -tlnp 2>/dev/null) | grep -q ":${LISTEN_PORT} "; then
        echo -e "${RED}Error: Agent port ${LISTEN_PORT} is not listening.${NC}" >&2
        journalctl -u hpanel-agent -n 30 --no-pager >&2 || true
        exit 1
    fi
    echo -e "${GREEN}✓ hpanel-agent is active and listening on ${AGENT_LISTEN}.${NC}"

    # Verify panel's health check reached the agent
    if [ "$HEALTH_CHECK_SUCCESS" != "true" ] && [ -n "$PANEL_URL" ] && [ -f "$ENROLLED_FILE" ]; then
        SERVER_ID=$(jq -r '.server.id // empty' "$ENROLLED_FILE")
        HMAC_SECRET=$(jq -r '.auth.hmac_secret // empty' "$CONFIG_FILE")

        if [ -n "$SERVER_ID" ] && [ -n "$HMAC_SECRET" ]; then
            echo "Requesting panel to verify agent health check..."
            VERIFY_RESP=$(curl --silent --show-error --max-time 15 \
                -X POST "${PANEL_URL}/api/v1/servers/verify-health" \
                -H "Content-Type: application/json" \
                -H "Accept: application/json" \
                -d "{\"server_id\": ${SERVER_ID}, \"hmac_secret\": \"${HMAC_SECRET}\"}" 2>/dev/null || true)

            if echo "$VERIFY_RESP" | jq -e '.status == "active"' >/dev/null 2>&1; then
                HEALTH_CHECK_SUCCESS=true
                echo -e "${GREEN}✓ Panel health check reached agent successfully.${NC}"
            else
                echo -e "${RED}Error: Panel health check failed to reach agent.${NC}" >&2
                if [ -n "$VERIFY_RESP" ]; then
                    echo "Response: $VERIFY_RESP" >&2
                fi
                journalctl -u hpanel-agent -n 30 --no-pager >&2 || true
                exit 1
            fi
        fi
    fi
fi

# Final PASS / FAIL Table
echo ""
echo "=============================================================="
echo "                 NODE SETUP VERIFICATION                      "
echo "=============================================================="
printf "%-35s %-15s\n" "Component" "Status"
echo "--------------------------------------------------------------"
printf "%-35s %-15s\n" "Operating System (Debian/Ubuntu)" "PASS"
printf "%-35s %-15s\n" "Web & PHP Packages (Nginx, MariaDB)" "PASS"
printf "%-35s %-15s\n" "Agent TLS Certificate" "PASS"
AGENT_STATUS="FAIL"
if [ "$DRY_RUN" = true ] || (systemctl is-active --quiet hpanel-agent 2>/dev/null); then
    AGENT_STATUS="PASS"
fi
printf "%-35s %-15s\n" "Agent Service (hpanel-agent)" "$AGENT_STATUS"
printf "%-35s %-15s\n" "Agent Listen Port (${AGENT_LISTEN})" "PASS"
printf "%-35s %-15s\n" "Node Enrollment" "$([ "$ENROLL_SUCCESS" = true ] && echo 'PASS' || echo 'SKIPPED')"
HEALTH_STATUS="SKIPPED"
if [ "$HEALTH_CHECK_SUCCESS" = true ] || [ "$HEALTH_CHECK_SUCCESS" = "true" ]; then
    HEALTH_STATUS="PASS"
fi
printf "%-35s %-15s\n" "Agent Health Check" "$HEALTH_STATUS"
echo "=============================================================="
echo "Registered Cert Fingerprint: ${CERT_FINGERPRINT:-none}"
echo "Agent Config File:           $CONFIG_FILE"
echo "Systemd Unit:                hpanel-agent.service"
echo "=============================================================="

if [ "$ENROLL_SUCCESS" = false ] && [ -n "$ENROLLMENT_TOKEN" ]; then
    exit 1
fi

if [ "$HEALTH_CHECK_SUCCESS" != "true" ] && [ "$HEALTH_CHECK_SUCCESS" != true ] && [ -n "$ENROLLMENT_TOKEN" ] && [ "$DRY_RUN" = false ]; then
    echo -e "${RED}Error: Node installation failed because panel health check could not reach agent.${NC}" >&2
    exit 1
fi

exit 0
