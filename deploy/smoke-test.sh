#!/usr/bin/env bash
# =============================================================================
# HPanel Post-Installation Smoke Test Script
# Verifies system services, agent connectivity, live account provisioning,
# file permissions, web server vhost, PHP-FPM pool, and clean teardown.
# =============================================================================

set -Eeuo pipefail

# ANSI Colors
C_RESET=$'\033[0m'
C_RED=$'\033[0;31m'
C_GREEN=$'\033[0;32m'
C_BLUE=$'\033[0;34m'
C_BOLD=$'\033[1m'

APP_DIR="${1:-}"
if [[ -z "$APP_DIR" ]]; then
  if [[ -f "/var/www/hpanel/artisan" ]]; then
    APP_DIR="/var/www/hpanel"
  elif [[ -f "./artisan" ]]; then
    APP_DIR="$(pwd)"
  else
    APP_DIR="/var/www/hpanel"
  fi
fi

ARTISAN="php ${APP_DIR}/artisan"
TEST_USER="smoketest1"
TEST_DOMAIN="smoketest1.test"

RECORD_NAME=()
RECORD_STATUS=()
RECORD_DETAILS=()
OVERALL_FAILED=0
ACCOUNT_CREATED=0

record_result() {
  local name="$1"
  local status="$2"
  local details="$3"

  RECORD_NAME+=("$name")
  RECORD_STATUS+=("$status")
  RECORD_DETAILS+=("$details")

  if [[ "$status" == "FAIL" ]]; then
    OVERALL_FAILED=1
    printf '  [%sFAIL%s] %s: %s\n' "$C_RED" "$C_RESET" "$name" "$details"
  else
    printf '  [%sPASS%s] %s\n' "$C_GREEN" "$C_RESET" "$name"
  fi
}

# shellcheck disable=SC2317
cleanup() {
  if [[ "$ACCOUNT_CREATED" -eq 1 ]]; then
    echo ""
    echo "Teardown: Cleaning up throwaway test account '${TEST_USER}'..."
    local term_payload="{\"username\":\"${TEST_USER}\",\"confirm_token\":\"TERMINATE-${TEST_USER}\",\"take_backup\":false,\"domains\":[\"${TEST_DOMAIN}\"]}"
    $ARTISAN hpanel:agent-dispatch account.terminate --payload="$term_payload" >/dev/null 2>&1 || true
    ACCOUNT_CREATED=0
  fi
}
trap cleanup EXIT

echo "======================================================================"
printf '%s%s  HPanel Post-Install Smoke Verification%s\n' "$C_BLUE" "$C_BOLD" "$C_RESET"
echo "  Target App Directory: ${APP_DIR}"
echo "======================================================================"
echo ""

# -----------------------------------------------------------------------------
# 1. Systemd Active Services Check
# -----------------------------------------------------------------------------
echo "--> Checking Systemd Services..."

# Detect installed PHP version
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo "8.3")

SERVICES=(
  "nginx"
  "mariadb"
  "redis-server"
  "php${PHP_VER}-fpm"
  "hpanel-queue"
  "hpanel-agent"
)

# Allow mariadb or mysql, redis-server or redis
for svc in "${SERVICES[@]}"; do
  actual_svc="$svc"
  if ! systemctl list-unit-files "${actual_svc}.service" &>/dev/null; then
    if [[ "$svc" == "mariadb" ]]; then
      actual_svc="mysql"
    elif [[ "$svc" == "redis-server" ]]; then
      actual_svc="redis"
    fi
  fi

  if systemctl is-active --quiet "$actual_svc"; then
    record_result "service:${actual_svc}" "PASS" "active (running)"
  else
    record_result "service:${actual_svc}" "FAIL" "inactive or failed"
  fi
done

# -----------------------------------------------------------------------------
# 2. Agent Connectivity Check (hpanel:agent-check)
# -----------------------------------------------------------------------------
echo ""
echo "--> Verifying Agent Connectivity & Certificates..."
if $ARTISAN hpanel:agent-check >/tmp/agent_check.log 2>&1; then
  record_result "agent:ping_and_certs" "PASS" "mTLS, HMAC, and system.ping ok"
else
  err_msg=$(tail -n 3 /tmp/agent_check.log | tr '\n' ' ' | sed 's/  */ /g')
  record_result "agent:ping_and_certs" "FAIL" "${err_msg:-Agent check failed}"
fi

# -----------------------------------------------------------------------------
# 3. Live Account Creation via Agent
# -----------------------------------------------------------------------------
echo ""
echo "--> Provisioning Throwaway Account '${TEST_USER}' (${TEST_DOMAIN})..."

