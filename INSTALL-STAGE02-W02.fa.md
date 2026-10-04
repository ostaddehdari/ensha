# نصب Stage 02 / Work 02 — v0.13.0

این بسته مستقل از Work 01 نیست؛ روی نسخهٔ نصب‌شدهٔ `0.12.0` نصب افزایشی می‌شود و همهٔ فایل‌های نسخهٔ پایه را نیز در خود دارد.

نصاب قبل از تغییر، backup فایل‌ها و دیتابیس می‌سازد، checksum و manifest را بررسی می‌کند، migration را اجرا می‌کند و در خطا rollback خودکار دارد.

پس از نصب موفق باید این خروجی‌ها در `runlog.txt` دیده شوند:

```text
INSTALL_STAGE02_W02_V0130_OK
VERIFY_STAGE02_W02_V0130_OK
VERIFY_STAGE02_W02=OK
RESULT=SUCCESS
```
