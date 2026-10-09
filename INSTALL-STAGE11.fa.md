# نصب Stage 11 / v0.23.0

بسته ZIP را در `/root/tmp` قرار دهید و کد مستقل `run.sh` ارائه‌شده را داخل `/root/run.sh` ذخیره و اجرا کنید.

نصاب نسخه `0.22.0`، Git، Redis، PHP Zip، checksum و سلامت سرویس را بررسی می‌کند؛ سپس نسخه پشتیبان کامل فایل‌ها و دیتابیس می‌سازد، migration، verify، ساخت آزمایشی XLSX، Blade cache و Health Check را اجرا می‌کند و پس از موفقیت commit و push انجام می‌دهد.

در خطای قبل از commit، rollback خودکار فایل‌ها و دیتابیس را به Stage 10 برمی‌گرداند.
