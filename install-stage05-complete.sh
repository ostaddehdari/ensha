#!/usr/bin/env bash
set -u
SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${1:-/var/www/ensha}"
LOG_FILE="${LOG_FILE:-/var/log/ensha-stage05-complete-v0.17.0-final.log}"
[[ "${EUID}" -eq 0 ]] || { echo "نصاب باید با root اجرا شود." >&2; exit 1; }
install -d -m 750 "$(dirname "${LOG_FILE}")"
if bash "${SOURCE_DIR}/deploy/update-v0.17.0.sh" "${SOURCE_DIR}" "${APP_DIR}" >"${LOG_FILE}" 2>&1; then
    echo "Stage 05 کامل / Ensha v0.17.0 با موفقیت نصب شد."
    echo "گزارش کامل: ${LOG_FILE}"
    exit 0
fi
echo "تکمیل Stage 05 ناموفق بود؛ Rollback خودکار در صورت شروع تغییرات اجرا شده است." >&2
echo "گزارش کامل: ${LOG_FILE}" >&2
exit 1