CREATE_PAYLOAD="{\"username\":\"${TEST_USER}\",\"primary_domain\":\"${TEST_DOMAIN}\",\"php_version\":\"${PHP_VER}\",\"storage_mb\":1024,\"inodes\":50000,\"php_workers\":5,\"php_memory_mb\":128}"

if $ARTISAN hpanel:agent-dispatch account.create --payload="$CREATE_PAYLOAD" >/tmp/agent_create.log 2>&1; then
  ACCOUNT_CREATED=1
  record_result "agent:account.create" "PASS" "account created via signed agent action"
else
  err_msg=$(tail -n 3 /tmp/agent_create.log | tr '\n' ' ' | sed 's/  */ /g')
  record_result "agent:account.create" "FAIL" "${err_msg:-account.create failed}"
fi

# -----------------------------------------------------------------------------
# 4. Asserts for Provisioned Account
# -----------------------------------------------------------------------------
if [[ "$ACCOUNT_CREATED" -eq 1 ]]; then
  echo ""
  echo "--> Running Provisioning Invariant Assertions..."

  # 1. Linux user exists
  if id "$TEST_USER" >/dev/null 2>&1; then
    record_result "assert:linux_user" "PASS" "user '${TEST_USER}' exists"
  else
    record_result "assert:linux_user" "FAIL" "user '${TEST_USER}' not found in /etc/passwd"
  fi

  # 2. /home/smoketest1 has mode 0711
  HOME_PATH="/home/${TEST_USER}"
  if [[ -d "$HOME_PATH" ]]; then
    HOME_MODE=$(stat -c '%a' "$HOME_PATH" 2>/dev/null || echo "")
    if [[ "$HOME_MODE" == "711" || "$HOME_MODE" == "0711" ]]; then
      record_result "assert:home_permissions" "PASS" "${HOME_PATH} mode ${HOME_MODE}"
    else
      record_result "assert:home_permissions" "FAIL" "${HOME_PATH} mode is ${HOME_MODE}, expected 0711"
    fi
  else
    record_result "assert:home_permissions" "FAIL" "Directory ${HOME_PATH} does not exist"
  fi

  # 3. public_html owned by user, mode 0755
  HTML_PATH="/home/${TEST_USER}/public_html"
  if [[ -d "$HTML_PATH" ]]; then
    HTML_MODE=$(stat -c '%a' "$HTML_PATH" 2>/dev/null || echo "")
    HTML_OWNER=$(stat -c '%U:%G' "$HTML_PATH" 2>/dev/null || echo "")

    if [[ ("$HTML_MODE" == "755" || "$HTML_MODE" == "0755") && "$HTML_OWNER" == "${TEST_USER}:${TEST_USER}" ]]; then
      record_result "assert:public_html" "PASS" "${HTML_PATH} mode ${HTML_MODE} owned by ${HTML_OWNER}"
    else
      record_result "assert:public_html" "FAIL" "${HTML_PATH} mode=${HTML_MODE} owner=${HTML_OWNER}, expected 0755 ${TEST_USER}:${TEST_USER}"
    fi
  else
    record_result "assert:public_html" "FAIL" "Directory ${HTML_PATH} does not exist"
  fi

  # 4. PHP-FPM pool file present, php-fpm<ver> -t returns ok
  POOL_PATH="/etc/php/${PHP_VER}/fpm/pool.d/${TEST_USER}.conf"
  if [[ -f "$POOL_PATH" ]]; then
    FPM_BIN=""
    if command -v "php-fpm${PHP_VER}" >/dev/null 2>&1; then
      FPM_BIN="php-fpm${PHP_VER}"
    elif [[ -x "/usr/sbin/php-fpm${PHP_VER}" ]]; then
      FPM_BIN="/usr/sbin/php-fpm${PHP_VER}"
    elif command -v php-fpm >/dev/null 2>&1; then
      FPM_BIN="php-fpm"
    fi

    if [[ -n "$FPM_BIN" ]]; then
      if "$FPM_BIN" -t >/dev/null 2>&1; then
        record_result "assert:php_fpm_pool" "PASS" "pool exists and ${FPM_BIN} -t passed"
      else
        record_result "assert:php_fpm_pool" "FAIL" "${FPM_BIN} -t validation failed"
      fi
    else
      record_result "assert:php_fpm_pool" "PASS" "pool file present (${POOL_PATH})"
    fi
  else
    record_result "assert:php_fpm_pool" "FAIL" "Pool file ${POOL_PATH} not found"
  fi

  # 5. Nginx vhost enabled, nginx -t returns ok
  VHOST_ENABLED="/etc/nginx/sites-enabled/${TEST_DOMAIN}.conf"
  if [[ -L "$VHOST_ENABLED" || -f "$VHOST_ENABLED" ]]; then
    if nginx -t >/dev/null 2>&1; then
      record_result "assert:nginx_vhost" "PASS" "vhost linked in sites-enabled and nginx -t passed"
    else
      record_result "assert:nginx_vhost" "FAIL" "nginx -t configuration test failed"
    fi
  else
    record_result "assert:nginx_vhost" "FAIL" "Nginx vhost ${VHOST_ENABLED} not found"
  fi

  # 6. curl -H "Host: smoketest1.test" http://127.0.0.1/ returns HTTP 200 with default page
  HTTP_CODE=$(curl -s -o /tmp/smoke_http_body.html -w '%{http_code}' -H "Host: ${TEST_DOMAIN}" http://127.0.0.1/ || true)
  if [[ "$HTTP_CODE" == "200" ]]; then
    record_result "assert:vhost_http_200" "PASS" "GET / with Host ${TEST_DOMAIN} returned HTTP 200"
  else
    record_result "assert:vhost_http_200" "FAIL" "GET / returned HTTP ${HTTP_CODE}, expected 200"
  fi

  # 7. curl with unknown Host returns empty reply (444)
  UNKNOWN_HOST="nonexistent-smoke-$(date +%s).invalid"
  set +e
  UNKNOWN_CODE=$(curl -s -o /dev/null -w '%{http_code}' -H "Host: ${UNKNOWN_HOST}" http://127.0.0.1/)
  CURL_EXIT=$?
  set -e
  # Nginx return 444 closes connection immediately: curl exit code 52 (empty reply) or code 000/444
  if [[ "$CURL_EXIT" -eq 52 || "$UNKNOWN_CODE" == "000" || "$UNKNOWN_CODE" == "444" ]]; then
    record_result "assert:unknown_host_444" "PASS" "unknown Host returned empty reply / 444"
  else
    record_result "assert:unknown_host_444" "FAIL" "unknown Host returned code=${UNKNOWN_CODE} curl_exit=${CURL_EXIT}, expected 444/empty reply"
  fi

  # ---------------------------------------------------------------------------
  # 5. Clean Teardown via account.terminate
  # ---------------------------------------------------------------------------
  echo ""
  echo "--> Tearing down test account '${TEST_USER}'..."
  TERM_PAYLOAD="{\"username\":\"${TEST_USER}\",\"confirm_token\":\"TERMINATE-${TEST_USER}\",\"take_backup\":false,\"domains\":[\"${TEST_DOMAIN}\"]}"
  if $ARTISAN hpanel:agent-dispatch account.terminate --payload="$TERM_PAYLOAD" >/tmp/agent_term.log 2>&1; then
    ACCOUNT_CREATED=0
    # Verify cleanup
    if ! id "$TEST_USER" >/dev/null 2>&1 && [[ ! -f "$VHOST_ENABLED" ]] && [[ ! -f "$POOL_PATH" ]]; then
      record_result "agent:account.terminate" "PASS" "clean teardown verified (user, vhost, pool removed)"
    else
      record_result "agent:account.terminate" "FAIL" "terminate completed but leftover files remain"
    fi
  else
    err_msg=$(tail -n 3 /tmp/agent_term.log | tr '\n' ' ' | sed 's/  */ /g')
    record_result "agent:account.terminate" "FAIL" "${err_msg:-account.terminate failed}"
  fi
fi

# -----------------------------------------------------------------------------
# Summary Table
# -----------------------------------------------------------------------------
echo ""
echo "======================================================================"
echo "  SMOKE TEST RESULTS SUMMARY"
echo "======================================================================"
printf ' %-3s | %-28s | %-6s | %s\n' "#" "Check" "Status" "Details"
echo "-----+------------------------------+--------+------------------------"
for i in "${!RECORD_NAME[@]}"; do
  status_color="$C_GREEN"
  if [[ "${RECORD_STATUS[$i]}" == "FAIL" ]]; then
    status_color="$C_RED"
  fi
  printf ' %-3d | %-28s | %s%-6s%s | %s\n' \
    "$((i + 1))" \
    "${RECORD_NAME[$i]:0:28}" \
    "$status_color" "${RECORD_STATUS[$i]}" "$C_RESET" \
    "${RECORD_DETAILS[$i]}"
done
echo "-----+------------------------------+--------+------------------------"

if [[ "$OVERALL_FAILED" -eq 0 ]]; then
  printf '\n%s%sALL SMOKE TESTS PASSED!%s System is fully operational.\n\n' "$C_GREEN" "$C_BOLD" "$C_RESET"
  exit 0
else
  printf '\n%s%sSMOKE TEST FAILURES DETECTED!%s Review the errors above.\n\n' "$C_RED" "$C_BOLD" "$C_RESET"
  exit 1
fi
