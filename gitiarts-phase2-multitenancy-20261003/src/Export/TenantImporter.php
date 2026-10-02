<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Export;

use GitiArts\Phase2\Contracts\TenantStorageInterface;
use GitiArts\Phase2\Localization\Lang;

/**
 * TenantImporter
 *
 * Reverse of TenantExporter. Given a validated .giti archive, materializes
 * a complete standalone installation into a target directory. Used by:
 *
 *   - Platform admins importing a tenant archive back into the platform
 *     (rare — usually only used for restore-after-deletion).
 *   - Café owners installing on their own host after leaving the platform.
 *     In this case the target is the deployment root itself.
 *
 * The importer NEVER touches the live system if validation fails.
 */
final class TenantImporter
{
    public function __construct(
        private readonly TenantStorageInterface $storage,
        private readonly TenantValidator $validator
    ) {
    }

    /**
     * Import a .giti archive into a target installation root.
     *
     * @param string $gitiPath   Path to .giti file.
     * @param string $targetRoot Target installation root (e.g. /var/www/gitiarts-standalone).
     * @return array{status:string,manifest:?array,errors:string[]}
     */
    public function import(string $gitiPath, string $targetRoot): array
    {
        $validation = $this->validator->validate($gitiPath);
        if ($validation['status'] !== TenantValidator::RESULT_OK) {
            return ['status' => $validation['status'], 'manifest' => $validation['manifest'], 'errors' => $validation['errors']];
        }

        $manifest = $validation['manifest'] ?? [];

        if (!is_dir($targetRoot)) {
            mkdir($targetRoot, 0775, true);
        }

        $zip = new \ZipArchive();
        if ($zip->open($gitiPath) !== true) {
            return [
                'status'    => 'failed',
                'manifest'  => $manifest,
                'errors'    => [Lang::get('import.error.cannot_open_zip')],
            ];
        }

        // Stage 1: extract database file
        $this->extractEntry($zip, 'database/tenant.sqlite', $targetRoot . '/storage/data.sqlite');

        // Stage 2: extract uploads (preserve subfolder structure)
        $this->extractPrefix($zip, 'uploads/', $targetRoot . '/storage/uploads');

        // Stage 3: extract config (settings.json + env.template)
        $this->extractEntry($zip, 'config/settings.json', $targetRoot . '/config/settings.json');
        $this->extractEntry($zip, 'config/env.template',  $targetRoot . '/.env.template');

        // Stage 4: extract meta
        $this->extractEntry($zip, 'meta/schema_version.txt',   $targetRoot . '/meta/schema_version.txt');
        $this->extractEntry($zip, 'meta/platform_version.txt', $targetRoot . '/meta/platform_version.txt');

        // Stage 5: extract README (Persian installation guide)
        $this->extractEntry($zip, 'README.txt', $targetRoot . '/README.txt');

        $zip->close();

        return ['status' => 'ok', 'manifest' => $manifest, 'errors' => []];
    }

    private function extractEntry(\ZipArchive $zip, string $entryName, string $destPath): void
    {
        $content = $zip->getFromName($entryName);
        if ($content === false) {
            return;
        }
        if (!is_dir(dirname($destPath))) {
            mkdir(dirname($destPath), 0775, true);
        }
        file_put_contents($destPath, $content);
    }

    private function extractPrefix(\ZipArchive $zip, string $prefix, string $destDir): void
    {
        if (!is_dir($destDir)) {
            mkdir($destDir, 0775, true);
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || !str_starts_with($name, $prefix)) {
                continue;
            }
            $relative = substr($name, strlen($prefix));
            if ($relative === '' || $relative === '/') {
                continue;
            }

            $targetPath = $destDir . '/' . $relative;

            // Directory entry
            if (str_ends_with($relative, '/')) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0775, true);
                }
                continue;
            }

            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }
            if (!is_dir(dirname($targetPath))) {
                mkdir(dirname($targetPath), 0775, true);
            }
            file_put_contents($targetPath, $content);
        }
    }
}
