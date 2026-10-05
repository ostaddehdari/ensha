#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR="${1:-/var/www/ensha}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
[[ "${EUID}" -eq 0 ]] || { echo "verify باید با root اجرا شود." >&2; exit 1; }
[[ -f "${APP_DIR}/artisan" && -f "${APP_DIR}/VERSION" ]] || { echo "نصب Ensha پیدا نشد." >&2; exit 1; }
[[ "$(tr -d '[:space:]' < "${APP_DIR}/VERSION")" == "0.16.0" ]] || { echo "VERSION_STAGE04_COMPLETE_INVALID" >&2; exit 1; }
"${PHP_BIN}" "${APP_DIR}/artisan" ensha:verify-stage04-complete
"${PHP_BIN}" "${APP_DIR}/artisan" route:list --name=appointments.calendar >/dev/null
"${PHP_BIN}" "${APP_DIR}/artisan" route:list --name=appointments.calendar.api.events >/dev/null
"${PHP_BIN}" "${APP_DIR}/artisan" route:list --name=appointments.calendar.api.update >/dev/null
echo "VERIFY_STAGE04_COMPLETE_V0160_OK"
