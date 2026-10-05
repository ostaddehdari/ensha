#!/usr/bin/env bash
set -Eeuo pipefail
BACKUP_DIR="${1:-}"
APP_DIR="${2:-/var/www/ensha}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
SERVICE_NAME="${SERVICE_NAME:-ensha-web.service}"
SYSTEMCTL_BIN="${SYSTEMCTL_BIN:-systemctl}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1:18850/up}"
fail() { echo "ROLLBACK_ERROR: $*" >&2; exit 1; }
[[ "${EUID}" -eq 0 ]] || fail "Rollback باید با root اجرا شود."
[[ -n "${BACKUP_DIR}" && -d "${BACKUP_DIR}" ]] || fail "Backup معتبر نیست."
[[ "${APP_DIR}" != "/" && "${APP_DIR}" != "/var" && "${APP_DIR}" != "/var/www" ]] || fail "APP_DIR ناامن است."
[[ -f "${BACKUP_DIR}/app-files.tar.gz" && -f "${BACKUP_DIR}/app-files.sha256" && -f "${BACKUP_DIR}/database.sha256" ]] || fail "Backup کامل برنامه و دیتابیس وجود ندارد."
tar -tzf "${BACKUP_DIR}/app-files.tar.gz" >/dev/null
(cd "${BACKUP_DIR}" && sha256sum -c app-files.sha256)
(cd "${BACKUP_DIR}" && sha256sum -c database.sha256)
"${PHP_BIN}" "${APP_DIR}/artisan" down --retry=30 >/dev/null 2>&1 || true
for path in app bootstrap config database resources routes public deploy systemd nginx tests; do [[ -e "${APP_DIR}/${path}" ]] && rm -rf -- "${APP_DIR:?}/${path}"; done
for file in artisan composer.json composer.lock phpunit.xml README.fa.md CHANGELOG.fa.md VERSION CHANGELOG-v0.15.0.fa.md README-v0.15.0.fa.md INSTALL-STAGE03-COMPLETE.fa.md install-stage03-complete.sh verify-stage03-complete.sh CHANGELOG-v0.16.0.fa.md README-v0.16.0.fa.md INSTALL-STAGE04-COMPLETE.fa.md install-stage04-complete.sh verify-stage04-complete.sh CHANGELOG-v0.17.0.fa.md README-v0.17.0.fa.md INSTALL-STAGE05-COMPLETE.fa.md install-stage05-complete.sh verify-stage05-complete.sh CHANGELOG-v0.18.0.fa.md README-v0.18.0.fa.md INSTALL-STAGE06-COMPLETE.fa.md install-stage06-complete.sh verify-stage06-complete.sh; do [[ -e "${APP_DIR}/${file}" ]] && rm -f -- "${APP_DIR:?}/${file}"; done
tar -xzpf "${BACKUP_DIR}/app-files.tar.gz" -C "${APP_DIR}"
chmod a+rx "${APP_DIR}"
[[ -f "${BACKUP_DIR}/nginx-ensha-location.conf" ]] && install -m 644 "${BACKUP_DIR}/nginx-ensha-location.conf" /etc/nginx/snippets/ensha-location.conf
if [[ -f "${BACKUP_DIR}/ensha-web.service" ]]; then install -m 644 "${BACKUP_DIR}/ensha-web.service" "/etc/systemd/system/${SERVICE_NAME}"; "${SYSTEMCTL_BIN}" daemon-reload; fi
DB_CONFIG_FILE="$(mktemp /run/ensha-rollback-db.XXXXXX.json)"
DB_CLIENT_FILE=""
cleanup_db() { rm -f -- "${DB_CONFIG_FILE}"; [[ -z "${DB_CLIENT_FILE}" ]] || rm -f -- "${DB_CLIENT_FILE}"; }
"${PHP_BIN}" "${APP_DIR}/deploy/database-config.php" "${APP_DIR}" "${DB_CONFIG_FILE}"
db_value() { "${PHP_BIN}" -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d[$argv[2]] ?? "";' "${DB_CONFIG_FILE}" "$1"; }
DB_DRIVER="$(db_value driver)"; DB_NAME="$(db_value database)"
case "${DB_DRIVER}" in
  mysql|mariadb)
    DB_CLIENT_FILE="$(mktemp /run/ensha-rollback-mysql.XXXXXX.cnf)"
    "${PHP_BIN}" -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); $q=fn($v)=>"\"".addcslashes((string)$v,"\\\"")."\""; $s="[client]\nuser=".$q($d["username"])."\npassword=".$q($d["password"])."\nhost=".$q($d["host"])."\n"; if($d["port"]!=="")$s.="port=".$q($d["port"])."\n"; if($d["unix_socket"]!=="")$s.="socket=".$q($d["unix_socket"])."\n"; file_put_contents($argv[2],$s,LOCK_EX); chmod($argv[2],0600);' "${DB_CONFIG_FILE}" "${DB_CLIENT_FILE}"
    # MySQL dump restores tables present at backup time, but does not remove
    # tables created by a migration after that backup. Drop only Stage 06 tables;
    # if they existed in the snapshot, the dump recreates them with their data.
    mysql --defaults-extra-file="${DB_CLIENT_FILE}" "${DB_NAME}" <<'SQL'
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS `client_profile_field_permissions`, `client_record_settings`,
  `staff_pay_rules`, `staff_work_session_audits`, `staff_work_sessions`,
  `counselor_leave_requests`, `appointment_statuses`, `discounts`;
SET FOREIGN_KEY_CHECKS=1;
SQL
    gzip -dc "${BACKUP_DIR}/database.sql.gz" | mysql --defaults-extra-file="${DB_CLIENT_FILE}" "${DB_NAME}";;
  pgsql) gzip -dc "${BACKUP_DIR}/database.sql.gz" | PGPASSWORD="$(db_value password)" psql -h "$(db_value host)" -p "$(db_value port)" -U "$(db_value username)" -d "${DB_NAME}";;
  sqlite) install -m 660 "${BACKUP_DIR}/database.sqlite" "${DB_NAME}";;
  *) cleanup_db; fail "Driver دیتابیس برای Rollback پشتیبانی نمی‌شود: ${DB_DRIVER}";;
esac
cleanup_db
cd "${APP_DIR}"
install -d -m 775 bootstrap/cache
COMPOSER_ALLOW_SUPERUSER=1 "${COMPOSER_BIN}" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
"${PHP_BIN}" artisan optimize:clear
install -d -m 775 storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
"${PHP_BIN}" artisan up
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache
[[ ! -f bootstrap/cache/services.php ]] || runuser -u www-data -- test -r bootstrap/cache/services.php
runuser -u www-data -- test -x "${APP_DIR}"
"${SYSTEMCTL_BIN}" restart "${SERVICE_NAME}"
"${SYSTEMCTL_BIN}" is-active --quiet "${SERVICE_NAME}"
healthOk=false
for attempt in {1..15}; do if curl --fail --silent --max-time 5 "${HEALTH_URL}" >/dev/null; then healthOk=true; break; fi; sleep 1; done
[[ "${healthOk}" == true ]] || fail "Health Check پس از Rollback ناموفق بود."
echo "ROLLBACK_STAGE06_COMPLETE_OK: ${BACKUP_DIR}"
