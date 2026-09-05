# InstaPilot V48 — نصب روی cPanel اشتراکی (تک‌فایل HTML)

## ۱) آپلود فایل‌ها

محتویات این بسته را در پوشه‌ی دلخواه داخل `public_html` آپلود کنید، مثلاً:

```
public_html/ins/index.html          ← فایل اصلی اپ (تک‌فایل)
public_html/ins/.htaccess
public_html/ins/instapilot_backend/...
public_html/ins/DATABASE_COMPLETE_INSTALL.sql
```

> اپ را می‌توانید در هر پوشه‌ای (حتی ریشه‌ی `public_html`) بگذارید؛ آدرس API به‌صورت خودکار نسبت به محل `index.html` ساخته می‌شود.
> اگر از قبل `index.php` یا `index.html` قدیمی در آن پوشه دارید، حذفش کنید.

## ۲) PHP

در cPanel → MultiPHP Manager نسخه **PHP 8.4 یا بالاتر** را انتخاب کنید.
اکستنشن‌های لازم: `PDO MySQL`، `cURL`، `OpenSSL`، `mbstring`، `JSON`.

## ۳) دیتابیس MySQL

۱. در cPanel → MySQL Databases یک دیتابیس و یوزر بسازید و به هم وصل کنید (با همه‌ی دسترسی‌ها).
۲. در phpMyAdmin فایل زیر را ایمپورت کنید (همه‌ی جداول تا V45 را می‌سازد):
   `DATABASE_COMPLETE_INSTALL.sql`
۳. اگر دیتابیس قبلی دارید، فقط میگریشن‌های لازم را از `instapilot_backend/database/` اجرا کنید؛ فایل‌های SQL قدیمی را قاطی نکنید.

## ۴) کانفیگ

`instapilot_backend/config/config.php` را پر کنید:

- `db.host / name / user / pass` ← از بخش MySQL cPanel
- `encryption_key` ← یک رشته‌ی تصادفی ۳۲+ بایتی
- `base_url` ← آدرس کامل پوشه‌ی نصب (مثلاً `https://example.com/ins`)
- `cookie_path` ← اگر دقیقاً در `/ins` نصب کردید می‌توانید `/ins` بگذارید؛ پیش‌فرض `/` برای هر مسیر کار می‌کند
- کلیدهای AI / Instagram فقط همین‌جا (بک‌اند) نگه‌داری شوند

## ۵) کران‌جاب (پردازش صف انتشار و مغز رشد)

در cPanel → Cron Jobs (مثلاً هر ۵ دقیقه):

```
/usr/local/bin/php /home/USER/public_html/ins/instapilot_backend/cron/worker.php
```

## ۶) اپلیکیشن اندروید (APK)

فایل `InstaPilot_v48.apk` یک وب‌ویوی بومیِ حاضر است که همان `index.html` را به‌صورت آفلاین در خود دارد و به بک‌اند شما وصل می‌شود.

۱. بک‌اند را طبق مراحل بالا روی هاست نصب کنید.
۲. در `config.php` خط زیر را فعال کنید (برای اتصال امن اپ از WebView):
   ```php
   'cors_origins' => ['null'],
   ```
۳. APK را روی گوشی نصب کنید (نیاز به مجوز «نصب از منابع ناشناس»).
۴. در اولین اجرا فرم «اتصال به سرور» می‌آید؛ آدرس پوشه‌ی نصب (مثل `https://example.com/ins`) را وارد کنید — آدرس ذخیره می‌شود و دفعه‌های بعد خودکار وصل می‌شود.

نکات فنی APK: package name `com.instapilot.app`، minSdk 24 (اندروید ۷+)، امضای v1 با گواهی سلف‌سایند — برای انتشار در گوگل‌پلی باید با کی‌استور خودتان و apksigner دوباره امضا کنید.

## ۷) بررسی سلامت

بعد از نصب این آدرس‌ها باید HTTP 200 بدهند:

- `https://example.com/ins/` ← رابط کاربری (همان index.html)
- `https://example.com/ins/api/health` ← سلامت API و دیتابیس
- `https://example.com/ins/api/auth/csrf` ← صدور توکن CSRF

سپس اولین کاربر را از داخل خود اپ (فرم ورود/ثبت‌نام) بسازید.

## نکات امنیتی

- `.htaccess` پوشه‌ی `config` دسترسی وب به کانفیگ و SQL را می‌بندد؛ آن را حذف نکنید.
- اجرای اسکریپت در `uploads/` مسدود است.
- نرخ ورود محدود است (۸ تلاش / ۵ دقیقه) و نشست‌ها ۳۰ دقیقه بی‌فعالی / ۲۴ ساعت مطلق دارند.
- HTTPS را فعال کنید (Really Simple SSL یا AutoSSL)؛ کوکی نشست پشت HTTPS با `Secure` ست می‌شود.
