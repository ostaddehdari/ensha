# Ensha v0.24.0 — Stage 12 Stabilization

این انتشار قبل از افزودن تست‌ساز، کمبودهای عملیاتی نسخه‌های قبلی را می‌بندد.

- PHPUnit 11 از lock نصب می‌شود؛ خرابی‌های خط مبنای کشف‌شده اصلاح و سپس کل مجموعه تست‌ها پس از migration اجرا می‌شود. هر شکست باعث Rollback خودکار فایل و دیتابیس خواهد شد.
- DayPilot دارای نمای واقعی ماه، فیلتر، Drag/Drop و Resize است.
- تست Feature و سناریوی Playwright مرورگر برای تقویم افزوده شده است.
- دوره حقوق هم‌پوشان ممنوع است و حقوق ثابت به نسبت روزهای بازه محاسبه می‌شود.
- گردش حقوق: پیش‌نویس، ارسال، تأیید چهارچشمی، پرداخت یا ابطال حسابرسی‌شده.
- اتصال مستقل هر مرکز به WordPress/Elementor، Kavenegar و Whisper.
- کانکتور اختیاری زرین‌پال برای مرحله فعال‌سازی پرداخت اینترنتی آماده تنظیم است؛ بدون Merchant ID غیرفعال می‌ماند.
- Retention روزانه صوت، گزارش انقضا، Legal Hold و حذف حسابرسی‌شده فعال است.

## نکات امنیتی

کلیدها در `centre_integrations.secret_payload` با APP_KEY رمزگذاری می‌شوند. کلیدها در Git، فایل ZIP یا HTML بازگردانده نمی‌شوند. افزونه وردپرس در `integrations/wordpress/ensha-booking.php` قرار دارد.

## تست مرورگری

بعد از نصب، با یک کاربر منشی دارای داده آزمایشی:

```bash
cd /var/www/ensha/tests/Browser
npm install
npx playwright install chromium
ENSHA_BROWSER_URL=https://srun.ir/ensha \
ENSHA_BROWSER_USER='USER_PHONE' \
ENSHA_BROWSER_PASSWORD='PASSWORD' \
npm test
```
