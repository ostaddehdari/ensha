#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR="${1:-/var/www/ensha}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
[[ "${EUID}" -eq 0 ]] || { echo "verify باید با root اجرا شود." >&2; exit 1; }
[[ -f "${APP_DIR}/artisan" && -f "${APP_DIR}/VERSION" ]] || { echo "نصب Ensha پیدا نشد." >&2; exit 1; }
[[ "$(tr -d '[:space:]' < "${APP_DIR}/VERSION")" == "0.12.0" ]] || { echo "VERSION_STAGE02_INVALID" >&2; exit 1; }
"${PHP_BIN}" "${APP_DIR}/artisan" ensha:verify-stage02
"${PHP_BIN}" "${APP_DIR}/artisan" route:list --name=clients >/dev/null
echo "VERIFY_STAGE02_V0120_OK"
