#!/usr/bin/env bash
# ==============================================================================
# HPanel Node Enrollment Script
# ==============================================================================
set -Eeuo pipefail

ENROLLED_FILE="/etc/panel-agent/enrolled.json"
CERTS_DIR="/etc/panel-agent/certs"
CONFIG_DIR="/etc/panel-agent"
CONFIG_FILE="${CONFIG_DIR}/config.yaml"

ENROLLMENT_TOKEN="${ENROLLMENT_TOKEN:-}"
PANEL_URL="${PANEL_URL:-}"
DRY_RUN=false
HOSTNAME_ARG=""
NODE_NAME_ARG=""
AGENT_URL_ARG=""
PRIVATE_IP_ARG=""
PUBLIC_IP_ARG=""
LOCATION_ARG=""

print_usage() {
    cat << 'EOF'
Usage: enroll.sh [OPTIONS]

Options:
  --token TOKEN         One-time enrollment token (required, 64-character string)
  --panel-url, --panel  Panel URL, e.g. https://hpanel.example.com (required)
  --name, --node-name   Human-readable node name (default: hostname)
  --hostname HOSTNAME   Node fully qualified hostname (default: hostname -f)
  --private-ip IP       Private / WireGuard interface IP for agent binding
  --public-ip IP        Public IP of this node (optional)
  --agent-url URL       Full agent URL (default: https://<listen-ip>:8443)
  --location LOC        Server location tag (optional)
  --dry-run             Print request body with token redacted and exit
  --help, -h            Show this help message
EOF
}

# 1. Parse CLI flags early (so --dry-run and --help take effect before apt-get)
while [[ $# -gt 0 ]]; do
    case "$1" in
        --token)
            ENROLLMENT_TOKEN="$2"
            shift 2
            ;;
        --panel-url|--panel)
            PANEL_URL="$2"
            shift 2
            ;;
        --hostname)
            HOSTNAME_ARG="$2"
            shift 2
            ;;
        --name|--node-name)
            NODE_NAME_ARG="$2"
            shift 2
            ;;
        --private-ip)
            PRIVATE_IP_ARG="$2"
            shift 2
            ;;
        --public-ip)
            PUBLIC_IP_ARG="$2"
            shift 2
            ;;
        --agent-url)
            AGENT_URL_ARG="$2"
            shift 2
            ;;
        --location)
            LOCATION_ARG="$2"
            shift 2
            ;;
        --dry-run)
            DRY_RUN=true
            shift
            ;;
        --help|-h)
            print_usage
            exit 0
            ;;
        *)
            echo "Error: Unknown argument: $1" >&2
            print_usage >&2
            exit 1
            ;;
    esac
done

# 2. Check and install missing prerequisites on Debian/Ubuntu
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

# Defaults resolution
HOSTNAME="${HOSTNAME_ARG:-${HOSTNAME:-$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo "localhost")}}"
NODE_NAME="${NODE_NAME_ARG:-${NODE_NAME:-$HOSTNAME}}"

if [ -n "$PRIVATE_IP_ARG" ]; then
    PRIVATE_IP="$PRIVATE_IP_ARG"
elif [ -z "${PRIVATE_IP:-}" ]; then
    PRIVATE_IP=$(ip -4 route get 8.8.8.8 2>/dev/null | awk '{print $7}' | tr -d '[:space:]' || hostname -I 2>/dev/null | awk '{print $1}' || echo "127.0.0.1")
fi

PUBLIC_IP="${PUBLIC_IP_ARG:-${PUBLIC_IP:-}}"

AGENT_URL="${AGENT_URL_ARG:-${AGENT_URL:-https://${PRIVATE_IP}:8443}}"
LOCATION="${LOCATION_ARG:-${LOCATION:-}}"

# Check if already enrolled (Idempotency)
if [ -f "$ENROLLED_FILE" ]; then
    if [ "$DRY_RUN" = false ]; then
        if jq -e '.server.id' "$ENROLLED_FILE" >/dev/null 2>&1 || jq -e '.id' "$ENROLLED_FILE" >/dev/null 2>&1; then
            echo "already enrolled"
            exit 0
        fi
    fi
fi

# Validation of required parameters
if [ -z "$ENROLLMENT_TOKEN" ]; then
    echo "Error: ENROLLMENT_TOKEN is required. Pass via --token or ENROLLMENT_TOKEN env var." >&2
    exit 1
fi

if [ -z "$PANEL_URL" ]; then
    echo "Error: PANEL_URL is required. Pass via --panel / --panel-url or PANEL_URL env var." >&2
    exit 1
fi

PANEL_URL="${PANEL_URL%/}"

# Read certificate fingerprint (SHA-256 of the DER format)
CERT_FINGERPRINT=""
CERT_FILE="${CERTS_DIR}/server.crt"
if [ -f "$CERT_FILE" ]; then
    CERT_FINGERPRINT=$(openssl x509 -in "$CERT_FILE" -outform DER 2>/dev/null | sha256sum | awk '{print $1}')
fi

# Hardware specs detection
CPU_CORES=$(nproc 2>/dev/null || getconf _NPROCESSORS_ONLN 2>/dev/null || echo 1)
MEMORY_MB=$(free -m 2>/dev/null | awk '/^Mem:/{print $2}' || echo 1024)
STORAGE_MB=$(df -m / 2>/dev/null | awk 'NR==2{print $2}' || echo 10240)

if [ "$MEMORY_MB" -lt 512 ] 2>/dev/null; then
    MEMORY_MB=512
fi
if [ "$STORAGE_MB" -lt 1024 ] 2>/dev/null; then
    STORAGE_MB=1024
fi

