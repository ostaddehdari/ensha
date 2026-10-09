#!/usr/bin/env bash
set -Eeuo pipefail

APP="${1:-/var/www/ensha}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"

[[ -f "$APP/artisan" ]] || { echo 'ENSHA_APP_NOT_FOUND' >&2; exit 1; }
command -v "$PHP_BIN" >/dev/null || { echo 'PHP_NOT_FOUND' >&2; exit 1; }
cd "$APP"
"$PHP_BIN" artisan ensha:verify-stage11-complete
[[ "$(redis-cli ping 2>/dev/null)" == PONG ]]
runuser -u www-data -- test -r "$APP/storage/framework/views"
runuser -u www-data -- test -w "$APP/storage/app/private"
echo 'VERIFY_STAGE11_V0230_OK'
