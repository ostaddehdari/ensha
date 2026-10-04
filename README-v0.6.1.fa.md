# انشا ۰.۶.۱ — اصلاح افزودن با کشیدن و عرض بوم

این نسخه روی نصب ۰.۶ اعمال می‌شود و دیتابیس را بازنشانی نمی‌کند.

- افزودن آیتم با کلیک یا کشیدن به بوم راست؛ رویداد Pointer و Drag and Drop هر دو پشتیبانی می‌شوند.
- بوم راست در نمایشگر بزرگ پهن‌تر از فهرست ابزار چپ است. مقدار `minmax(640px,300px)` در CSS نامعتبر است چون حداقل از حداکثر بزرگ‌تر است؛ مقدار معتبر `minmax(640px,2.2fr)` استفاده شده است.
- نشانی فایل‌های CSS/JS نسخه‌دار شده است تا کش نسخهٔ ۰.۶ باقی نماند.
- پیام «فرم‌ساز آماده است» پس از اجرای JavaScript ظاهر می‌شود؛ اگر پیام «در حال آماده‌سازی» باقی بماند، فایل JavaScript بارگذاری نشده است.

## انتقال از Git Bash ویندوز

```bash
cd ~/Downloads
scp -i ~/.ssh/id_ed25519_vps -P 2233 ensha-laravel-v0.6.1-builder-drag-hotfix.zip root@5.61.27.185:/tmp/
ssh -i ~/.ssh/id_ed25519_vps -p 2233 root@5.61.27.185
```

## نصب و بررسی در سرور

```bash
mkdir -p /tmp/ensha-v0.6.1
unzip -q /tmp/ensha-laravel-v0.6.1-builder-drag-hotfix.zip -d /tmp/ensha-v0.6.1
bash /tmp/ensha-v0.6.1/ensha-base/deploy/update-v0.6.1.sh /tmp/ensha-v0.6.1/ensha-base /var/www/ensha
systemctl status ensha-web --no-pager
curl -fsS http://127.0.0.1:18850/up >/dev/null && echo 'Ensha OK'
```

فرم‌ساز را در https://srun.ir/ensha/profile-fields با Ctrl+F5 باز کنید. با یک کلیک روی «متن کوتاه» و سپس با کشیدن یک آیتم دیگر، اضافه‌شدن هر دو را پیش از «ذخیره فرم» بررسی کنید.
