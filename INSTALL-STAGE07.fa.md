# نصب Stage 07 / Ensha v0.19.0

نسخه مبدا باید `0.18.9` باشد و مخزن محلی با شاخه `main` گیت‌هاب یکسان و بدون تغییر ثبت‌نشده باشد.

```bash
unzip -oq /tmp/ensha-stage07-v0.19.0.zip -d /tmp
bash /tmp/ensha-stage07-v0.19.0/install.sh
```

نصاب قبل از تغییر، از فایل‌های درگیر و دیتابیس نسخه پشتیبان می‌گیرد، برنامه را وارد maintenance می‌کند، migration و verify را اجرا می‌کند، سلامت وب و Redis را می‌سنجد و سپس commit و push انجام می‌دهد. در صورت شکست پیش از commit، rollback خودکار اجرا می‌شود.
