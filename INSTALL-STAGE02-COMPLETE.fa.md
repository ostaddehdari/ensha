# نصب تکمیل Stage 02 / v0.14.0

خط مبنا: Ensha v0.13.0 نصب‌شده در `/var/www/ensha`.

نصاب پیش از تغییر از فایل‌های برنامه و دیتابیس نسخه پشتیبان می‌گیرد، برنامه را موقتاً در maintenance قرار می‌دهد، مهاجرت را اجرا می‌کند، مسیرها و Blade را بررسی می‌کند، سرویس را راه‌اندازی و Health Check را کنترل می‌کند.

```bash
bash install-stage02-complete.sh /var/www/ensha
bash verify-stage02-complete.sh /var/www/ensha
```

مسیر Backup موفق در خروجی نصب و در فایل زیر ثبت می‌شود:

```text
/var/lib/ensha/deployments/stage02-complete-v0.14.0.last-backup
```

Rollback دستی:

```bash
bash deploy/rollback-v0.14.0.sh /var/backups/ensha/stage02-complete-v0.14.0-YYYYMMDD-HHMMSS /var/www/ensha
```
