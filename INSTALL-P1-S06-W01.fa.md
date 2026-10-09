# نصب ENSHA-P1-S06-W01 — v0.26.0

## پیش‌نیاز قطعی

- نسخه فعلی: `0.25.1`
- Commit محلی و Remote: `3c571f7eb6e9ebb46dcfd6ccade435ea2c41aad3`
- Worktree تمیز، Health و Redis سالم
- اجرای نصب با کاربر `root`

## نصب

```bash
cd /root
unzip -oq ensha-p1-s06-w01-v0.26.0.zip
chmod +x /root/ensha-p1-s06-w01-v0.26.0/install-p1-s06-w01.sh
/root/ensha-p1-s06-w01-v0.26.0/install-p1-s06-w01.sh
```

نصاب پیش از تغییر فایل‌ها، بکاپ کامل دیتابیس و فایل‌های درگیر را در `/var/backups/ensha/` می‌سازد. در خطای پیش از Commit، Rollback خودکار اجرا می‌شود.

## اقدام لازم در WordPress

پس از نصب موفق Laravel، فایل `integrations/wordpress/ensha-booking.php` نسخه `0.26.0` را جایگزین نسخه افزونه WordPress کنید. Base URL، Centre ID و API Key قبلی بدون تغییر باقی می‌مانند؛ افزونه جدید از همان کلید برای محاسبه امضا استفاده می‌کند و خود کلید را به Laravel ارسال نمی‌کند.

تا زمان ارتقای افزونه WordPress، درخواست‌های نسخه قبلی به‌درستی با پاسخ 401 رد می‌شوند.

## نشانه موفقیت

```text
WORDPRESS_HMAC_SIGNATURE=PASS
WORDPRESS_REPLAY_PROTECTION=PASS
WORDPRESS_IDEMPOTENCY=PASS
VERIFY=PASS
RESULT=SUCCESS
EXIT_CODE=0
```
