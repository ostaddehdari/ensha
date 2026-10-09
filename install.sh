#!/usr/bin/env bash
set -Eeuo pipefail

APP="${APP_DIR:-/var/www/ensha}"
PKG="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
SERVICE="${SERVICE_NAME:-ensha-web.service}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1:18850/up}"
EXPECTED_VERSION="0.23.0"
BACKUP=""; INSTALL_STARTED=0; COMMITTED=0; DB_CONFIG=""; DB_CLIENT=""

files=(
 VERSION README-v0.24.0.fa.md CHANGELOG-v0.24.0.fa.md INSTALL-STAGE12.fa.md verify-stage12.sh rollback.sh install.sh composer.json composer.lock phpunit.xml
 app/Http/Controllers/IntegrationController.php app/Http/Controllers/PayrollReportController.php app/Http/Controllers/SecretaryCalendarController.php app/Http/Controllers/SessionRecordingController.php app/Http/Controllers/WordPressApiController.php
 app/Http/Controllers/CounsellingCaseController.php app/Jobs/TranscribeSessionRecording.php app/Models/CentreIntegration.php app/Models/NoteAddendum.php app/Models/SessionRecording.php app/Models/SmsMessage.php app/Models/StaffPayrollRun.php app/Models/StaffPayrollRunAudit.php app/Models/User.php
 app/Services/AudioRetentionService.php app/Services/PayrollService.php app/Services/SmsProviderService.php
 bootstrap/app.php config/panels.php database/migrations/2026_10_09_000025_stage12_stabilization_integrations_retention.php database/seeders/DatabaseSeeder.php
 deploy/ensha-scheduler.service deploy/ensha-scheduler.timer integrations/wordpress/ensha-booking.php
 public/css/stage06.css public/js/stage06-scheduler.js resources/views/appointments/calendar.blade.php resources/views/centres/integrations.blade.php resources/views/counselor/recording-retention.blade.php resources/views/counselor/session-report.blade.php resources/views/layouts/sidebar.blade.php resources/views/reports/payroll.blade.php resources/views/reports/payroll-run.blade.php
 routes/api.php routes/console.php routes/web.php tests/TestCase.php tests/Feature/AccessControlTest.php tests/Feature/ProfileBuilderTest.php tests/Feature/ProfileDraftTest.php tests/Feature/SelfProfileTest.php tests/Feature/Stage03AppointmentEngineTest.php tests/Feature/Stage06SchedulingTest.php tests/Feature/Stage11ReportsPayrollTest.php tests/Feature/Stage12StabilizationTest.php tests/Browser/package.json tests/Browser/stage12-calendar.spec.js
)

log(){ printf '[%s] %s\n' "$(date -u +%FT%TZ)" "$*"; }
fail(){ log "ERROR=$*" >&2; exit 1; }
cleanup(){ [[ -z "$DB_CONFIG" ]] || rm -f -- "$DB_CONFIG"; [[ -z "$DB_CLIENT" ]] || rm -f -- "$DB_CLIENT"; }
finish(){
    rc=$?; trap - EXIT; cleanup
    if ((rc!=0 && INSTALL_STARTED==1 && COMMITTED==0)); then
        log 'خطا پس از شروع نصب؛ اجرای rollback خودکار'
        bash "$PKG/rollback.sh" "$BACKUP" "$APP" || log 'AUTOMATIC_ROLLBACK_FAILED'
    fi
    ((rc==0)) && log 'RESULT=SUCCESS' || log 'RESULT=FAILED'
    log "EXIT_CODE=$rc"
    exit "$rc"
}
trap finish EXIT

[[ $EUID == 0 ]] || fail 'نصاب باید با root اجرا شود.'
[[ "$APP" != / && "$APP" != /var && "$APP" != /var/www ]] || fail 'APP_DIR ناامن است.'
[[ -f "$APP/artisan" && -f "$APP/.env" && -d "$APP/.git" ]] || fail 'نصب معتبر Ensha پیدا نشد.'
for command_name in "$PHP_BIN" "$COMPOSER_BIN" git sha256sum flock curl gzip redis-cli systemctl runuser; do
    command -v "$command_name" >/dev/null || fail "دستور لازم پیدا نشد: $command_name"
done
exec 9>/run/ensha-stage12-v0240.lock
flock -n 9 || fail 'نصب دیگری در حال اجرا است.'

