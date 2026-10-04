#!/usr/bin/env bash
set -Eeuo pipefail

SOURCE_DIR="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
APP_DIR="${2:-/var/www/ensha}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
SERVICE_NAME="${SERVICE_NAME:-ensha-web.service}"
SYSTEMCTL_BIN="${SYSTEMCTL_BIN:-systemctl}"
BACKUP_ROOT="${BACKUP_ROOT:-/var/backups/ensha}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1:18850/up}"
STATE_DIR="${STATE_DIR:-/var/lib/ensha/deployments}"
BACKUP_DIR=""
INSTALL_STARTED=false

fail() { echo "INSTALL_ERROR: $*" >&2; exit 1; }

on_error() {
    local rc=$?
    trap - ERR
    echo "نصب Stage 00 شکست خورد (code=${rc})." >&2
    if [[ "${INSTALL_STARTED}" == true && -n "${BACKUP_DIR}" ]]; then
        echo "Rollback خودکار از ${BACKUP_DIR} آغاز شد." >&2
        bash "${SOURCE_DIR}/deploy/rollback-v0.10.4.sh" "${BACKUP_DIR}" "${APP_DIR}" || echo "ROLLBACK_AUTOMATIC_FAILED" >&2
    fi
    exit "${rc}"
}
trap on_error ERR

[[ "${EUID}" -eq 0 ]] || fail "نصب باید با کاربر root اجرا شود."
[[ "${APP_DIR}" != "/" && "${APP_DIR}" != "/var" && "${APP_DIR}" != "/var/www" ]] || fail "APP_DIR ناامن است."
[[ -x "${PHP_BIN}" ]] || fail "PHP در ${PHP_BIN} پیدا نشد."
"${PHP_BIN}" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' || fail "PHP 8.3 یا جدیدتر لازم است."
for requiredCommand in tar gzip sha256sum flock rsync curl "${COMPOSER_BIN}"; do
    command -v "${requiredCommand}" >/dev/null || fail "دستور لازم پیدا نشد: ${requiredCommand}"
done
[[ -f "${SOURCE_DIR}/composer.json" && -f "${SOURCE_DIR}/VERSION" ]] || fail "بسته نصب معتبر نیست."
[[ "$(tr -d '[:space:]' < "${SOURCE_DIR}/VERSION")" == "0.10.4" ]] || fail "نسخه SOURCE باید 0.10.4 باشد."
[[ -f "${APP_DIR}/artisan" && -f "${APP_DIR}/.env" ]] || fail "نصب فعلی Ensha در ${APP_DIR} پیدا نشد."
CURRENT_VERSION="$(tr -d '[:space:]' < "${APP_DIR}/VERSION")"
[[ "${CURRENT_VERSION}" == "0.10.3" || "${CURRENT_VERSION}" == "0.10.4" ]] || fail "نسخه فعلی باید 0.10.3 یا 0.10.4 باشد؛ مقدار فعلی: ${CURRENT_VERSION}"
[[ -f "${SOURCE_DIR}/MANIFEST.sha256" ]] || fail "MANIFEST.sha256 موجود نیست."

exec 9>/run/ensha-stage00-v0.10.4.lock
flock -n 9 || fail "یک نصب دیگر Ensha در حال اجرا است."

(cd "${SOURCE_DIR}" && sha256sum -c MANIFEST.sha256)
while IFS= read -r -d '' phpFile; do "${PHP_BIN}" -l "${phpFile}" >/dev/null; done < <(find "${SOURCE_DIR}/app" "${SOURCE_DIR}/bootstrap" "${SOURCE_DIR}/config" "${SOURCE_DIR}/database" "${SOURCE_DIR}/routes" "${SOURCE_DIR}/tests" -type f -name '*.php' -print0)
while IFS= read -r -d '' shellFile; do bash -n "${shellFile}"; done < <(find "${SOURCE_DIR}" -type f -name '*.sh' -print0)

BACKUP_STAMP="$(date -u +%Y%m%d-%H%M%S)"
BACKUP_DIR="${BACKUP_ROOT}/stage00-v0.10.4-${BACKUP_STAMP}"
install -d -m 700 "${BACKUP_DIR}" "${STATE_DIR}"
cp -a "${SOURCE_DIR}/deploy/rollback-v0.10.4.sh" "${SOURCE_DIR}/deploy/database-config.php" "${BACKUP_DIR}/"

tar -C "${APP_DIR}" \
    --exclude='./vendor' --exclude='./storage' --exclude='./bootstrap/cache' \
    -czpf "${BACKUP_DIR}/app-files.tar.gz" .
[[ -f /etc/nginx/snippets/ensha-location.conf ]] && cp -a /etc/nginx/snippets/ensha-location.conf "${BACKUP_DIR}/nginx-ensha-location.conf"
[[ -f "/etc/systemd/system/${SERVICE_NAME}" ]] && cp -a "/etc/systemd/system/${SERVICE_NAME}" "${BACKUP_DIR}/ensha-web.service"

