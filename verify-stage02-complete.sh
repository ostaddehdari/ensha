#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR="${1:-/var/www/ensha}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
[[ "${EUID}" -eq 0 ]] || { echo "verify باید با root اجرا شود." >&2; exit 1; }
[[ -f "${APP_DIR}/artisan" && -f "${APP_DIR}/VERSION" ]] || { echo "نصب Ensha پیدا نشد." >&2; exit 1; }
[[ "$(tr -d '[:space:]' < "${APP_DIR}/VERSION")" == "0.14.0" ]] || { echo "VERSION_STAGE02_COMPLETE_INVALID" >&2; exit 1; }
"${PHP_BIN}" "${APP_DIR}/artisan" ensha:verify-stage02-complete
"${PHP_BIN}" "${APP_DIR}/artisan" route:list --name=clients.intakes.store >/dev/null
"${PHP_BIN}" "${APP_DIR}/artisan" route:list --name=cases.sessions.store >/dev/null
"${PHP_BIN}" "${APP_DIR}/artisan" route:list --name=private-files.download >/dev/null
"${PHP_BIN}" "${APP_DIR}/artisan" route:list --name=clients.merge >/dev/null
echo "VERIFY_STAGE02_COMPLETE_V0140_OK"
