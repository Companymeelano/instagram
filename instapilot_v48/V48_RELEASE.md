# InstaPilot V48 — Single-File cPanel Release

این نسخه روی V47 ساخته شده و علاوه بر رفع چهار باگ واقعی، کل رابط کاربری را در **یک فایل HTML واحد** (`index.html`) ارائه می‌کند که روی هر هاست اشتراکی cPanel بدون هیچ وابستگی خارجی کار می‌کند.

## باگ‌های رفع‌شده (نسبت به V47)

1. **کرش بوت (بحرانی):** `setupCommand()` در `boot()` صدا زده می‌شد ولی تعریف نشده بود؛ به‌محض اجرا، `ReferenceError` باعث می‌شد هندلرهای Mission/Blueprint/Scheduler، پنل‌های V40–V43، رانتایم امنیتی V45 و `restoreSession()` هرگز فعال نشوند.
   → تابع `setupCommand()` پیاده‌سازی شد: دکمه‌ی ⌘ در نوار بالا، مرکز فرمان با جریان واقعی `POST /command` (پاسخ `navigate` → جابه‌جایی view) و fallback آفلاین/Demo.
2. **CSS گم‌شده:** `final.css` (۸۹ گروه کلاس از جمله استایل V40–V43، `.ip35-dashboard` و `.ip35-toast`) در بسته بود ولی لینک نشده بود → در نسخه‌ی تک‌فایل، هر دو استایل‌شیت درون `index.html` ادغام شده‌اند.
3. **Badge اعلان:** فرانت `bellBadge`/`notificationBadge` را می‌خواست ولی HTML فقط `id="badge"` داشت → آیدی به `notificationBadge` اصلاح شد و یک fallback هم در JS اضافه شد.
4. **زمان‌بندی V16:** `#v16ContentId` در HTML وجود نداشت → ورودی مربوطه به overlay اضافه شد؛ همچنین بعد از تأیید Draft و در لحظه‌ی زمان‌بندی، `content_id` به‌صورت خودکار از `GET /mission/status` خوانده و پر می‌شود.

## تک‌فایل شدن

- `index.html` (≈335KB) شامل کد HTML + کل CSS (app + final) + کل JS (compat + runtime) است؛ هیچ رفرنس `assets/` خارجی ندارد.
- آدرس API دیگر هاردکد `/ins/api` نیست: به‌صورت خودکار نسبت به محل فایل ساخته می‌شود (`<dir>/instapilot_backend/api`). برای استقرار جداگانه می‌توان `window.INSTAPILOT_API` را قبل از رانتایم ست کرد.
- مسیر کوکی نشست در `config.php` قابل تنظیم شد (`cookie_path`، پیش‌فرض `/`) تا اپ در هر پوشه‌ای از هاست کار کند.

## تست‌های انجام‌شده

- اجرای واقعی رانتایم روی DOM کامل صفحه (jsdom): بوت **بدون خطا** کامل شد؛ کلیک Demo → داشبورد باز شد؛ هندلرهای Mission/Scheduler/Command پاسخ دادند.
- `node --check` روی JS این‌لاین نهایی: PASS
- `php -l` روی فایل‌های تغییرکرده (bootstrap، config): PASS
- اسکن id تکراری در index.html: ۲۴۰ آیدی، بدون تکرار
- ساختار ۵۹ جدول `DATABASE_COMPLETE_INSTALL.sql` با جداول مصرفی کد سازگار است (شامل V45: device_sessions / rate_limits / login_activity).

## اجزای بسته

```
index.html                  ← اپ کامل (single-file)؛ همین را باز می‌کنید
.htaccess                   ← روتینگ API به بک‌اند + هدرهای امنیتی/کش
instapilot_backend/         ← API (PHP 8.4+)، کران worker، کانفیگ، SQL
DATABASE_COMPLETE_INSTALL.sql ← نصب کامل دیتابیس در یک ایمپورت
DATABASE_V33_UPGRADE.sql    ← ارتقای دیتابیس‌های قدیمی (در صورت نیاز)
README.md                   ← راهنمای نصب cPanel قدم‌به‌قدم
```

## بیلد اندروید (APK)

- `InstaPilot_v48.apk`: وب‌ویو بومی (WebView فول‌اسکرین، فعال‌سازی JS/DOM Storage/کوکی‌ها، دکمه Back هوشمند) که `index.html` را آفلاین در asset دارد و در اولین اجرا آدرس سرور را می‌گیرد و ذخیره می‌کند.
- اتصال به بک‌اند از Origin `null` انجام می‌شود؛ فعال‌سازی با `'cors_origins' => ['null']` در config (به‌همراه SameSite=None خودکار کوکی روی HTTPS).
- امضا: v1، سازگار با نصب مستقیم (sideload) روی همه‌ی نسخه‌های اندروید. برای فروشگاه: با کی‌استور شخصی + apksigner دوباره امضا شود.
- امضای APK با OpenSSL و androguard تأیید رمزنگاری شد؛ گواهی ثابت در `.apk-build/`.
