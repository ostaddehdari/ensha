# انشا ۰.۷ — پروفایل شخصی و شناسایی کد ملی

روی نسخهٔ ۰.۶.۱ نصب کنید. نیازمند PHP 8.3 یا بالاتر؛ داده‌ها، رمزها، `.env` و `storage` حفظ می‌شوند.

## امکانات

- `/ensha/module/profile`: صفحهٔ واقعی برای مشاهده و ویرایش شماره تماس و همهٔ فیلدهای فعال مشترک و مخصوص نقش کاربر.
- نام، نام خانوادگی، کد ملی، نقش و مرکز در پروفایل شخصی فقط خواندنی هستند. مدیر مجاز می‌تواند هویت و انتساب را در بخش کاربران اصلاح کند.
- تغییر شماره تماس که شناسهٔ ورود است، نیازمند رمز فعلی است و نشست‌های دیگر را پایان می‌دهد. سایر فیلدهای پروفایل بدون رمز قابل اصلاح‌اند.
- داشبورد ادمین: جست‌وجوی دقیق ده‌رقمی با کد ملی، نمایش افراد اخیر با کد ملی؛ در فهرست کاربران و خروجی CSV کد ملی ستون نخست است. شناسهٔ عددی داخلی فقط برای روابط دیتابیس باقی می‌ماند.

## آپلود از Git Bash ویندوز

فایل ZIP را در Downloads ذخیره کنید:

```bash
cd ~/Downloads
scp -i ~/.ssh/id_ed25519_vps -P 2233 ensha-laravel-v0.7-self-profile.zip root@5.61.27.185:/tmp/
ssh -i ~/.ssh/id_ed25519_vps -p 2233 root@5.61.27.185
```

## نصب روی سرور

پیش از نصب از دیتابیس نسخهٔ پشتیبان بگیرید؛ اسکریپت فقط از فایل‌های برنامه بکاپ می‌گیرد.

```bash
mkdir -p /tmp/ensha-v0.7
unzip -q /tmp/ensha-laravel-v0.7-self-profile.zip -d /tmp/ensha-v0.7
bash /tmp/ensha-v0.7/ensha-base/deploy/update-v0.7.sh /tmp/ensha-v0.7/ensha-base /var/www/ensha
systemctl status ensha-web --no-pager
curl -fsS http://127.0.0.1:18850/up >/dev/null && echo 'Ensha OK'
cd /var/www/ensha && php artisan route:list --path=module/profile
```

در صورت امکان تست اختصاصی این نسخه را اجرا کنید:

```bash
cd /var/www/ensha && php artisan test --filter=SelfProfileTest
```

پس از نصب، صفحه https://srun.ir/ensha/module/profile را با Ctrl+F5 باز کنید.
