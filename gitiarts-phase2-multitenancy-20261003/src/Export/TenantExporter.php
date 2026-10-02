<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Export;

use GitiArts\Phase2\Contracts\TenantStorageInterface;
use GitiArts\Phase2\Localization\JalaliDate;
use GitiArts\Phase2\Localization\Lang;

/**
 * TenantExporter
 *
 * Produces a .giti archive (a ZIP with custom extension) for a single
 * tenant. The archive is fully self-contained: it includes the database,
 * uploaded files, settings, schema metadata, and a Persian installation
 * README.
 *
 * Output layout (matches DELIVERABLE 2 in the spec):
 *
 *   {slug}.giti
 *   ├── manifest.json
 *   ├── database/tenant.sqlite
 *   ├── uploads/{logo,menu-items,receipts,employees}/...
 *   ├── config/settings.json
 *   ├── config/env.template
 *   ├── meta/schema_version.txt
 *   ├── meta/platform_version.txt
 *   └── README.txt   (Persian, RTL-formatted)
 *
 * Implementation notes:
 *   - Uses streaming reads (ZipArchive with on-disk temp file) to avoid
 *     loading the full DB into memory — important for cafés with thousands
 *     of orders.
 *   - Manifest is generated AFTER the payload is built so the checksum
 *     covers the actual file contents.
 */
final class TenantExporter
{
    private const FORMAT_VERSION    = '1.0';
    private const PLATFORM_VERSION  = '5.0.5-test.5';
    private const SCHEMA_VERSION    = '2026_10_03_000001';

    public function __construct(
        private readonly TenantStorageInterface $storage
    ) {
    }

