#!/usr/bin/env bash
set -euo pipefail

# استفاده برای نصب موجود؛ .env، دیتابیس، storage و فایل‌های کاربر دست‌نخورده می‌مانند.
SOURCE_DIR="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
APP_DIR="${2:-/var/www/ensha}"

if [[ "${EUID}" -ne 0 ]]; then
  echo "این بروزرسانی باید با sudo یا root اجرا شود." >&2
  exit 1
fi
if [[ ! -f "${SOURCE_DIR}/composer.json" || ! -d "${SOURCE_DIR}/public/assets" ]]; then
  echo "مسیر بستهٔ اصلاحی معتبر نیست: ${SOURCE_DIR}" >&2
  exit 1
fi

install -d "${APP_DIR}"
for path in app bootstrap config database/migrations database/seeders resources routes public/css public/js public/assets systemd nginx composer.json README.fa.md; do
  if [[ -e "${SOURCE_DIR}/${path}" ]]; then
    mkdir -p "${APP_DIR}/$(dirname "${path}")"
    cp -a "${SOURCE_DIR}/${path}" "${APP_DIR}/$(dirname "${path}")/"
  fi
done

cd "${APP_DIR}"
if command -v composer >/dev/null 2>&1 && [[ -f composer.json ]]; then
  if [[ -f composer.lock ]]; then composer install --no-dev --prefer-dist --optimize-autoloader; else composer update --no-dev --with-all-dependencies --prefer-dist --optimize-autoloader; fi
fi
php artisan optimize:clear
chown -R www-data:www-data "${APP_DIR}/storage"
chmod -R ug+rwX "${APP_DIR}/storage"
chmod -R a+rX "${APP_DIR}/public"
systemctl restart ensha-web.service
echo "Ensha UI v0.2 با ساختار واقعی Metronic بروزرسانی شد."
