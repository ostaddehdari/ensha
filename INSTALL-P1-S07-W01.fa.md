# نصب ENSHA-P1-S07-W01

برای نصب از بسته جایگزین `ensha-p1-s07-w01-v0.25.0-r1.zip` استفاده شود. بسته اولیه بدون پسوند `r1` منسوخ است.

```bash
chmod +x install-p1-s07-w01.sh
./install-p1-s07-w01.sh
```

مقادیر پیش‌فرض:

- پروژه: `/var/www/ensha`
- سرویس: `ensha-web.service`
- Health: `http://127.0.0.1:18850/up`

نصاب نسخه و Commit مبنا، Git، Redis و Health را بررسی می‌کند؛ از فایل‌ها و دیتابیس بکاپ می‌گیرد؛ PHP Syntax، آزمون اختصاصی، کل مجموعه Application و Health نهایی را اجرا می‌کند؛ سپس Commit را به `main` می‌فرستد.

این ورک Migration ندارد. در خطای پیش از Commit، فایل‌ها خودکار بازگردانده می‌شوند و دیتابیس نیاز به Restore ندارد.
