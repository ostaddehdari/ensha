#!/usr/bin/env bash
set -u

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${1:-/var/www/ensha}"
LOG_FILE="${LOG_FILE:-/var/log/ensha-stage00-v0.10.4.log}"

if [[ "${EUID}" -ne 0 ]]; then
    echo "این نصب‌کننده باید با کاربر root اجرا شود." >&2
    exit 1
fi

install -d -m 750 "$(dirname "${LOG_FILE}")"
if bash "${SOURCE_DIR}/deploy/update-v0.10.4.sh" "${SOURCE_DIR}" "${APP_DIR}" >"${LOG_FILE}" 2>&1; then
    echo "Stage 00 / Ensha v0.10.4 با موفقیت نصب شد."
    echo "گزارش کامل: ${LOG_FILE}"
    exit 0
fi

echo "نصب Stage 00 ناموفق بود؛ Rollback خودکار اجرا شده است." >&2
echo "گزارش کامل: ${LOG_FILE}" >&2
exit 1
