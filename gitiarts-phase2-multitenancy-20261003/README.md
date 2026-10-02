# پلتفرم گیتى‌آرتس — فاز دوم (مالتی‌تنانسی + قابلیت انتقال + بومی‌سازی)

<div dir="rtl" lang="fa">

نسخه: ۵.۰.۵-test.5  
فاز: ۲  
زبان اصلی: فارسی  

---

## نمای کلی

این بسته فاز دوم پلتفرم گیتى‌آرتس را پیاده‌سازی می‌کند و سه قابلیت کلیدی را
به سیستم اضافه می‌نماید:

۱. **مالتی‌تنانسی** — چندین کافه روی یک نمونه از پلتفرم با جداسازی کامل داده‌ها  
۲. **قابلیت انتقال (Portability Guarantee)** — هر کافه می‌تواند در هر زمان
   پلتفرم را ترک کرده و روی هاست اختصاصی خود نصب کند  
۳. **بومی‌سازی فارسی (Persian-First)** — تمام رابط کاربری فارسی، راست‌چین،
   با اعداد فارسی و تاریخ شمسی

---

## ساختار پوشه‌ها

```
gitiarts-phase2/
├── composer.json
├── .env.example
├── src/
│   ├── Contracts/          رابط‌های قراردادی
│   ├── Deployment/         مدیریت حالت پلتفرم/standalone
│   ├── Tenant/             تشخیص و مسیردهی tenant
│   ├── Storage/            درایورهای ذخیره‌سازی (SQLite + PostgreSQL)
│   ├── Export/             خروجی/ورودی بسته .giti
│   ├── Update/             کانال به‌روزرسانی
│   ├── Database/           SchemaBuilder و MigrationRunner
│   ├── Localization/       کلاس‌های بومی‌سازی فارسی
│   └── Support/            کلاس‌های کمکی (Env loader)
├── resources/lang/         فایل‌های ترجمه (fa.json + en.json)
├── migrations/             مهاجرت‌های پایگاه داده
├── scripts/                اسکریپت‌های مهاجرت SQLite به PostgreSQL
├── tests/                  تست‌های PHPUnit
└── docs/                   مستندات فنی
```

---

## نصب

### پیش‌نیازها

- PHP نسخه ۸.۱ یا بالاتر
- اکستنشن‌های pdo، json، zip، mbstring، openssl
- اختیاری: Redis برای کش تشخیص tenant

### مراحل نصب

۱. تمام فایل‌های این بسته را در روت پروژه کپی کنید:

   ```bash
   cp -r gitiarts-phase2/* /var/www/gitiarts/
   ```

۲. وابستگی‌ها را با Composer نصب کنید:

   ```bash
   cd /var/www/gitiarts
   composer install --no-dev --optimize-autoloader
   ```

۳. فایل `.env.example` را به `.env` کپی کرده و مقادیر را تنظیم کنید:

   ```bash
   cp .env.example .env
   nano .env
   ```

۴. مسیرهای ذخیره‌سازی را ایجاد کنید:

   ```bash
   mkdir -p /storage/tenants /storage/exports /storage/cache /storage/logs
   chmod -R 775 /storage
   ```

۵. مهاجرت‌های پایگاه داده را اعمال کنید (یک‌بار برای هر tenant):

   ```php
   use GitiArts\Phase2\Database\MigrationRunner;
   use GitiArts\Phase2\Storage\TenantStorageManager;

   $runner = new MigrationRunner(
       TenantStorageManager::getDriver(),
       __DIR__ . '/migrations'
   );
   $runner->migrate($tenantId);
   ```

---

## حالت‌های اجرا (Deployment Modes)

پلتفرم در دو حالت اجرا می‌شود:

### حالت پلتفرم (platform)

برای سرور اصلی ما. چندین کافه روی یک نمونه فعال می‌شوند.

```env
DEPLOYMENT_MODE=platform
TENANT_STORAGE_DRIVER=sqlite
```

