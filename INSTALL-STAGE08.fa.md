# نصب Stage 08 / Ensha v0.20.0

نسخه مبدا باید `0.19.0` باشد. مخزن محلی باید با `origin/main` یکسان و فاقد تغییر ثبت‌نشده باشد.

نصاب عملیات زیر را در یک چرخه کنترل‌شده انجام می‌دهد:

1. بررسی manifest، PHP، JavaScript، Sodium، Git، Redis و سلامت وب.
2. پشتیبان‌گیری از تمام فایل‌های درگیر، دیتابیس و سرویس Queue قبلی.
3. ورود برنامه به maintenance mode.
4. نصب فایل‌ها، اجرای migration و ساخت مسیرهای خصوصی صوت.
5. اصلاح مالکیت و مجوزهای `storage` و `bootstrap/cache`.
6. نصب و راه‌اندازی `ensha-queue.service`.
7. اجرای verify، ساخت View Cache و Health Check.
8. Commit و Push به شاخه `main` و تطبیق commit محلی و راه‌دور.

اگر نصب پس از شروع تغییرات و قبل از Commit شکست بخورد، Rollback خودکار فایل‌ها، دیتابیس و سرویس Queue را به وضعیت قبلی بازمی‌گرداند.

گزارش اجرای launcher در `/root/runlog.txt` ذخیره می‌شود.
