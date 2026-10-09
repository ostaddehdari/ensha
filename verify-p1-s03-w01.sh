#!/usr/bin/env bash
set -Eeuo pipefail

APP="${APP_DIR:-/var/www/ensha}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"

[[ -f "$APP/artisan" ]] || { echo 'VERIFY_FAIL=ARTISAN_NOT_FOUND' >&2; exit 1; }
[[ "$(tr -d '[:space:]' < "$APP/VERSION")" == '0.24.1' ]] || { echo 'VERIFY_FAIL=VERSION' >&2; exit 1; }

files=(
 app/Http/Controllers/SecretaryCalendarController.php
 app/Services/AppointmentAvailabilityService.php
 app/Services/AppointmentBookingService.php
 app/Services/AppointmentRescheduleService.php
 app/Services/AppointmentResourceLockService.php
 config/appointments.php
 tests/Feature/P1S03W01SchedulingIntegrityTest.php
)
for file in "${files[@]}"; do
    [[ -f "$APP/$file" ]] || { echo "VERIFY_FAIL=MISSING:$file" >&2; exit 1; }
    "$PHP_BIN" -l "$APP/$file" >/dev/null
done

grep -Fq 'ensha:schedule:' "$APP/app/Services/AppointmentResourceLockService.php"
grep -Fq 'assignAvailableRoom' "$APP/app/Services/AppointmentAvailabilityService.php"
grep -Fq 'moveToRange' "$APP/app/Services/AppointmentRescheduleService.php"

cd "$APP"
"$PHP_BIN" artisan route:list --name=appointments.calendar.api.update >/dev/null
"$PHP_BIN" artisan test --filter=P1S03W01SchedulingIntegrityTest

echo 'VERIFY_P1_S03_W01=PASS'
