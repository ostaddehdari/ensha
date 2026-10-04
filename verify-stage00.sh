#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${1:-/var/www/ensha}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
SERVICE_NAME="${SERVICE_NAME:-ensha-web.service}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1:18850/up}"

[[ -f "${APP_DIR}/artisan" ]] || { echo "Ensha در ${APP_DIR} پیدا نشد." >&2; exit 1; }
[[ "$(tr -d '[:space:]' < "${APP_DIR}/VERSION")" == "0.10.4" ]]
cd "${APP_DIR}"
"${PHP_BIN}" artisan ensha:verify-stage00
systemctl is-active --quiet "${SERVICE_NAME}"
curl --fail --silent --max-time 5 "${HEALTH_URL}" >/dev/null
echo "VERIFY_STAGE00_V0104_OK"
