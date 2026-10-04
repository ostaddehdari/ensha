# انتقال و نصب Stage 01 / Ensha v0.11.0

## انتقال از Git Bash ویندوز

```bash
cd ~/Downloads
scp -i ~/.ssh/id_rsa -P 2233 ensha-stage01-v0.11.0.zip root@5.61.27.185:/tmp/
```

## نصب

فایل ZIP را در `/tmp/ensha-stage01-v0.11.0` استخراج و سپس اجرا کنید:

```bash
bash /tmp/ensha-stage01-v0.11.0/ensha-base/install-stage01.sh /var/www/ensha
bash /tmp/ensha-stage01-v0.11.0/ensha-base/verify-stage01.sh /var/www/ensha
```

گزارش نصب در `/var/log/ensha-stage01-v0.11.0.log` و مسیر آخرین Backup در `/var/lib/ensha/deployments/stage01-v0.11.0.last-backup` ذخیره می‌شود.
