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
DB_CONFIG_FILE=""
DB_CLIENT_FILE=""

fail() { echo "INSTALL_ERROR: $*" >&2; exit 1; }
cleanup() { [[ -z "${DB_CONFIG_FILE}" ]] || rm -f -- "${DB_CONFIG_FILE}"; [[ -z "${DB_CLIENT_FILE}" ]] || rm -f -- "${DB_CLIENT_FILE}"; }
on_error() { local rc="$1" command="$2" line="$3"; trap - ERR; echo "INSTALL_STEP_FAILED line=${line} command=${command} code=${rc}" >&2; echo "تکمیل Stage 06 شکست خورد (code=${rc})." >&2; if [[ "${INSTALL_STARTED}" == true && -n "${BACKUP_DIR}" ]]; then bash "${SOURCE_DIR}/deploy/rollback-v0.18.0.sh" "${BACKUP_DIR}" "${APP_DIR}" || echo "ROLLBACK_AUTOMATIC_FAILED" >&2; fi; cleanup; exit "${rc}"; }
trap 'on_error "$?" "$BASH_COMMAND" "$LINENO"' ERR
trap cleanup EXIT

[[ "${EUID}" -eq 0 ]] || fail "نصب باید با کاربر root اجرا شود."
[[ "${APP_DIR}" != "/" && "${APP_DIR}" != "/var" && "${APP_DIR}" != "/var/www" ]] || fail "APP_DIR ناامن است."
[[ -x "${PHP_BIN}" ]] || fail "PHP پیدا نشد."
"${PHP_BIN}" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' || fail "PHP 8.3 یا جدیدتر لازم است."
for commandName in tar gzip sha256sum flock rsync curl "${COMPOSER_BIN}"; do command -v "${commandName}" >/dev/null || fail "دستور لازم پیدا نشد: ${commandName}"; done
[[ -f "${SOURCE_DIR}/composer.json" && -f "${SOURCE_DIR}/VERSION" && -f "${SOURCE_DIR}/MANIFEST.sha256" ]] || fail "بسته نصب معتبر نیست."
[[ "$(tr -d '[:space:]' < "${SOURCE_DIR}/VERSION")" == "0.18.0" ]] || fail "نسخه SOURCE باید 0.18.0 باشد."
[[ -f "${APP_DIR}/artisan" && -f "${APP_DIR}/.env" ]] || fail "نصب فعلی Ensha پیدا نشد."
CURRENT_VERSION="$(tr -d '[:space:]' < "${APP_DIR}/VERSION")"
[[ "${CURRENT_VERSION}" == "0.16.0" || "${CURRENT_VERSION}" == "0.17.0" || "${CURRENT_VERSION}" == "0.18.0" ]] || fail "نسخه فعلی باید 0.16.0، 0.17.0 یا 0.18.0 باشد؛ مقدار: ${CURRENT_VERSION}"
exec 9>/run/ensha-stage06-complete-v0.18.0.lock
flock -n 9 || fail "یک نصب دیگر Ensha در حال اجرا است."
curl --fail --silent --max-time 8 "${HEALTH_URL}" >/dev/null || fail "نسخهٔ فعلی پیش از نصب پاسخ سلامت نمی‌دهد."
(cd "${SOURCE_DIR}" && sha256sum -c --quiet MANIFEST.sha256)
while IFS= read -r -d '' phpFile; do "${PHP_BIN}" -l "${phpFile}" >/dev/null; done < <(find "${SOURCE_DIR}/app" "${SOURCE_DIR}/bootstrap" "${SOURCE_DIR}/config" "${SOURCE_DIR}/database" "${SOURCE_DIR}/routes" "${SOURCE_DIR}/tests" -type f -name '*.php' -print0)
while IFS= read -r -d '' shellFile; do bash -n "${shellFile}"; done < <(find "${SOURCE_DIR}" -type f -name '*.sh' -print0)

BACKUP_STAMP="$(date -u +%Y%m%d-%H%M%S)"
BACKUP_DIR="${BACKUP_ROOT}/stage06-complete-v0.18.0-${BACKUP_STAMP}"
install -d -m 700 "${BACKUP_DIR}" "${STATE_DIR}"
cp -a "${SOURCE_DIR}/deploy/rollback-v0.18.0.sh" "${SOURCE_DIR}/deploy/database-config.php" "${BACKUP_DIR}/"
tar -C "${APP_DIR}" --exclude='./.git' --exclude='./vendor' --exclude='./storage' --exclude='./bootstrap/cache/*.php' -czpf "${BACKUP_DIR}/app-files.tar.gz" .
tar -tzf "${BACKUP_DIR}/app-files.tar.gz" >/dev/null
(cd "${BACKUP_DIR}" && sha256sum app-files.tar.gz > app-files.sha256 && sha256sum -c app-files.sha256)
[[ -f /etc/nginx/snippets/ensha-location.conf ]] && cp -a /etc/nginx/snippets/ensha-location.conf "${BACKUP_DIR}/nginx-ensha-location.conf"
[[ -f "/etc/systemd/system/${SERVICE_NAME}" ]] && cp -a "/etc/systemd/system/${SERVICE_NAME}" "${BACKUP_DIR}/ensha-web.service"