log 'بررسی بسته، سرویس‌ها و خط مبنای Git'
(cd "$PKG" && sha256sum -c MANIFEST.sha256)
[[ "$(tr -d '[:space:]' < "$APP/VERSION")" == "$EXPECTED_VERSION" ]] || fail "نسخه فعلی باید $EXPECTED_VERSION باشد."
[[ -z "$(git -C "$APP" status --porcelain)" ]] || fail 'DIRTY_WORKTREE'
git -C "$APP" fetch origin main
before="$(git -C "$APP" rev-parse HEAD)"; remote="$(git -C "$APP" rev-parse origin/main)"
[[ "$before" == "$remote" ]] || fail 'LOCAL_REMOTE_COMMIT_MISMATCH'
log "LOCAL_BEFORE=$before"
curl --fail --silent --max-time 8 "$HEALTH_URL" >/dev/null || fail 'HEALTH_BEFORE_FAILED'
[[ "$(redis-cli ping 2>/dev/null)" == PONG ]] || fail 'REDIS_NOT_READY'
"$PHP_BIN" -r 'exit(class_exists("ZipArchive")?0:1);' || fail 'PHP_ZIP_EXTENSION_NOT_AVAILABLE'
for file in "${files[@]}"; do [[ -f "$PKG/$file" ]] || fail "PACKAGE_FILE_MISSING=$file"; done
while IFS= read -r -d '' php_file; do "$PHP_BIN" -l "$php_file" >/dev/null; done < <(find "$PKG/app" "$PKG/config" "$PKG/database" "$PKG/routes" "$PKG/tests" -type f -name '*.php' -print0)
bash -n "$PKG/install.sh" "$PKG/rollback.sh" "$PKG/verify-stage12.sh"
"$PHP_BIN" -l "$PKG/integrations/wordpress/ensha-booking.php" >/dev/null

log 'نصب PHPUnit 11 از composer.lock پیش از هر تغییر Stage 12'
cd "$APP"
COMPOSER_ALLOW_SUPERUSER=1 "$COMPOSER_BIN" install --no-interaction --prefer-dist --optimize-autoloader
[[ -x vendor/bin/phpunit ]] || fail 'PHPUNIT_INSTALL_FAILED'
chown -R www-data:www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 775 {} +
find storage bootstrap/cache -type f -exec chmod 664 {} +
vendor/bin/phpunit --version
log 'PHPUNIT_INSTALLED=OK'
log 'خرابی‌های خط مبنای v0.23.0 تشخیص داده شد؛ اصلاحات خط مبنا همراه Stage 12 اعمال و سپس کل تست‌ها اجرا می‌شوند.'

BACKUP="/var/backups/ensha/stage12-v0.24.0-$(date -u +%Y%m%d-%H%M%S)"
install -d -m 700 "$BACKUP/files"; : > "$BACKUP/new-files.txt"; : > "$BACKUP/files.list"
for file in "${files[@]}"; do
    printf '%s\n' "$file" >> "$BACKUP/files.list"
    if [[ -f "$APP/$file" ]]; then
        install -D -m 600 "$APP/$file" "$BACKUP/files/$file"
    else
        printf '%s\n' "$file" >> "$BACKUP/new-files.txt"
    fi
done
(cd "$BACKUP" && find files -type f -print0 | sort -z | xargs -0 sha256sum > files.sha256)

log 'تهیه نسخه پشتیبان دیتابیس'
DB_CONFIG="$(mktemp /run/ensha-stage12-db.XXXXXX.json)"
"$PHP_BIN" "$APP/deploy/database-config.php" "$APP" "$DB_CONFIG"
db_value(){ "$PHP_BIN" -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d[$argv[2]] ?? "";' "$DB_CONFIG" "$1"; }
driver="$(db_value driver)"; database="$(db_value database)"; printf '%s' "$driver" > "$BACKUP/db-driver.txt"
case "$driver" in
    mysql|mariadb)
        command -v mysqldump >/dev/null || fail 'mysqldump پیدا نشد.'
        DB_CLIENT="$(mktemp /run/ensha-stage12-mysql.XXXXXX.cnf)"
        "$PHP_BIN" -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);$q=fn($v)=>"\"".addcslashes((string)$v,"\\\"")."\"";$s="[client]\nuser=".$q($d["username"])."\npassword=".$q($d["password"])."\nhost=".$q($d["host"])."\n";if($d["port"]!=="")$s.="port=".$q($d["port"])."\n";if($d["unix_socket"]!=="")$s.="socket=".$q($d["unix_socket"])."\n";file_put_contents($argv[2],$s,LOCK_EX);chmod($argv[2],0600);' "$DB_CONFIG" "$DB_CLIENT"
        mysqldump --defaults-extra-file="$DB_CLIENT" --no-tablespaces --single-transaction --quick --routines --triggers --events --add-drop-table --default-character-set=utf8mb4 "$database" | gzip -9 > "$BACKUP/database.sql.gz"
        gzip -t "$BACKUP/database.sql.gz"
        ;;
    pgsql)
        command -v pg_dump >/dev/null || fail 'pg_dump پیدا نشد.'
        PGPASSWORD="$(db_value password)" pg_dump --clean --if-exists -h "$(db_value host)" -p "$(db_value port)" -U "$(db_value username)" "$database" | gzip -9 > "$BACKUP/database.sql.gz"
        gzip -t "$BACKUP/database.sql.gz"
        ;;
    sqlite)
        [[ -f "$database" ]] || fail 'فایل SQLite پیدا نشد.'
        cp -a "$database" "$BACKUP/database.sqlite"
        ;;
    *) fail "درایور دیتابیس پشتیبانی نمی‌شود: $driver" ;;
