#!/usr/bin/env bash
set -Eeuo pipefail
APP="${1:-/var/www/ensha}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
[[ -f "$APP/artisan" ]] || { echo 'ENSHA_APP_NOT_FOUND' >&2; exit 1; }
cd "$APP"
"$PHP_BIN" artisan ensha:verify-stage08-complete
systemctl is-active --quiet ensha-queue.service
runuser -u www-data -- test -r "$APP/storage/app/private/ensha-audio"
echo 'VERIFY_STAGE08_V0200_OK'
