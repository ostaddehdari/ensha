#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${1:-/var/www/ensha}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

if [[ "${EUID}" -ne 0 ]]; then
  echo "این نصب‌کننده باید با sudo یا کاربر root اجرا شود." >&2
  exit 1
fi
if [[ ! -x "${PHP_BIN}" ]]; then
  echo "PHP قابل اجرا در مسیر ${PHP_BIN} پیدا نشد." >&2
  exit 1
fi
if ! "${PHP_BIN}" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);'; then
  echo "برای نصب این نسخه PHP 8.3 یا جدیدتر لازم است." >&2
  exit 1
fi
if ! command -v "${COMPOSER_BIN}" >/dev/null 2>&1; then
  echo "Composer پیدا نشد. مسیر آن را با COMPOSER_BIN مشخص کنید." >&2
  exit 1
fi
if [[ ! -f "${APP_DIR}/composer.json" ]]; then
  echo "composer.json در ${APP_DIR} پیدا نشد. ابتدا فایل پروژه را در این مسیر استخراج کنید." >&2
  exit 1
fi

install -d -m 775 "${APP_DIR}/bootstrap/cache" "${APP_DIR}/storage/framework/cache" "${APP_DIR}/storage/framework/sessions" "${APP_DIR}/storage/framework/views" "${APP_DIR}/storage/logs"
install -d -m 775 "${APP_DIR}/database"

if [[ ! -f "${APP_DIR}/.env" ]]; then
  cp "${APP_DIR}/.env.example" "${APP_DIR}/.env"
fi

if grep -q '^DB_CONNECTION=sqlite' "${APP_DIR}/.env" 2>/dev/null && [[ ! -f "${APP_DIR}/database/database.sqlite" ]]; then
  touch "${APP_DIR}/database/database.sqlite"
fi

cd "${APP_DIR}"
if [[ -f "composer.lock" ]]; then
  COMPOSER_ALLOW_SUPERUSER=1 "${COMPOSER_BIN}" install --no-dev --prefer-dist --optimize-autoloader --no-interaction
else
  COMPOSER_ALLOW_SUPERUSER=1 "${COMPOSER_BIN}" update --no-dev --with-all-dependencies --prefer-dist --optimize-autoloader --no-interaction
fi
if ! grep -Eq '^APP_KEY=base64:.+' .env; then
  "${PHP_BIN}" artisan key:generate --force
fi
"${PHP_BIN}" artisan migrate --seed --force
"${PHP_BIN}" artisan storage:link || true
"${PHP_BIN}" artisan optimize:clear
"${PHP_BIN}" artisan view:cache
"${PHP_BIN}" artisan route:list --except-vendor >/dev/null

chown -R www-data:www-data "${APP_DIR}/storage" "${APP_DIR}/database" "${APP_DIR}/bootstrap/cache"
chmod -R ug+rwX "${APP_DIR}/storage" "${APP_DIR}/database" "${APP_DIR}/bootstrap/cache"
chmod -R a+rX "${APP_DIR}/public"

install -m 0644 "${APP_DIR}/systemd/ensha-web.service" /etc/systemd/system/ensha-web.service
systemctl daemon-reload
systemctl enable --now ensha-web.service
systemctl is-active --quiet ensha-web.service

if command -v curl >/dev/null 2>&1; then
  health_ok=false
  for _attempt in {1..10}; do
    if curl --fail --silent --max-time 5 http://127.0.0.1:18850/up >/dev/null; then
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

echo
echo "Ensha آماده است: http://127.0.0.1:18850"
echo "اکنون nginx/ensha-location.conf را داخل server block مربوط به srun.ir include کنید و nginx را reload نمایید."
echo "کاربر نمونه ادمین: 09120000000 / ChangeMe123!"
echo "برای امنیت، رمزهای نمونه را فوراً تغییر دهید."
