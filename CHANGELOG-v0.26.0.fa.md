# تغییرات Ensha v0.26.0

- افزودن Middleware اختصاصی احراز اصالت پل WordPress با HMAC-SHA256.
- افزودن کنترل Timestamp و Nonce ضدبازپخش.
- افزودن Idempotency عمومی برای درخواست‌های تغییردهنده WordPress.
- افزودن جدول‌های `wordpress_request_nonces` و `wordpress_idempotency_keys`.
- ارتقای افزونه `Ensha Booking Connector` به نسخه `0.26.0` و حذف ارسال مستقیم کلید API.
- افزودن آزمون Feature برای امضا، انقضا، دست‌کاری، Replay و Idempotency رزرو.
- افزودن نصب، Verify، Rollback و بکاپ کامل دیتابیس برای `ENSHA-P1-S06-W01`.
- اصلاح بسته `r1`: نرمال‌سازی قطعی Query String با ترتیب کلید و `RFC3986` برای سازگاری یکسان Laravel، Nginx و WordPress.
- اصلاح بسته `r2`: یکسان‌سازی Hash بدنه متدهای امن روی رشته خالی؛ جلوگیری از اختلاف بدنه مصنوعی `[]` در `getJson()` لاراول.
