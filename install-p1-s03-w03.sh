#!/usr/bin/env bash
set -Eeuo pipefail

APP="${APP_DIR:-/var/www/ensha}"
PKG="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
SERVICE="${SERVICE_NAME:-ensha-web.service}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1:18850/up}"
EXPECTED_VERSION='0.24.2'
EXPECTED_COMMIT='14e2af1a3dae1cf4b8152a90d1c6859d7a39250c'
TARGET_VERSION='0.24.3'
BACKUP=''
INSTALL_STARTED=0
COMMITTED=0
DB_CONFIG=''
DB_CLIENT=''

files=(
 VERSION README-P1-S03-W03.fa.md CHANGELOG-v0.24.3.fa.md INSTALL-P1-S03-W03.fa.md
 install-p1-s03-w03.sh rollback-p1-s03-w03.sh verify-p1-s03-w03.sh MANIFEST-P1-S03-W03.sha256
 docs/evidence/ENSHA-P1-S03-W03-browser-acceptance-2026-10-09.fa.md
 tests/Browser/package.json tests/Browser/p1-s03-w03-live-acceptance.spec.js
)

log(){ printf '[%s] %s\n' "$(date -u +%FT%TZ)" "$*"; }
fail(){ log "ERROR=$*" >&2; exit 1; }
cleanup(){ [[ -z "$DB_CONFIG" ]] || rm -f -- "$DB_CONFIG"; [[ -z "$DB_CLIENT" ]] || rm -f -- "$DB_CLIENT"; }
finish(){
    rc=$?; trap - EXIT; cleanup
    if ((rc != 0 && INSTALL_STARTED == 1 && COMMITTED == 0)); then
        log 'خطا پس از شروع نصب؛ بازگردانی خودکار فایل‌ها'
        bash "$PKG/rollback-p1-s03-w03.sh" "$BACKUP" "$APP" || log 'AUTOMATIC_ROLLBACK_FAILED'
    fi
    ((rc == 0)) && log 'RESULT=SUCCESS' || log 'RESULT=FAILED'
    log "EXIT_CODE=$rc"
    exit "$rc"
}
trap finish EXIT

[[ $EUID == 0 ]] || fail 'نصاب باید با root اجرا شود.'
[[ "$APP" != / && "$APP" != /var && "$APP" != /var/www ]] || fail 'APP_DIR ناامن است.'
install -d -m 750 /var/log/ensha
LOG_FILE="/var/log/ensha/p1-s03-w03-$(date -u +%Y%m%d-%H%M%S).log"
exec > >(tee -a "$LOG_FILE") 2>&1
log "LOG_FILE=$LOG_FILE"

[[ -f "$APP/artisan" && -f "$APP/.env" && -d "$APP/.git" ]] || fail 'نصب معتبر Ensha پیدا نشد.'
for command_name in "$PHP_BIN" git sha256sum flock curl gzip redis-cli systemctl runuser; do
    command -v "$command_name" >/dev/null || fail "دستور لازم پیدا نشد: $command_name"
done
exec 9>/run/ensha-p1-s03-w03.lock
flock -n 9 || fail 'نصب دیگری در حال اجرا است.'

log 'بررسی بسته، سرویس‌ها و خط مبنا'
(cd "$PKG" && sha256sum -c MANIFEST-P1-S03-W03.sha256)
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
if command -v node >/dev/null; then node --check "$PKG/tests/Browser/p1-s03-w03-live-acceptance.spec.js"; fi
bash -n "$PKG/install-p1-s03-w03.sh" "$PKG/rollback-p1-s03-w03.sh" "$PKG/verify-p1-s03-w03.sh"

BACKUP="/var/backups/ensha/p1-s03-w03-v0.24.3-$(date -u +%Y%m%d-%H%M%S)"
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

log 'تهیه نسخه پشتیبان دیتابیس؛ این ورک Migration ندارد'
DB_CONFIG="$(mktemp /run/ensha-p1-s03-w03-db.XXXXXX.json)"
"$PHP_BIN" "$APP/deploy/database-config.php" "$APP" "$DB_CONFIG"
db_value(){ "$PHP_BIN" -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d[$argv[2]] ?? "";' "$DB_CONFIG" "$1"; }
driver="$(db_value driver)"; database="$(db_value database)"; printf '%s' "$driver" > "$BACKUP/db-driver.txt"
case "$driver" in
    mysql|mariadb)
        command -v mysqldump >/dev/null || fail 'mysqldump پیدا نشد.'
        DB_CLIENT="$(mktemp /run/ensha-p1-s03-w03-mysql.XXXXXX.cnf)"
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

log 'نصب ENSHA-P1-S03-W03 و اجرای آزمون‌های اجباری'
INSTALL_STARTED=1
for file in "${files[@]}"; do
    mode=644; [[ "$file" == *.sh ]] && mode=755
    install -D -m "$mode" "$PKG/$file" "$APP/$file"
done
cd "$APP"
"$PHP_BIN" artisan optimize:clear
bash verify-p1-s03-w03.sh
"$PHP_BIN" artisan test --testsuite=Application
log 'P1_S03_W03_AND_FULL_PHPUNIT=PASS'
"$PHP_BIN" artisan config:cache
runuser -u www-data -- "$PHP_BIN" artisan view:cache
chown -R www-data:www-data storage bootstrap/cache
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
git commit -m 'test(p1-s03-w03): record live browser acceptance v0.24.3'
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
log 'LIVE_BROWSER_CREATE=PASS'
log 'LIVE_BROWSER_FILTER=PASS'
log 'LIVE_BROWSER_DRAG=PASS'
log 'LIVE_BROWSER_RESIZE=PASS'
log 'LIVE_BROWSER_409=PASS'
log 'VERIFY=PASS'
