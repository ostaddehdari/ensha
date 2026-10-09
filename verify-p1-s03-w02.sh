#!/usr/bin/env bash
set -Eeuo pipefail

APP="${APP_DIR:-/var/www/ensha}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"

[[ -f "$APP/artisan" ]] || { echo 'VERIFY_FAIL=ARTISAN_NOT_FOUND' >&2; exit 1; }
[[ "$(tr -d '[:space:]' < "$APP/VERSION")" == '0.24.2' ]] || { echo 'VERIFY_FAIL=VERSION' >&2; exit 1; }

files=(
 app/Http/Controllers/SecretaryCalendarController.php app/Models/Appointment.php
 app/Services/AppointmentAvailabilityService.php app/Services/AppointmentRescheduleService.php
 resources/views/appointments/calendar.blade.php public/js/stage06-scheduler.js public/css/stage06.css
 tests/Feature/P1S03W02CalendarExperienceTest.php tests/Browser/p1-s03-w02-calendar.spec.js
)
for file in "${files[@]}"; do [[ -f "$APP/$file" ]] || { echo "VERIFY_FAIL=MISSING:$file" >&2; exit 1; }; done
for file in app/Http/Controllers/SecretaryCalendarController.php app/Models/Appointment.php app/Services/AppointmentAvailabilityService.php app/Services/AppointmentRescheduleService.php tests/Feature/P1S03W02CalendarExperienceTest.php; do
    "$PHP_BIN" -l "$APP/$file" >/dev/null
done
if command -v node >/dev/null; then node --check "$APP/public/js/stage06-scheduler.js"; fi
grep -Fq 'data-edit-drawer' "$APP/resources/views/appointments/calendar.blade.php"
grep -Fq "name=\"branch_id\"" "$APP/resources/views/appointments/calendar.blade.php"
grep -Fq 'jalaliDate' "$APP/app/Http/Controllers/SecretaryCalendarController.php"

cd "$APP"
"$PHP_BIN" artisan route:list --name=appointments.calendar.api.update >/dev/null
"$PHP_BIN" artisan test --filter=P1S03W02CalendarExperienceTest

echo 'VERIFY_P1_S03_W02=PASS'

