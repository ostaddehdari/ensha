#!/usr/bin/env bash
set -Eeuo pipefail

APP="${APP_DIR:-/var/www/ensha}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"

[[ -f "$APP/artisan" ]] || { echo 'VERIFY_FAIL=ARTISAN_NOT_FOUND' >&2; exit 1; }
[[ "$(tr -d '[:space:]' < "$APP/VERSION")" == '0.25.0' ]] || { echo 'VERIFY_FAIL=VERSION' >&2; exit 1; }

files=(
 app/Http/Controllers/DailyOperationsController.php
 app/Services/AppointmentBookingService.php
 app/Services/AppointmentRescheduleService.php
 resources/views/operations/index.blade.php
 tests/Feature/P1S07W01OperationalCycleTest.php
 tests/Browser/package.json
 tests/Browser/p1-s07-w01-operational-cycle.spec.js
)
for file in "${files[@]}"; do [[ -f "$APP/$file" ]] || { echo "VERIFY_FAIL=MISSING:$file" >&2; exit 1; }; done
for file in app/Http/Controllers/DailyOperationsController.php app/Services/AppointmentBookingService.php app/Services/AppointmentRescheduleService.php tests/Feature/P1S07W01OperationalCycleTest.php; do
    "$PHP_BIN" -l "$APP/$file" >/dev/null
done
grep -Fq 'تأیید خودکار هنگام پذیرش' "$APP/app/Http/Controllers/DailyOperationsController.php"
grep -Fq 'صف پذیرش‌شده‌ها' "$APP/resources/views/operations/index.blade.php"
grep -Fq "['cancelled', 'no_show']" "$APP/app/Services/AppointmentBookingService.php"
grep -Fq 'ENSHA_W01_LIVE' "$APP/tests/Browser/p1-s07-w01-operational-cycle.spec.js"
if command -v node >/dev/null; then node --check "$APP/tests/Browser/p1-s07-w01-operational-cycle.spec.js"; fi

cd "$APP"
"$PHP_BIN" artisan route:list --name=operations.check-in >/dev/null
"$PHP_BIN" artisan route:list --name=operations.waitlist.promote >/dev/null
"$PHP_BIN" artisan view:clear >/dev/null
"$PHP_BIN" artisan view:cache >/dev/null
"$PHP_BIN" artisan test --filter=P1S07W01OperationalCycleTest

echo 'PENDING_CHECK_IN=PASS'
echo 'ROLE_OPERATIONAL_CYCLE=PASS'
echo 'NO_SHOW_CANCEL_RELEASE=PASS'
echo 'RESCHEDULE_HISTORY=PASS'
echo 'WAITLIST_PROMOTION=PASS'
echo 'VERIFY_P1_S07_W01=PASS'
