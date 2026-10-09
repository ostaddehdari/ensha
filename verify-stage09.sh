#!/usr/bin/env bash
set -Eeuo pipefail
APP="${1:-/var/www/ensha}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
[[ -f "$APP/artisan" ]] || { echo 'ENSHA_APP_NOT_FOUND' >&2; exit 1; }
cd "$APP"
"$PHP_BIN" artisan ensha:verify-stage09-complete
[[ "$(redis-cli ping 2>/dev/null)" == PONG ]]
runuser -u www-data -- test -r "$APP/storage/framework/views"
echo 'VERIFY_STAGE09_V0210_OK'