DB_CONFIG_FILE="$(mktemp /run/ensha-install-db.XXXXXX.json)"
DB_CLIENT_FILE=""
cleanup_secrets() { rm -f -- "${DB_CONFIG_FILE}" ${DB_CLIENT_FILE:+"${DB_CLIENT_FILE}"}; }
trap 'cleanup_secrets' EXIT
"${PHP_BIN}" "${SOURCE_DIR}/deploy/database-config.php" "${APP_DIR}" "${DB_CONFIG_FILE}"
db_value() { "${PHP_BIN}" -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d[$argv[2]] ?? "";' "${DB_CONFIG_FILE}" "$1"; }
DB_DRIVER="$(db_value driver)"
DB_NAME="$(db_value database)"

case "${DB_DRIVER}" in
    mysql|mariadb)
        command -v mysqldump >/dev/null || fail "mysqldump برای Backup دیتابیس لازم است."
        command -v mysql >/dev/null || fail "mysql برای Rollback دیتابیس لازم است."
        DB_CLIENT_FILE="$(mktemp /run/ensha-install-mysql.XXXXXX.cnf)"
        "${PHP_BIN}" -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); $q=fn($v)=>"\"".addcslashes((string)$v,"\\\"")."\""; $s="[client]\nuser=".$q($d["username"])."\npassword=".$q($d["password"])."\nhost=".$q($d["host"])."\n"; if($d["port"]!=="")$s.="port=".$q($d["port"])."\n"; if($d["unix_socket"]!=="")$s.="socket=".$q($d["unix_socket"])."\n"; file_put_contents($argv[2],$s,LOCK_EX); chmod($argv[2],0600);' "${DB_CONFIG_FILE}" "${DB_CLIENT_FILE}"
        mysqldump --defaults-extra-file="${DB_CLIENT_FILE}" --single-transaction --routines --triggers --events --add-drop-table "${DB_NAME}" | gzip -9 > "${BACKUP_DIR}/database.sql.gz"
        ;;
    pgsql)
        command -v pg_dump >/dev/null || fail "pg_dump برای Backup دیتابیس لازم است."
        command -v psql >/dev/null || fail "psql برای Rollback دیتابیس لازم است."
        PGPASSWORD="$(db_value password)" pg_dump --clean --if-exists -h "$(db_value host)" -p "$(db_value port)" -U "$(db_value username)" "${DB_NAME}" | gzip -9 > "${BACKUP_DIR}/database.sql.gz"
        ;;
    sqlite)
        [[ -f "${DB_NAME}" ]] || fail "فایل SQLite پیدا نشد."
        cp -a "${DB_NAME}" "${BACKUP_DIR}/database.sqlite"
        ;;
    *) fail "Driver دیتابیس برای Backup پشتیبانی نمی‌شود: ${DB_DRIVER}" ;;
esac

printf 'stage=00\ntarget_version=0.10.4\ncreated_at_utc=%s\napp_dir=%s\ndatabase_driver=%s\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${APP_DIR}" "${DB_DRIVER}" > "${BACKUP_DIR}/backup.meta"

INSTALL_STARTED=true
"${PHP_BIN}" "${APP_DIR}/artisan" down --retry=30 || true

rsync -a --exclude='.env' --exclude='vendor/' --exclude='storage/' "${SOURCE_DIR}/" "${APP_DIR}/"
cd "${APP_DIR}"
COMPOSER_ALLOW_SUPERUSER=1 "${COMPOSER_BIN}" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
"${PHP_BIN}" artisan migrate --force
"${PHP_BIN}" artisan storage:link || true
"${PHP_BIN}" artisan optimize:clear
"${PHP_BIN}" artisan config:cache
"${PHP_BIN}" artisan route:list >/dev/null
"${PHP_BIN}" artisan view:cache
"${PHP_BIN}" artisan ensha:verify-stage00

install -d -m 775 storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chattr -R -i storage bootstrap/cache 2>/dev/null || true
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
chmod -R a+rX public

"${SYSTEMCTL_BIN}" restart "${SERVICE_NAME}"
"${SYSTEMCTL_BIN}" is-active --quiet "${SERVICE_NAME}"
healthOk=false
for attempt in {1..15}; do
    if curl --fail --silent --max-time 5 "${HEALTH_URL}" >/dev/null; then healthOk=true; break; fi
    sleep 1
done
[[ "${healthOk}" == true ]] || fail "Health Check از ${HEALTH_URL} پاسخ موفق نگرفت."

"${PHP_BIN}" artisan up
printf '%s\n' "${BACKUP_DIR}" > "${STATE_DIR}/stage00-v0.10.4.last-backup"
cleanup_secrets
trap - ERR EXIT
echo "INSTALL_STAGE00_V0104_OK"
echo "BACKUP_DIR=${BACKUP_DIR}"
