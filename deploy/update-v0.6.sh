#!/usr/bin/env bash
set -euo pipefail

# ارتقای نصب موجود بدون دست‌زدن به .env، vendor، storage یا داده‌های موجود.
SOURCE_DIR="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
APP_DIR="${2:-/var/www/ensha}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
SERVICE_NAME="${SERVICE_NAME:-ensha-web.service}"
SYSTEMCTL_BIN="${SYSTEMCTL_BIN:-systemctl}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/ensha}"
HEALTH_URL="${HEALTH_URL:-http://127.0.0.1:18850/up}"

if [[ "${EUID}" -ne 0 ]]; then
  echo "این بروزرسانی باید با sudo یا کاربر root اجرا شود." >&2
  exit 1
fi
if [[ ! -x "${PHP_BIN}" ]]; then
  echo "PHP قابل اجرا در مسیر ${PHP_BIN} پیدا نشد." >&2
  exit 1
fi
if ! "${PHP_BIN}" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);'; then
  echo "برای این نسخه PHP 8.3 یا جدیدتر لازم است." >&2
  exit 1
fi
if [[ ! -f "${SOURCE_DIR}/composer.json" || ! -f "${SOURCE_DIR}/VERSION" ]]; then
  echo "بسته بروزرسانی معتبر نیست: ${SOURCE_DIR}" >&2
  exit 1
fi
if [[ ! -f "${APP_DIR}/artisan" || ! -f "${APP_DIR}/.env" || ! -f "${APP_DIR}/database/migrations/2026_09_23_000007_expand_profile_fields.php" ]]; then
  echo "نصب فعلی انشا در ${APP_DIR} پیدا نشد." >&2
  exit 1
fi

while IFS= read -r -d '' php_file; do
  "${PHP_BIN}" -l "${php_file}" >/dev/null
done < <(find "${SOURCE_DIR}/app" "${SOURCE_DIR}/bootstrap" "${SOURCE_DIR}/config" "${SOURCE_DIR}/database" "${SOURCE_DIR}/routes" "${SOURCE_DIR}/tests" -type f -name '*.php' -print0)

BACKUP_STAMP="$(date -u +%Y%m%d-%H%M%S)"
BACKUP_FILE="${BACKUP_DIR}/ensha-before-v0.6-${BACKUP_STAMP}.tar.gz"
install -d -m 750 "${BACKUP_DIR}"
backup_paths=()
for path in app bootstrap config database/migrations database/seeders resources routes public deploy systemd nginx tests composer.json composer.lock phpunit.xml README.fa.md CHANGELOG.fa.md VERSION; do
  if [[ -e "${APP_DIR}/${path}" ]]; then
    backup_paths+=("${path}")
  fi
done
tar \
  --exclude='bootstrap/cache/*' \
  --exclude='storage/*' \
  --exclude='vendor/*' \
  --exclude='.env' \
  -czf "${BACKUP_FILE}" \
  -C "${APP_DIR}" \
  "${backup_paths[@]}"

cd "${APP_DIR}"
"${PHP_BIN}" artisan down --retry=30 || true
trap 'cd "${APP_DIR}" && "${PHP_BIN}" artisan up >/dev/null 2>&1 || true' EXIT

for path in app bootstrap config database/migrations database/seeders resources routes public/css public/js public/assets deploy systemd nginx tests composer.json phpunit.xml README.fa.md CHANGELOG.fa.md VERSION; do
  if [[ -e "${SOURCE_DIR}/${path}" ]]; then
    mkdir -p "${APP_DIR}/$(dirname "${path}")"
    cp -a "${SOURCE_DIR}/${path}" "${APP_DIR}/$(dirname "${path}")/"
  fi
done

install -d -m 775 \
  "${APP_DIR}/bootstrap/cache" \
  "${APP_DIR}/storage/framework/cache" \
  "${APP_DIR}/storage/framework/sessions" \
  "${APP_DIR}/storage/framework/views" \
  "${APP_DIR}/storage/logs"

cd "${APP_DIR}"
if command -v "${COMPOSER_BIN}" >/dev/null 2>&1; then
  if [[ -f composer.lock ]]; then
    COMPOSER_ALLOW_SUPERUSER=1 "${COMPOSER_BIN}" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
  else
    COMPOSER_ALLOW_SUPERUSER=1 "${COMPOSER_BIN}" dump-autoload --optimize --no-dev
  fi
fi

"${PHP_BIN}" artisan migrate --force
"${PHP_BIN}" artisan optimize:clear
"${PHP_BIN}" artisan view:cache
"${PHP_BIN}" artisan route:list --except-vendor >/dev/null

if [[ "${SKIP_OWNERSHIP:-false}" != true ]]; then
  chown -R www-data:www-data "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
fi
chmod -R ug+rwX "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"
chmod -R a+rX "${APP_DIR}/public"

"${SYSTEMCTL_BIN}" restart "${SERVICE_NAME}"
"${SYSTEMCTL_BIN}" is-active --quiet "${SERVICE_NAME}"
if command -v curl >/dev/null 2>&1; then
  health_ok=false
  for _attempt in {1..10}; do
    if curl --fail --silent --max-time 5 "${HEALTH_URL}" >/dev/null; then
      health_ok=true
      break
    fi
    sleep 1
  done
  if [[ "${health_ok}" != true ]]; then
    echo "سرویس اجرا شده است اما پاسخ سلامت از پورت 18850 دریافت نشد." >&2
    exit 1
  fi
fi

"${PHP_BIN}" artisan up
trap - EXIT

echo "Ensha v0.6 با فرم‌ساز یکپارچهٔ AJAX نصب شد."
echo "نسخه پشتیبان فایل‌های قبلی: ${BACKUP_FILE}"
