#!/usr/bin/env bash
set -Eeuo pipefail

APP="${APP_DIR:-/var/www/ensha}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"

[[ -f "$APP/artisan" ]] || { echo 'VERIFY_FAIL=ARTISAN_NOT_FOUND' >&2; exit 1; }
[[ "$(tr -d '[:space:]' < "$APP/VERSION")" == '0.26.0' ]] || { echo 'VERIFY_FAIL=VERSION' >&2; exit 1; }

files=(
 app/Http/Middleware/VerifyWordPressBridgeRequest.php
 app/Http/Controllers/WordPressApiController.php
 database/migrations/2026_10_09_000027_secure_wordpress_bridge.php
 bootstrap/app.php routes/api.php integrations/wordpress/ensha-booking.php
 tests/Feature/P1S06W01SecureWordPressBridgeTest.php
)
for file in "${files[@]}"; do [[ -f "$APP/$file" ]] || { echo "VERIFY_FAIL=MISSING:$file" >&2; exit 1; }; done
for file in "${files[@]}"; do "$PHP_BIN" -l "$APP/$file" >/dev/null; done

grep -Fq 'X-Ensha-Signature' "$APP/integrations/wordpress/ensha-booking.php"
grep -Fq 'X-Ensha-Idempotency-Key' "$APP/integrations/wordpress/ensha-booking.php"
grep -Fq "'wordpress.bridge'" "$APP/bootstrap/app.php"

cd "$APP"
"$PHP_BIN" artisan migrate:status | grep -Fq '2026_10_09_000027_secure_wordpress_bridge'
"$PHP_BIN" artisan route:list -v --name=api.wordpress.availability | grep -Fq 'wordpress.bridge'
"$PHP_BIN" artisan route:list -v --name=api.wordpress.book | grep -Fq 'wordpress.bridge'
"$PHP_BIN" artisan test --filter=P1S06W01SecureWordPressBridgeTest

"$PHP_BIN" -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); foreach(["wordpress_request_nonces","wordpress_idempotency_keys"] as $table){if(!Illuminate\Support\Facades\Schema::hasTable($table)){fwrite(STDERR,"VERIFY_FAIL=TABLE:$table\n");exit(1);}} echo "WORDPRESS_BRIDGE_TABLES=PASS\n";'

echo 'WORDPRESS_HMAC_SIGNATURE=PASS'
echo 'WORDPRESS_REPLAY_PROTECTION=PASS'
echo 'WORDPRESS_IDEMPOTENCY=PASS'
echo 'VERIFY_P1_S06_W01=PASS'
