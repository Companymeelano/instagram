# InstaPilot V7 — Instagram Engine

این نسخه مرحله بعدی V6 است و اتصال رسمی Instagram Login را به‌صورت server-side پیاده می‌کند.

## V7 چه چیزی اضافه کرده؟

- Instagram OAuth با `state` و اتصال به کاربر داخلی
- تبادل authorization code با short-lived access token
- تبدیل short-lived token به long-lived token
- رمزنگاری access token در Database با AES-256-GCM
- دریافت پروفایل واقعی Instagram
- Sync پروفایل و Media
- Refresh خودکار token نزدیک انقضا در Worker
- endpoint واقعی برای ساخت Media Container و Publish
- ثبت Audit Log و Notification برای اتصال/انتشار/خطا
- API endpointهای جدید:
  - `GET /ins/api/instagram/connect`
  - `GET /ins/api/instagram/callback`
  - `GET /ins/api/instagram/status`
  - `POST /ins/api/instagram/sync`
  - `POST /ins/api/instagram/refresh`
  - `POST /ins/api/instagram/publish`

## تنظیمات لازم

در `config/config.php`:

- `app.base_url`
- `app.encryption_key` — یک Secret تصادفی حداقل 32 کاراکتری
- اطلاعات MySQL
- `instagram.app_id`
- `instagram.app_secret`
- `instagram.redirect_uri`
- scopes مورد نیاز

**App Secret و access token هرگز نباید در Front-end قرار بگیرند.**

## Meta / Instagram

این نسخه از Instagram API with Instagram Login استفاده می‌کند. برای حساب‌های Professional و permissionهای مورد نیاز باید تنظیمات و App Review مطابق مستندات Meta انجام شود.

## Publishing

برای انتشار، `media_url` باید از URL عمومی و قابل دسترس برای Instagram باشد. برای Reel/Video ابتدا Media Container ساخته و وضعیت آن بررسی می‌شود و سپس publish انجام می‌شود.

## Cron

Worker را در cPanel Cron مثلاً هر 5 دقیقه اجرا کنید:

`php /home/USERNAME/public_html/ins/instapilot_backend/cron/worker.php`

در مسیر واقعی هاست، `USERNAME` و مسیر پروژه را مطابق سرور خودتان تغییر دهید.

## نکته مهم

تا زمانی که App ID/Secret، Redirect URI، permissions، App Review و Database روی سرور تنظیم نشده باشند، اتصال Instagram عمداً پیام خطا می‌دهد و خود را Connected نشان نمی‌دهد.
