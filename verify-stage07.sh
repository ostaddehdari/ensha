#!/usr/bin/env bash
set -Eeuo pipefail
APP="${1:-/var/www/ensha}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
[[ -f "$APP/artisan" ]] || { echo 'ENSHA_APP_NOT_FOUND' >&2; exit 1; }
cd "$APP"
"$PHP_BIN" artisan ensha:verify-stage07-complete
echo 'VERIFY_STAGE07_V0190_OK'
