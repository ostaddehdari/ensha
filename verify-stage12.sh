#!/usr/bin/env bash
set -Eeuo pipefail
APP="${1:-/var/www/ensha}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
cd "$APP"
"$PHP_BIN" artisan ensha:verify-stage12-complete
echo VERIFY_STAGE12_COMPLETE_V0240_OK
