#!/usr/bin/env bash
set -u
SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${1:-/var/www/ensha}"
LOG_FILE="${LOG_FILE:-/var/log/ensha-stage02-w02-v0.13.0.log}"
[[ "${EUID}" -eq 0 ]] || { echo "نصاب باید با root اجرا شود." >&2; exit 1; }
install -d -m 750 "$(dirname "${LOG_FILE}")"
if bash "${SOURCE_DIR}/deploy/update-v0.13.0.sh" "${SOURCE_DIR}" "${APP_DIR}" >"${LOG_FILE}" 2>&1; then
    echo "Stage 02 / Work 02 / Ensha v0.13.0 با موفقیت نصب شد."
    echo "گزارش کامل: ${LOG_FILE}"
    exit 0
fi
echo "نصب Stage 02 / Work 02 ناموفق بود؛ Rollback خودکار در صورت شروع تغییرات اجرا شده است." >&2
echo "گزارش کامل: ${LOG_FILE}" >&2
exit 1
