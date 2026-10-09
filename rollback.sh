#!/usr/bin/env bash
set -Eeuo pipefail
BACKUP="${1:-}"
APP="${2:-/var/www/ensha}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
SERVICE="${SERVICE_NAME:-ensha-web.service}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1:18850/up}"
DB_CONFIG=""; DB_CLIENT=""
fail(){ echo "ROLLBACK_ERROR: $*" >&2; exit 1; }
cleanup(){ [[ -z "$DB_CONFIG" ]] || rm -f -- "$DB_CONFIG"; [[ -z "$DB_CLIENT" ]] || rm -f -- "$DB_CLIENT"; }
trap cleanup EXIT
[[ $EUID == 0 ]] || fail 'Rollback باید با root اجرا شود.'
[[ -n "$BACKUP" && -d "$BACKUP" && -f "$BACKUP/files.list" && -f "$BACKUP/database.sha256" ]] || fail 'نسخه پشتیبان معتبر نیست.'
(cd "$BACKUP" && sha256sum -c files.sha256 && sha256sum -c database.sha256)
"$PHP_BIN" "$APP/artisan" down --retry=30 >/dev/null 2>&1 || true
while IFS= read -r file; do
  if [[ -f "$BACKUP/files/$file" ]]; then mode=644; [[ "$file" == *.sh ]] && mode=755; install -D -m "$mode" "$BACKUP/files/$file" "$APP/$file"
  elif grep -Fqx -- "$file" "$BACKUP/new-files.txt"; then rm -f -- "$APP/$file"; fi
done < "$BACKUP/files.list"
DB_CONFIG="$(mktemp /run/ensha-stage10-rollback-db.XXXXXX.json)"
"$PHP_BIN" "$APP/deploy/database-config.php" "$APP" "$DB_CONFIG"
db_value(){ "$PHP_BIN" -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d[$argv[2]] ?? "";' "$DB_CONFIG" "$1"; }
driver="$(db_value driver)"; database="$(db_value database)"
case "$driver" in
  mysql|mariadb)
    DB_CLIENT="$(mktemp /run/ensha-stage10-rollback-mysql.XXXXXX.cnf)"
    "$PHP_BIN" -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);$q=fn($v)=>"\"".addcslashes((string)$v,"\\\"")."\"";$s="[client]\nuser=".$q($d["username"])."\npassword=".$q($d["password"])."\nhost=".$q($d["host"])."\n";if($d["port"]!=="")$s.="port=".$q($d["port"])."\n";if($d["unix_socket"]!=="")$s.="socket=".$q($d["unix_socket"])."\n";file_put_contents($argv[2],$s,LOCK_EX);chmod($argv[2],0600);' "$DB_CONFIG" "$DB_CLIENT"
    mysql --defaults-extra-file="$DB_CLIENT" "$database" <<'SQL'
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS `counselor_settlement_adjustments`, `counselor_settlement_items`, `counselor_settlements`, `appointment_compensation_snapshots`, `compensation_rules`;
SET FOREIGN_KEY_CHECKS=1;
SQL
    gzip -dc "$BACKUP/database.sql.gz" | mysql --defaults-extra-file="$DB_CLIENT" "$database";;
  pgsql)
    PGPASSWORD="$(db_value password)" psql -h "$(db_value host)" -p "$(db_value port)" -U "$(db_value username)" -d "$database" -c 'DROP TABLE IF EXISTS counselor_settlement_adjustments, counselor_settlement_items, counselor_settlements, appointment_compensation_snapshots, compensation_rules CASCADE;'
    gzip -dc "$BACKUP/database.sql.gz" | PGPASSWORD="$(db_value password)" psql -h "$(db_value host)" -p "$(db_value port)" -U "$(db_value username)" -d "$database";;
  sqlite) install -m 660 "$BACKUP/database.sqlite" "$database";;
  *) fail "درایور دیتابیس پشتیبانی نمی‌شود: $driver";;
esac
cd "$APP"
COMPOSER_ALLOW_SUPERUSER=1 "$COMPOSER_BIN" dump-autoload --no-dev --classmap-authoritative --no-interaction
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
install -d -m 775 storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 775 {} +
find storage bootstrap/cache -type f -exec chmod 664 {} +
runuser -u www-data -- "$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan up
systemctl restart "$SERVICE"
for attempt in {1..15}; do curl --fail --silent --max-time 5 "$HEALTH_URL" >/dev/null && { echo "ROLLBACK_STAGE10_OK=$BACKUP"; exit 0; }; sleep 1; done
fail 'Health Check پس از rollback ناموفق بود.'