# Build payload with redacted token for dry-run
if command -v jq >/dev/null 2>&1; then
    DRY_RUN_PAYLOAD=$(jq -n \
        --arg token "[REDACTED]" \
        --arg hostname "$HOSTNAME" \
        --arg ip "$PRIVATE_IP" \
        --arg pub_ip "$PUBLIC_IP" \
        --arg agent_url "$AGENT_URL" \
        --arg fp "$CERT_FINGERPRINT" \
        --argjson cpu "$CPU_CORES" \
        --argjson mem "$MEMORY_MB" \
        --argjson storage "$STORAGE_MB" \
        --arg loc "$LOCATION" \
        '{
            token: $token,
            hostname: $hostname,
            private_ip: $ip,
            agent_url: $agent_url,
            agent_public_cert_fingerprint: (if $fp != "" then $fp else null end),
            cpu_cores: $cpu,
            memory_mb: $mem,
            storage_mb: $storage
        } + (if $pub_ip != "" then {public_ip: $pub_ip} else {} end) + (if $loc != "" then {location: $loc} else {} end)')
else
    DRY_RUN_PAYLOAD="{\"token\":\"[REDACTED]\",\"hostname\":\"$HOSTNAME\",\"private_ip\":\"$PRIVATE_IP\",\"agent_url\":\"$AGENT_URL\"}"
fi

if [ "$DRY_RUN" = true ]; then
    echo "[DRY-RUN] Target URL: ${PANEL_URL}/api/v1/servers/enroll"
    echo "[DRY-RUN] Request Payload:"
    if command -v jq >/dev/null 2>&1; then
        echo "$DRY_RUN_PAYLOAD" | jq .
    else
        echo "$DRY_RUN_PAYLOAD"
    fi
    exit 0
fi

# Build actual payload
REAL_PAYLOAD=$(echo "$DRY_RUN_PAYLOAD" | jq --arg token "$ENROLLMENT_TOKEN" '.token = $token')

# Send enrollment POST request via curl
RESPONSE=""
if ! RESPONSE=$(curl --silent --show-error --fail-with-body --max-time 30 \
    -X POST "${PANEL_URL}/api/v1/servers/enroll" \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    -d "$REAL_PAYLOAD"); then
    echo "Error: Node enrollment request failed." >&2
    if [ -n "$RESPONSE" ]; then
        echo "Response body: $RESPONSE" >&2
    fi
    exit 1
fi

# Verify response and extract server info
SERVER_ID=$(echo "$RESPONSE" | jq -r '.server.id // empty')
if [ -z "$SERVER_ID" ]; then
    echo "Error: Invalid response format from panel. Expected .server.id" >&2
    echo "Response: $RESPONSE" >&2
    exit 1
fi

# Extract and configure panel CA certificate if provided
CA_CERT_DATA=$(echo "$RESPONSE" | jq -r '.ca_certificate // empty')
if [ -n "$CA_CERT_DATA" ]; then
    mkdir -p "$CERTS_DIR"
    echo "$CA_CERT_DATA" > "${CERTS_DIR}/panel-ca.crt"
    chmod 644 "${CERTS_DIR}/panel-ca.crt"
fi

# Extract and configure per-node HMAC secret if provided
HMAC_SECRET_DATA=$(echo "$RESPONSE" | jq -r '.hmac_secret // empty')
if [ -n "$HMAC_SECRET_DATA" ]; then
    mkdir -p "$CONFIG_DIR"
    if [ -f "$CONFIG_FILE" ] && jq -e . "$CONFIG_FILE" >/dev/null 2>&1; then
        TMP_CFG="${CONFIG_FILE}.tmp"
        jq --arg sec "$HMAC_SECRET_DATA" --arg ca "${CERTS_DIR}/panel-ca.crt" \
            '.auth.hmac_secret = $sec | .server.client_ca_file = $ca' "$CONFIG_FILE" > "$TMP_CFG"
        chmod 600 "$TMP_CFG"
        mv -f "$TMP_CFG" "$CONFIG_FILE"
    else
        cat << EOF > "$CONFIG_FILE"
{
  "server": {
    "listen_address": "${AGENT_LISTEN:-${PRIVATE_IP}:8443}",
    "tls_cert_file": "${CERTS_DIR}/server.crt",
    "tls_key_file": "${CERTS_DIR}/server.key",
    "client_ca_file": "${CERTS_DIR}/panel-ca.crt"
  },
  "auth": {
    "hmac_secret": "${HMAC_SECRET_DATA}",
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
    fi
fi

# Save enrollment state (without secrets)
mkdir -p "$(dirname "$ENROLLED_FILE")"
echo "$RESPONSE" | jq 'del(.hmac_secret)' > "$ENROLLED_FILE"
chmod 600 "$ENROLLED_FILE"

# Print registered fingerprint and summary table
echo ""
echo "=============================================================="
echo "          HPANEL NODE ENROLLED SUCCESSFULLY                   "
echo "=============================================================="
echo "Server ID:            $SERVER_ID"
echo "Hostname:             $(echo "$RESPONSE" | jq -r '.server.hostname // "unknown"')"
echo "Private IP:           $(echo "$RESPONSE" | jq -r '.server.private_ip // "unknown"')"
echo "Public IP:            $(echo "$RESPONSE" | jq -r '.server.public_ip // "none"')"
echo "Registered Cert FP:   ${CERT_FINGERPRINT:-none}"
echo "Status:               $(echo "$RESPONSE" | jq -r '.server.status // "active"')"
echo "Location:             $(echo "$RESPONSE" | jq -r '.server.location // "default"')"
echo "Config File:          $ENROLLED_FILE"
echo "=============================================================="

exit 0