### حالت Standalone (standalone)

برای کافه‌هایی که پلتفرم را ترک کرده و روی هاست خود نصب می‌کنند.

```env
DEPLOYMENT_MODE=standalone
TENANT_STORAGE_DRIVER=sqlite
UPDATE_SERVER_URL=https://api.yourplatform.ir/updates/check
UPDATE_LICENSE_KEY=YOUR_LICENSE_KEY
```

**مهم:** کد برنامه هرگز نباید مستقیماً متغیر `DEPLOYMENT_MODE` را بخواند.
همیشه باید از کلاس `DeploymentContext` استفاده کند.

---

## بومی‌سازی فارسی

این پلتفرم برای بازار ایران ساخته شده است. فارسی زبان اصلی و مرجع است،
نه یک لایه ترجمه.

- تمام رشته‌های کاربری در `resources/lang/fa.json` قرار دارند
- اعداد فارسی: از کلاس `PersianDigits` استفاده کنید
- تاریخ شمسی: از کلاس `JalaliDate` استفاده کنید
- واحد پول: تومان، از کلاس `Currency` استفاده کنید
- اعتبارسنجی موبایل/کد ملی: از کلاس `Validator` استفاده کنید

برای راهنمای کامل به `docs/LOCALIZATION_GUIDE.md` مراجعه کنید.

---

## قابلیت انتقال (.giti)

هر کافه می‌تواند در هر زمان بسته‌ای از کل داده‌های خود دریافت کند:

```php
use GitiArts\Phase2\Export\TenantExporter;
use GitiArts\Phase2\Storage\TenantStorageManager;

$exporter = new TenantExporter(TenantStorageManager::getDriver());
$path = $exporter->export($tenantId, [
    'name_fa'      => 'کافه میران',
    'slug'         => 'miran',
    'owner_mobile' => '09123456789',
    'plan'         => 'standalone',
    'created_at'   => '2024-01-15',
]);

// $path اکنون مسیر فایل .giti است
```

برای نصب روی هاست اختصاصی:

```php
use GitiArts\Phase2\Export\TenantImporter;
use GitiArts\Phase2\Export\TenantValidator;

$importer = new TenantImporter(
    TenantStorageManager::getDriver(),
    new TenantValidator()
);
$result = $importer->import('/path/to/miran.giti', '/var/www/gitiarts');
```

---

## تست‌ها

```bash
composer test
# یا
./vendor/bin/phpunit tests/
```

تست‌ها شامل موارد زیر هستند:
- تست رشته‌های فارسی (طول مالتی‌بایت)
- تست تبدیل اعداد فارسی به لاتین و برعکس
- تست تبدیل تاریخ میلادی به شمسی و برعکس
- تست اعتبارسنجی کد ملی با الگوریتم چک‌سام
- تست اعتبارسنجی موبایل ایرانی
- تست مسیردهی hash-based برای tenant
- تست حالت‌های DeploymentContext

---

## مستندات فنی

- `docs/MIGRATION_PLAN.md` — طرح مهاجرت SQLite به PostgreSQL
- `docs/TENANT_ANTIPATTERNS.md` — ۱۵ اشتباه رایج + نکات فارسی/RTL
- `docs/LOCALIZATION_GUIDE.md` — راهنمای اضافه کردن رشته‌های فارسی جدید

---

## تضمین قابلیت انتقال

پلتفرم گیتى‌آرتس متعهد می‌شود که در هر زمان، صاحب کافه می‌تواند تمام
اطلاعات خود را دریافت کرده و روی هاست اختصاصی خود نصب نماید. این یک
ویژگی بازاریابی است، نه یک پس‌اندازه فنی.

این کد، اثبات عملی آن تعهد است.

---

## لایسنس

این کد متعلق به شرکت گیتى‌آرتس است. استفاده از آن در کافه‌هایی که
اشتراک معتبر دارند یا لایسنس standalone خریداری کرده‌اند، مجاز است.

</div>
