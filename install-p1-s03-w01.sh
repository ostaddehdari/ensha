#!/usr/bin/env bash
set -Eeuo pipefail

APP="${APP_DIR:-/var/www/ensha}"
PKG="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
SERVICE="${SERVICE_NAME:-ensha-web.service}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1:18850/up}"
EXPECTED_VERSION='0.24.0'
EXPECTED_COMMIT='fc1bd975907879db6034e99cebe8ae941e10be63'
TARGET_VERSION='0.24.1'
BACKUP=''
INSTALL_STARTED=0
COMMITTED=0
DB_CONFIG=''
DB_CLIENT=''

files=(
 VERSION README-P1-S03-W01.fa.md CHANGELOG-v0.24.1.fa.md INSTALL-P1-S03-W01.fa.md
 install-p1-s03-w01.sh rollback-p1-s03-w01.sh verify-p1-s03-w01.sh MANIFEST-P1-S03-W01.sha256
 app/Http/Controllers/SecretaryCalendarController.php
 app/Services/AppointmentAvailabilityService.php app/Services/AppointmentBookingService.php
 app/Services/AppointmentRescheduleService.php app/Services/AppointmentResourceLockService.php
 config/appointments.php tests/Feature/P1S03W01SchedulingIntegrityTest.php
)

log(){ printf '[%s] %s\n' "$(date -u +%FT%TZ)" "$*"; }
fail(){ log "ERROR=$*" >&2; exit 1; }
cleanup(){ [[ -z "$DB_CONFIG" ]] || rm -f -- "$DB_CONFIG"; [[ -z "$DB_CLIENT" ]] || rm -f -- "$DB_CLIENT"; }
finish(){
    rc=$?; trap - EXIT; cleanup
    if ((rc != 0 && INSTALL_STARTED == 1 && COMMITTED == 0)); then
        log 'خطا پس از شروع نصب؛ بازگردانی خودکار فایل‌ها'
        bash "$PKG/rollback-p1-s03-w01.sh" "$BACKUP" "$APP" || log 'AUTOMATIC_ROLLBACK_FAILED'
    fi
    ((rc == 0)) && log 'RESULT=SUCCESS' || log 'RESULT=FAILED'
    log "EXIT_CODE=$rc"
    exit "$rc"
}
trap finish EXIT

[[ $EUID == 0 ]] || fail 'نصاب باید با root اجرا شود.'
[[ "$APP" != / && "$APP" != /var && "$APP" != /var/www ]] || fail 'APP_DIR ناامن است.'
install -d -m 750 /var/log/ensha
LOG_FILE="/var/log/ensha/p1-s03-w01-$(date -u +%Y%m%d-%H%M%S).log"
exec > >(tee -a "$LOG_FILE") 2>&1
log "LOG_FILE=$LOG_FILE"

[[ -f "$APP/artisan" && -f "$APP/.env" && -d "$APP/.git" ]] || fail 'نصب معتبر Ensha پیدا نشد.'
for command_name in "$PHP_BIN" "$COMPOSER_BIN" git sha256sum flock curl gzip redis-cli systemctl runuser; do
    command -v "$command_name" >/dev/null || fail "دستور لازم پیدا نشد: $command_name"
done
exec 9>/run/ensha-p1-s03-w01.lock
flock -n 9 || fail 'نصب دیگری در حال اجرا است.'

log 'بررسی بسته، سرویس‌ها و خط مبنا'
(cd "$PKG" && sha256sum -c MANIFEST-P1-S03-W01.sha256)
[[ "$(tr -d '[:space:]' < "$APP/VERSION")" == "$EXPECTED_VERSION" ]] || fail "نسخه فعلی باید $EXPECTED_VERSION باشد."
[[ -z "$(git -C "$APP" status --porcelain)" ]] || fail 'DIRTY_WORKTREE'
git -C "$APP" fetch origin main
before="$(git -C "$APP" rev-parse HEAD)"
remote="$(git -C "$APP" rev-parse origin/main)"
[[ "$before" == "$EXPECTED_COMMIT" ]] || fail "UNEXPECTED_LOCAL_COMMIT=$before"
[[ "$remote" == "$EXPECTED_COMMIT" ]] || fail "UNEXPECTED_REMOTE_COMMIT=$remote"
curl --fail --silent --max-time 8 "$HEALTH_URL" >/dev/null || fail 'HEALTH_BEFORE_FAILED'
[[ "$(redis-cli ping 2>/dev/null)" == PONG ]] || fail 'REDIS_NOT_READY'
for file in "${files[@]}"; do [[ -f "$PKG/$file" ]] || fail "PACKAGE_FILE_MISSING=$file"; done
while IFS= read -r -d '' php_file; do "$PHP_BIN" -l "$php_file" >/dev/null; done < <(find "$PKG/app" "$PKG/config" "$PKG/tests" -type f -name '*.php' -print0)
bash -n "$PKG/install-p1-s03-w01.sh" "$PKG/rollback-p1-s03-w01.sh" "$PKG/verify-p1-s03-w01.sh"

