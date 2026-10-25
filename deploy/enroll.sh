#!/usr/bin/env bash
# ==============================================================================
# HPanel Node Enrollment Script
# ==============================================================================
set -Eeuo pipefail

ENROLLED_FILE="/etc/panel-agent/enrolled.json"
CERTS_DIR="/etc/panel-agent/certs"

ENROLLMENT_TOKEN="${ENROLLMENT_TOKEN:-}"
PANEL_URL="${PANEL_URL:-}"
DRY_RUN=false
HOSTNAME_ARG=""
NODE_NAME_ARG=""
AGENT_URL_ARG=""
PRIVATE_IP_ARG=""
LOCATION_ARG=""

print_usage() {
    cat << 'EOF'
Usage: enroll.sh [OPTIONS]

Options:
  --token TOKEN         One-time enrollment token (required, 64-character string)
  --panel-url URL       Panel URL, e.g. https://hpanel.example.com (required)
  --hostname HOSTNAME   Node fully qualified hostname (default: hostname -f)
  --node-name NAME      Human-readable node name (default: hostname)
  --private-ip IP       Private / WireGuard interface IP for agent binding
  --agent-url URL       Full agent URL (default: https://<private-ip>:8443)
  --location LOC        Server location tag (optional)
  --dry-run             Print request body with token redacted and exit
  --help, -h            Show this help message
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --token)
            ENROLLMENT_TOKEN="$2"
            shift 2
            ;;
        --panel-url)
            PANEL_URL="$2"
            shift 2
            ;;
        --hostname)
            HOSTNAME_ARG="$2"
            shift 2
            ;;
        --node-name)
            NODE_NAME_ARG="$2"
            shift 2
            ;;
        --private-ip)
            PRIVATE_IP_ARG="$2"
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

# Defaults resolution
HOSTNAME="${HOSTNAME_ARG:-${HOSTNAME:-$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo "localhost")}}"
NODE_NAME="${NODE_NAME_ARG:-${NODE_NAME:-$HOSTNAME}}"

if [ -n "$PRIVATE_IP_ARG" ]; then
    PRIVATE_IP="$PRIVATE_IP_ARG"
elif [ -z "${PRIVATE_IP:-}" ]; then
    PRIVATE_IP=$(ip -4 route get 8.8.8.8 2>/dev/null | awk '{print $7}' | tr -d '[:space:]' || hostname -I 2>/dev/null | awk '{print $1}' || echo "127.0.0.1")
fi

AGENT_URL="${AGENT_URL_ARG:-${AGENT_URL:-https://${PRIVATE_IP}:8443}}"
LOCATION="${LOCATION_ARG:-${LOCATION:-}}"

# Check if already enrolled (Idempotency)
if [ -f "$ENROLLED_FILE" ]; then
    if jq -e '.server.id' "$ENROLLED_FILE" >/dev/null 2>&1 || jq -e '.id' "$ENROLLED_FILE" >/dev/null 2>&1; then
        echo "already enrolled"
        exit 0
    fi
fi

# Validation of required parameters
if [ -z "$ENROLLMENT_TOKEN" ]; then
    echo "Error: ENROLLMENT_TOKEN is required. Pass via --token or ENROLLMENT_TOKEN env var." >&2
    exit 1
fi

if [ -z "$PANEL_URL" ]; then
    echo "Error: PANEL_URL is required. Pass via --panel-url or PANEL_URL env var." >&2
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
DRY_RUN_PAYLOAD=$(jq -n \
    --arg token "[REDACTED]" \
    --arg hostname "$HOSTNAME" \
    --arg ip "$PRIVATE_IP" \
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
    } + (if $loc != "" then {location: $loc} else {} end)')

if [ "$DRY_RUN" = true ]; then
    echo "[DRY-RUN] Target URL: ${PANEL_URL}/api/v1/servers/enroll"
    echo "[DRY-RUN] Request Payload:"
    echo "$DRY_RUN_PAYLOAD" | jq .
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

# Save enrollment state
mkdir -p "$(dirname "$ENROLLED_FILE")"
echo "$RESPONSE" > "$ENROLLED_FILE"
chmod 600 "$ENROLLED_FILE"

# Print safe summary without any secrets
echo "=============================================================="
echo "          HPANEL NODE ENROLLED SUCCESSFULLY                   "
echo "=============================================================="
echo "Server ID:   $SERVER_ID"
echo "Hostname:    $(echo "$RESPONSE" | jq -r '.server.hostname // "unknown"')"
echo "Private IP:  $(echo "$RESPONSE" | jq -r '.server.private_ip // "unknown"')"
echo "Status:      $(echo "$RESPONSE" | jq -r '.server.status // "active"')"
echo "Location:    $(echo "$RESPONSE" | jq -r '.server.location // "default"')"
echo "Config File: $ENROLLED_FILE"
echo "=============================================================="

exit 0
