# ارتقا به نسخه ۰.۴: مراکز و کارکنان

نیازمند نسخه ۰.۳ نصب‌شده روی سرور و PHP 8.3 یا جدیدتر. این نسخه داده‌ها، فایل `.env`، نشست‌ها، رمزها و پوشه `storage` را بازنشانی نمی‌کند.

پس از ارسال ZIP به `/tmp/`:

```bash
mkdir -p /tmp/ensha-v0.4
unzip -q /tmp/ensha-laravel-v0.4-centres-staff.zip -d /tmp/ensha-v0.4
bash /tmp/ensha-v0.4/ensha-base/deploy/update-v0.4.sh /tmp/ensha-v0.4/ensha-base /var/www/ensha
```

بررسی:

```bash
systemctl status ensha-web --no-pager
curl -i http://127.0.0.1:18850/up
cd /var/www/ensha && php artisan route:list --path=centres && php artisan route:list --path=staff
```

مدیر مرکز فقط مرکز و کارکنان مرکز خودش را می‌بیند. ادمین سیستم می‌تواند مرکز ایجاد کند و همه مراکز را ببیند. ایجاد حساب کارمند و جابه‌جایی بین مراکز از «کاربران» انجام می‌شود. غیرفعال کردن مرکز دارای کاربر فعال ممنوع است.

پیش از اجرای ارتقا، از پایگاه داده یک نسخه پشتیبان جداگانه بگیرید؛ اسکریپت فقط از فایل‌های برنامه نسخه پشتیبان تهیه می‌کند.