BACKUP="/var/backups/ensha/p1-s03-w01-v0.24.1-$(date -u +%Y%m%d-%H%M%S)"
install -d -m 700 "$BACKUP/files"
: > "$BACKUP/new-files.txt"
: > "$BACKUP/files.list"
for file in "${files[@]}"; do
    printf '%s\n' "$file" >> "$BACKUP/files.list"
    if [[ -f "$APP/$file" ]]; then
        install -d -m 700 "$(dirname "$BACKUP/files/$file")"
        cp -a "$APP/$file" "$BACKUP/files/$file"
    else
        printf '%s\n' "$file" >> "$BACKUP/new-files.txt"
    fi
done
(cd "$BACKUP" && find files -type f -print0 | sort -z | xargs -0 sha256sum > files.sha256)

log 'تهیه نسخه پشتیبان دیتابیس؛ این ورک Migration ندارد و Rollback دیتابیس اجرا نمی‌کند'
DB_CONFIG="$(mktemp /run/ensha-p1-s03-w01-db.XXXXXX.json)"
"$PHP_BIN" "$APP/deploy/database-config.php" "$APP" "$DB_CONFIG"
db_value(){ "$PHP_BIN" -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d[$argv[2]] ?? "";' "$DB_CONFIG" "$1"; }
driver="$(db_value driver)"; database="$(db_value database)"; printf '%s' "$driver" > "$BACKUP/db-driver.txt"
case "$driver" in
    mysql|mariadb)
        command -v mysqldump >/dev/null || fail 'mysqldump پیدا نشد.'
        DB_CLIENT="$(mktemp /run/ensha-p1-s03-w01-mysql.XXXXXX.cnf)"
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

log 'نصب ENSHA-P1-S03-W01 و اجرای آزمون‌های اجباری'
"$PHP_BIN" "$APP/artisan" down --retry=30
INSTALL_STARTED=1
for file in "${files[@]}"; do
    mode=644; [[ "$file" == *.sh ]] && mode=755
    install -D -m "$mode" "$PKG/$file" "$APP/$file"
done
cd "$APP"
COMPOSER_ALLOW_SUPERUSER=1 "$COMPOSER_BIN" install --no-interaction --prefer-dist --optimize-autoloader
"$PHP_BIN" artisan optimize:clear
chown -R www-data:www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 775 {} +
find storage bootstrap/cache -type f -exec chmod 664 {} +
bash verify-p1-s03-w01.sh
"$PHP_BIN" artisan test --testsuite=Application
log 'P1_S03_W01_AND_FULL_PHPUNIT=PASS'
"$PHP_BIN" artisan config:cache
runuser -u www-data -- "$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan up
systemctl restart "$SERVICE"
systemctl is-active --quiet "$SERVICE"
health=000
for attempt in {1..15}; do
    health="$(curl -sS --max-time 5 -o /dev/null -w '%{http_code}' "$HEALTH_URL" || true)"
    log "HEALTH_ATTEMPT_$attempt=$health"
    [[ "$health" == 200 ]] && break
    sleep 1
done
[[ "$health" == 200 ]] || fail 'HEALTH_AFTER_FAILED'
[[ "$(redis-cli ping 2>/dev/null)" == PONG ]] || fail 'REDIS_AFTER_FAILED'

log 'ثبت Commit و ارسال به GitHub'
git add -- "${files[@]}"
git commit -m 'fix(p1-s03-w01): unify appointment resource collision controls v0.24.1'
COMMITTED=1
INSTALL_STARTED=0
git push origin HEAD:main
git fetch origin main
local_commit="$(git rev-parse HEAD)"
remote_commit="$(git rev-parse origin/main)"
[[ "$local_commit" == "$remote_commit" ]] || fail 'REMOTE_COMMIT_VERIFICATION_FAILED'

log "VERSION=$TARGET_VERSION"
log "LOCAL_COMMIT=$local_commit"
log "REMOTE_COMMIT=$remote_commit"
log 'RESOURCE_LOCKS=COUNSELOR_SLOT_ROOM_POOL'
log 'SCHEDULING_RULES=SHIFT_EXCEPTION_LEAVE_BREAK_ROOM_CAPACITY'
log 'VERIFY=PASS'
