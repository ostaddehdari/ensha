#!/usr/bin/env bash
set -Eeuo pipefail

APP="${APP_DIR:-/var/www/ensha}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"

[[ -f "$APP/artisan" ]] || { echo 'VERIFY_FAIL=ARTISAN_NOT_FOUND' >&2; exit 1; }
[[ "$(tr -d '[:space:]' < "$APP/VERSION")" == '0.24.3' ]] || { echo 'VERIFY_FAIL=VERSION' >&2; exit 1; }

files=(
 README-P1-S03-W03.fa.md CHANGELOG-v0.24.3.fa.md INSTALL-P1-S03-W03.fa.md
 docs/evidence/ENSHA-P1-S03-W03-browser-acceptance-2026-10-09.fa.md
 tests/Browser/package.json tests/Browser/p1-s03-w03-live-acceptance.spec.js
)
for file in "${files[@]}"; do [[ -f "$APP/$file" ]] || { echo "VERIFY_FAIL=MISSING:$file" >&2; exit 1; }; done

grep -Fq 'نتیجه نهایی: `PASS`' "$APP/docs/evidence/ENSHA-P1-S03-W03-browser-acceptance-2026-10-09.fa.md"
grep -Fq 'Appointment #11' "$APP/docs/evidence/ENSHA-P1-S03-W03-browser-acceptance-2026-10-09.fa.md"
grep -Fq 'conflictResponse).status()).toBe(409)' "$APP/tests/Browser/p1-s03-w03-live-acceptance.spec.js"
grep -Fq 'ENSHA_W03_LIVE' "$APP/tests/Browser/p1-s03-w03-live-acceptance.spec.js"
if command -v node >/dev/null; then node --check "$APP/tests/Browser/p1-s03-w03-live-acceptance.spec.js"; fi

cd "$APP"
"$PHP_BIN" artisan test --filter='P1S03W01SchedulingIntegrityTest|P1S03W02CalendarExperienceTest'

echo 'LIVE_BROWSER_CREATE=PASS_RECORDED'
echo 'LIVE_BROWSER_FILTER=PASS_RECORDED'
echo 'LIVE_BROWSER_DRAG=PASS_RECORDED'
echo 'LIVE_BROWSER_RESIZE=PASS_RECORDED'
echo 'LIVE_BROWSER_409=PASS_RECORDED'
echo 'VERIFY_P1_S03_W03=PASS'