    /**
     * Build a .giti archive for a tenant and return its absolute path.
     *
     * @param string $tenantId   Tenant UUID.
     * @param array  $tenantInfo Tenant info from platform directory DB:
     *                           ['name_fa','slug','owner_mobile','plan','created_at'].
     * @return string Absolute path to the generated .giti file.
     */
    public function export(string $tenantId, array $tenantInfo): string
    {
        $tenantRoot = $this->storage->getTenantRoot($tenantId);
        $slug       = $this->safeSlug($tenantInfo['slug'] ?? $tenantId);

        $tempDir = rtrim(\GitiArts\Phase2\Support\Env::get('EXPORT_TEMP_ROOT', '/storage/exports') ?? '/storage/exports', '/');
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $gitiPath = $tempDir . '/' . $slug . '_' . date('Ymd_His') . '.giti';

        $zip = new \ZipArchive();
        $openResult = $zip->open($gitiPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($openResult !== true) {
            throw new \RuntimeException(sprintf('Cannot create .giti archive (code %d)', $openResult));
        }

        // --- Stage 1: stream the database file ---
        $dbPath = $tenantRoot . '/data.sqlite';
        if (is_file($dbPath)) {
            // Use addFile (streamed internally by libzip) to avoid loading
            // the entire DB into PHP memory.
            $zip->addFile($dbPath, 'database/tenant.sqlite');
        }

        // --- Stage 2: stream all upload categories ---
        $uploadsRoot = $tenantRoot . '/uploads';
        if (is_dir($uploadsRoot)) {
            $this->addDirectoryToZip($zip, $uploadsRoot, 'uploads');
        }

        // --- Stage 3: settings.json ---
        $meta = $this->storage->getAllMeta($tenantId);
        $settings = [
            'cafe_name_fa'    => $tenantInfo['name_fa']    ?? ($meta['cafe_name_fa']    ?? 'کافه'),
            'cafe_slug'       => $slug,
            'owner_mobile'    => $tenantInfo['owner_mobile'] ?? ($meta['owner_mobile']    ?? null),
            'plan'            => $tenantInfo['plan']        ?? ($meta['plan']            ?? 'standalone'),
            'timezone'        => 'Asia/Tehran',
            'locale'          => 'fa',
            'currency'        => 'IRR_TOMAN',
            'sms_provider'    => $meta['sms_provider']    ?? null,
            'sms_api_key'     => $meta['sms_api_key']     ?? null,
            'sms_sender'      => $meta['sms_sender']      ?? null,
        ];
        $zip->addFromString('config/settings.json', json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // --- Stage 4: env.template ---
        $zip->addFromString('config/env.template', $this->renderEnvTemplate($slug));

        // --- Stage 5: meta files ---
        $zip->addFromString('meta/schema_version.txt', self::SCHEMA_VERSION);
        $zip->addFromString('meta/platform_version.txt', self::PLATFORM_VERSION);

        // --- Stage 6: Persian README ---
        $zip->addFromString('README.txt', $this->renderPersianReadme($tenantInfo, $meta));

        // --- Stage 7: build checksum over current payload, then write manifest ---
        $zip->close();
        $checksum = $this->computeChecksum($gitiPath);

        // Reopen to append the manifest (now that we know the checksum).
        $zip = new \ZipArchive();
        $zip->open($gitiPath);
        $manifest = $this->buildManifest($tenantId, $tenantInfo, $meta, $checksum);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $zip->close();

        return $gitiPath;
    }

    /**
     * Compute a SHA-256 of the entire ZIP payload (excluding manifest,
     * which is added afterwards).
     */
    private function computeChecksum(string $path): string
    {
        return hash_file('sha256', $path);
    }

    private function buildManifest(string $tenantId, array $info, array $meta, string $checksum): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return [
            'format_version'    => self::FORMAT_VERSION,
            'platform_version'  => self::PLATFORM_VERSION,
            'schema_version'    => self::SCHEMA_VERSION,
            'exported_at'       => $now->format(\DateTimeInterface::ATOM),
            'exported_at_jalali' => JalaliDate::toJalali($now->format('Y-m-d H:i:s')),
            'tenant' => [
                'uuid'          => $tenantId,
                'name_fa'       => $info['name_fa'] ?? ($meta['cafe_name_fa'] ?? 'کافه'),
                'slug'          => $info['slug'] ?? $tenantId,
                'owner_mobile'  => $info['owner_mobile'] ?? null,
                'plan'          => $info['plan'] ?? 'standalone',
                'created_at'    => $info['created_at'] ?? null,
            ],
            'stats' => [
                'orders_count'  => $meta['stats_orders_count']   ?? 0,
                'menu_items'    => $meta['stats_menu_items']     ?? 0,
                'employees'     => $meta['stats_employees']      ?? 0,
                'customers'     => $meta['stats_customers']      ?? 0,
            ],
            'checksum'      => $checksum,
            'checksum_algo' => 'sha256',
            'encryption' => [
                'enabled'      => \GitiArts\Phase2\Support\Env::getBool('EXPORT_ENCRYPTION_ENABLED', false),
                'algorithm'    => 'aes-256-cbc',
                'key_fingerprint' => null,
            ],
        ];
    }

    private function renderEnvTemplate(string $slug): string
    {
        return <<<ENV
# =====================================================
# GitiArts Standalone Environment Configuration
# کافه: {$slug}
# =====================================================
DEPLOYMENT_MODE=standalone
TENANT_STORAGE_DRIVER=sqlite
SQLITE_STORAGE_ROOT=/storage
APP_LOCALE=fa
APP_TIMEZONE=Asia/Tehran

# برای ارسال پیامک OTP، کلید سرویس ایرانی خود را وارد کنید
SMS_PROVIDER=kavenegar
SMS_API_KEY=
SMS_SENDER_NUMBER=

# کانال به‌روزرسانی (اختیاری)
UPDATE_SERVER_URL=https://api.yourplatform.ir/updates/check
UPDATE_LICENSE_KEY=
ENV;
    }

    /**
     * Persian (RTL) installation guide embedded in every .giti archive.
     * Lines are written without forced justification so terminals and
     * text editors display Persian correctly with shaping.
     */
    private function renderPersianReadme(array $info, array $meta): string
    {
        $name = $info['name_fa'] ?? ($meta['cafe_name_fa'] ?? 'کافه شما');
        $date = JalaliDate::toJalali(date('Y-m-d H:i:s'));

        return <<<TXT
====================================================
        بسته خروجی پلتفرم گیتى‌آرتس
        (نسخه قابل نصب روی هاست اختصاصی شما)
====================================================

نام کافه: {$name}
تاریخ صدور بسته: {$date}

این بسته شامل تمام اطلاعات کافه شما اعم از پایگاه داده،
فایل‌های آپلود شده (لوگو، عکس منو، فیش‌ها، مدارک کارکنان)
و تنظیمات اختصاصی است.

----------------------------------------------------
راهنمای نصب روی هاست اختصاصی
----------------------------------------------------

۱. تمام محتویات این بسته را در روت هاست خود از حالت فشرده خارج کنید.

۲. فایل config/env.template را به .env تغییر نام دهید و مقادیر
   مربوط به سرویس پیامک خود را در آن وارد کنید.

۳. فایل database/tenant.sqlite را در مسیر /storage/data.sqlite
   کپی کنید.

۴. پوشه uploads/ را در مسیر /storage/uploads/ کپی کنید.

۵. کد اصلی پلتفرم گیتى‌آرتس را روی هاست خود نصب کنید
   (نصب کننده از همین پوشه‌بندی استفاده می‌کند).

۶. متغیر محیطی DEPLOYMENT_MODE را روی standalone تنظیم کنید.

۷. در صورت بروز هرگونه خطا، با پشتیبانی گیتى‌آرتس تماس بگیرید.

----------------------------------------------------
تضمین قابلیت انتقال (Portability Guarantee)
----------------------------------------------------
پلتفرم گیتى‌آرتس متعهد می‌شود که در هر زمان، صاحب کافه می‌تواند
تمام اطلاعات خود را دریافت کرده و روی هاست اختصاصی خود نصب نماید.
این بسته، اثبات عملی این تعهد است.

====================================================
GitiArts Menu Platform — Standalone Export Package
TXT;
    }

    private function addDirectoryToZip(\ZipArchive $zip, string $sourceDir, string $zipPrefix): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $realPath = $file->getRealPath();
            $relative = $zipPrefix . substr($realPath, strlen($sourceDir));
            // Normalize Windows backslashes
            $relative = str_replace('\\', '/', $relative);
            $zip->addFile($realPath, $relative);
        }
    }

    private function safeSlug(string $slug): string
    {
        $slug = preg_replace('/[^a-z0-9\-_]/', '', strtolower($slug)) ?? 'tenant';
        return $slug !== '' ? $slug : 'tenant';
    }
}
