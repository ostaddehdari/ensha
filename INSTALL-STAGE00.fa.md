# راهنمای انتقال و نصب Stage 00 / Ensha v0.10.4

## انتقال از Git Bash ویندوز

```bash
cd ~/Downloads

scp -i ~/.ssh/id_rsa -P 2233 \
  ensha-stage00-v0.10.4.zip \
  root@5.61.27.185:/tmp/
```

## نصب روی سرور

```bash
ssh -i ~/.ssh/id_rsa -p 2233 root@5.61.27.185

install -d -m 700 /tmp/ensha-stage00-v0.10.4
unzip -q -o /tmp/ensha-stage00-v0.10.4.zip \
  -d /tmp/ensha-stage00-v0.10.4

bash /tmp/ensha-stage00-v0.10.4/ensha-base/install-stage00.sh \
  /var/www/ensha
```

خروجی کامل در `/var/log/ensha-stage00-v0.10.4.log` ذخیره می‌شود و ترمینال فقط نتیجه نهایی را نشان می‌دهد.

## بررسی بعد از نصب

```bash
bash /tmp/ensha-stage00-v0.10.4/ensha-base/verify-stage00.sh \
  /var/www/ensha
```

خروجی موفق باید شامل `VERIFY_STAGE00_V0104_OK` باشد.

## Rollback دستی

```bash
ENSHA_BACKUP_DIR="$(cat /var/lib/ensha/deployments/stage00-v0.10.4.last-backup)"

bash "$ENSHA_BACKUP_DIR/rollback-v0.10.4.sh" \
  "$ENSHA_BACKUP_DIR" \
  /var/www/ensha
```

نصب‌کننده در صورت شکست Migration، Verify، راه‌اندازی سرویس یا Health Check همین Rollback را به‌طور خودکار اجرا می‌کند.