DB_CONFIG_FILE="$(mktemp /run/ensha-install-db.XXXXXX.json)"
"${PHP_BIN}" "${SOURCE_DIR}/deploy/database-config.php" "${APP_DIR}" "${DB_CONFIG_FILE}"
db_value() { "${PHP_BIN}" -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d[$argv[2]] ?? "";' "${DB_CONFIG_FILE}" "$1"; }
DB_DRIVER="$(db_value driver)"; DB_NAME="$(db_value database)"
case "${DB_DRIVER}" in
  mysql|mariadb)
    command -v mysqldump >/dev/null || fail "mysqldump لازم است."; command -v mysql >/dev/null || fail "mysql لازم است."
    DB_CLIENT_FILE="$(mktemp /run/ensha-install-mysql.XXXXXX.cnf)"
    "${PHP_BIN}" -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); $q=fn($v)=>"\"".addcslashes((string)$v,"\\\"")."\""; $s="[client]\nuser=".$q($d["username"])."\npassword=".$q($d["password"])."\nhost=".$q($d["host"])."\n"; if($d["port"]!=="")$s.="port=".$q($d["port"])."\n"; if($d["unix_socket"]!=="")$s.="socket=".$q($d["unix_socket"])."\n"; file_put_contents($argv[2],$s,LOCK_EX); chmod($argv[2],0600);' "${DB_CONFIG_FILE}" "${DB_CLIENT_FILE}"
    mysqldump --defaults-extra-file="${DB_CLIENT_FILE}" --no-tablespaces --single-transaction --quick --routines --triggers --events --add-drop-table --default-character-set=utf8mb4 "${DB_NAME}" | gzip -9 > "${BACKUP_DIR}/database.sql.gz"
    gzip -t "${BACKUP_DIR}/database.sql.gz";;
  pgsql) PGPASSWORD="$(db_value password)" pg_dump --clean --if-exists -h "$(db_value host)" -p "$(db_value port)" -U "$(db_value username)" "${DB_NAME}" | gzip -9 > "${BACKUP_DIR}/database.sql.gz"; gzip -t "${BACKUP_DIR}/database.sql.gz";;
  sqlite) [[ -f "${DB_NAME}" ]] || fail "فایل SQLite پیدا نشد."; cp -a "${DB_NAME}" "${BACKUP_DIR}/database.sqlite";;
  *) fail "Driver دیتابیس پشتیبانی نمی‌شود: ${DB_DRIVER}";;
esac
(cd "${BACKUP_DIR}" && sha256sum database.* > database.sha256 && sha256sum -c database.sha256)

INSTALL_STARTED=true
"${PHP_BIN}" "${APP_DIR}/artisan" down --retry=30
rsync -a --exclude='/.git/' --exclude='/.env' --exclude='/vendor/' --exclude='/storage/' --exclude='/bootstrap/cache/' "${SOURCE_DIR}/" "${APP_DIR}/"
# rsync -a also copies the source directory's mode to APP_DIR. A private
# extracted ZIP directory can otherwise make the whole app unreadable to web.
chmod a+rx "${APP_DIR}"
runuser -u www-data -- test -x "${APP_DIR}"
echo "APP_DIRECTORY_TRAVERSABLE_OK"
cmp -s "${SOURCE_DIR}/public/vendor/daypilot/daypilot-javascript.min.js" "${APP_DIR}/public/vendor/daypilot/daypilot-javascript.min.js" || fail "فایل DayPilot در مقصد کپی نشده است."
cd "${APP_DIR}"
COMPOSER_ALLOW_SUPERUSER=1 "${COMPOSER_BIN}" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
"${PHP_BIN}" artisan migrate --force
echo "MIGRATIONS_OK"
"${PHP_BIN}" artisan storage:link || true
"${PHP_BIN}" artisan optimize:clear
"${PHP_BIN}" artisan config:cache
"${PHP_BIN}" artisan route:list >/dev/null
"${PHP_BIN}" artisan view:cache
"${PHP_BIN}" artisan ensha:verify-stage06-complete
install -d -m 775 storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
"${PHP_BIN}" artisan up
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
[[ ! -f bootstrap/cache/services.php ]] || runuser -u www-data -- test -r bootstrap/cache/services.php
chmod -R a+rX public
runuser -u www-data -- test -r public/index.php
echo "WEB_FILE_PERMISSIONS_OK"
"${SYSTEMCTL_BIN}" restart "${SERVICE_NAME}"
"${SYSTEMCTL_BIN}" is-active --quiet "${SERVICE_NAME}"
healthOk=false; for attempt in {1..15}; do status="$(curl --silent --show-error --max-time 5 -o /dev/null -w '%{http_code}' "${HEALTH_URL}" || true)"; echo "HEALTH_ATTEMPT_${attempt}=${status}"; if [[ "${status}" == 200 ]]; then healthOk=true; break; fi; sleep 1; done
[[ "${healthOk}" == true ]] || fail "Health Check ناموفق بود."
printf '%s\n' "${BACKUP_DIR}" > "${STATE_DIR}/stage06-complete-v0.18.0.last-backup"
trap - EXIT
cleanup
echo "INSTALL_STAGE06_COMPLETE_V0180_OK"
echo "BACKUP_DIR=${BACKUP_DIR}"
