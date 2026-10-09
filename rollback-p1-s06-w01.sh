#!/usr/bin/env bash
set -Eeuo pipefail

BACKUP="${1:-}"
APP="${2:-${APP_DIR:-/var/www/ensha}}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
SERVICE="${SERVICE_NAME:-ensha-web.service}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1:18850/up}"

[[ $EUID == 0 ]] || { echo 'Rollback must run as root.' >&2; exit 1; }
[[ -n "$BACKUP" && -d "$BACKUP/files" && -f "$BACKUP/files.list" ]] || { echo 'Invalid backup directory.' >&2; exit 1; }
[[ -f "$BACKUP/database-config.json" ]] || { echo 'Database backup configuration not found.' >&2; exit 1; }
[[ -f "$APP/artisan" ]] || { echo 'Ensha application not found.' >&2; exit 1; }

db_value(){ "$PHP_BIN" -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $d[$argv[2]] ?? "";' "$BACKUP/database-config.json" "$1"; }
driver="$(db_value driver)"; database="$(db_value database)"
"$PHP_BIN" "$APP/artisan" down --retry=30 || true

case "$driver" in
    mysql|mariadb)
        command -v mysql >/dev/null || { echo 'mysql client not found.' >&2; exit 1; }
        [[ -f "$BACKUP/database.sql.gz" && -f "$BACKUP/mysql-client.cnf" ]] || { echo 'MySQL backup is incomplete.' >&2; exit 1; }
        gzip -t "$BACKUP/database.sql.gz"
        gzip -dc "$BACKUP/database.sql.gz" | mysql --defaults-extra-file="$BACKUP/mysql-client.cnf" "$database"
        ;;
    pgsql)
        command -v psql >/dev/null || { echo 'psql not found.' >&2; exit 1; }
        gzip -t "$BACKUP/database.sql.gz"
        PGPASSWORD="$(db_value password)" gzip -dc "$BACKUP/database.sql.gz" | PGPASSWORD="$(db_value password)" psql -h "$(db_value host)" -p "$(db_value port)" -U "$(db_value username)" "$database"
        ;;
    sqlite)
        [[ -f "$BACKUP/database.sqlite" ]] || { echo 'SQLite backup not found.' >&2; exit 1; }
        cp -a "$BACKUP/database.sqlite" "$database"
        ;;
    *) echo "Unsupported database driver: $driver" >&2; exit 1 ;;
esac

while IFS= read -r file; do
    [[ -n "$file" ]] || continue
    if [[ -f "$BACKUP/files/$file" ]]; then install -d "$(dirname "$APP/$file")"; cp -a "$BACKUP/files/$file" "$APP/$file"; fi
done < "$BACKUP/files.list"
if [[ -f "$BACKUP/new-files.txt" ]]; then while IFS= read -r file; do [[ -n "$file" ]] && rm -f -- "$APP/$file"; done < "$BACKUP/new-files.txt"; fi

cd "$APP"
"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan config:cache
runuser -u www-data -- "$PHP_BIN" artisan view:cache
chown -R www-data:www-data storage bootstrap/cache
"$PHP_BIN" artisan up
systemctl restart "$SERVICE"
systemctl is-active --quiet "$SERVICE"
health=000
for attempt in {1..15}; do health="$(curl -sS --max-time 5 -o /dev/null -w '%{http_code}' "$HEALTH_URL" || true)"; [[ "$health" == 200 ]] && break; sleep 1; done
[[ "$health" == 200 ]] || { echo 'ROLLBACK_HEALTH_FAILED' >&2; exit 1; }
echo 'ROLLBACK_P1_S06_W01=PASS'
echo "RESTORED_BACKUP=$BACKUP"
echo 'DATABASE_RESTORE=PASS'
