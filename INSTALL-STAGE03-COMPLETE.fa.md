# نصب Stage 03 کامل — v0.15.0

پیش‌نیاز: Ensha v0.14.0 روی `/var/www/ensha`، دسترسی Redis و مخزن Git همگام با `origin/main`.

نصب مستقیم بسته استخراج‌شده:

```bash
bash install-stage03-complete.sh /var/www/ensha
bash verify-stage03-complete.sh /var/www/ensha
```

نصاب قبل از هر تغییر از فایل‌های برنامه و دیتابیس نسخه پشتیبان می‌گیرد. اگر نصب، migration، بررسی سلامت یا سرویس وب شکست بخورد، Rollback خودکار اجرا می‌شود.

Rollback دستی:

```bash
bash /var/www/ensha/deploy/rollback-v0.15.0.sh /var/backups/ensha/stage03-complete-v0.15.0-YYYYMMDD-HHMMSS /var/www/ensha
```

نشانگرهای موفقیت:

- `INSTALL_STAGE03_COMPLETE_V0150_OK`
- `VERIFY_STAGE03_COMPLETE_OK`
- `VERIFY_STAGE03_COMPLETE_V0150_OK`