esac
(cd "$BACKUP" && sha256sum database.* > database.sha256 && sha256sum -c database.sha256)
log "BACKUP_DIR=$BACKUP"

log 'نصب Stage 12، migration و تست اجباری کامل'
"$PHP_BIN" "$APP/artisan" down --retry=30
INSTALL_STARTED=1
for file in "${files[@]}"; do
    mode=644; [[ "$file" == *.sh ]] && mode=755
    install -D -m "$mode" "$PKG/$file" "$APP/$file"
done
cd "$APP"
COMPOSER_ALLOW_SUPERUSER=1 "$COMPOSER_BIN" install --no-interaction --prefer-dist --optimize-autoloader
"$PHP_BIN" artisan migrate --force
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan route:list --name=api.wordpress >/dev/null
"$PHP_BIN" artisan route:list --name=recordings.retention >/dev/null
install -d -m 775 storage/app/private/report-exports storage/app/private/ensha-audio storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 775 {} +
find storage bootstrap/cache -type f -exec chmod 664 {} +
runuser -u www-data -- "$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan ensha:verify-stage12-complete
"$PHP_BIN" artisan test --testsuite=Application
log 'BASELINE_AND_STAGE12_PHPUNIT=PASS'
if command -v node >/dev/null; then node --check tests/Browser/stage12-calendar.spec.js; fi
log 'BROWSER_DRAG_DROP_TEST=PACKAGED (برای اجرای واقعی، URL و حساب منشی لازم است)'
"$PHP_BIN" artisan config:cache
runuser -u www-data -- "$PHP_BIN" artisan view:cache
install -m 644 deploy/ensha-scheduler.service /etc/systemd/system/ensha-scheduler.service
install -m 644 deploy/ensha-scheduler.timer /etc/systemd/system/ensha-scheduler.timer
systemctl daemon-reload
systemctl enable --now ensha-scheduler.timer
systemctl is-active --quiet ensha-scheduler.timer
"$PHP_BIN" artisan up
systemctl restart "$SERVICE"; systemctl is-active --quiet "$SERVICE"
health=000
for attempt in {1..15}; do
    health="$(curl -sS --max-time 5 -o /dev/null -w '%{http_code}' "$HEALTH_URL" || true)"
    log "HEALTH_ATTEMPT_$attempt=$health"
    [[ "$health" == 200 ]] && break
    sleep 1
done
[[ "$health" == 200 ]] || fail 'HEALTH_AFTER_FAILED'
[[ "$(redis-cli ping 2>/dev/null)" == PONG ]] || fail 'REDIS_AFTER_FAILED'

log 'ثبت commit و ارسال به GitHub'
git add -- "${files[@]}"
git commit -m 'fix(stage-12): stabilize calendar payroll integrations and audio retention v0.24.0'
COMMITTED=1; INSTALL_STARTED=0
git push origin HEAD:main; git fetch origin main
local_commit="$(git rev-parse HEAD)"; remote_commit="$(git rev-parse origin/main)"
[[ "$local_commit" == "$remote_commit" ]] || fail 'REMOTE_COMMIT_VERIFICATION_FAILED'
log 'VERSION=0.24.0'; log "LOCAL_COMMIT=$local_commit"; log "REMOTE_COMMIT=$remote_commit"
log 'PHPUNIT=INSTALLED_AND_PASS'; log 'DAYPILOT=MONTH_FILTER_MOVE_RESIZE'; log 'PAYROLL=HARDENED'; log 'INTEGRATIONS=CONFIGURABLE'; log 'AUDIO_RETENTION=ACTIVE'
