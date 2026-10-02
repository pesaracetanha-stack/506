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
 * TenantValidator
 *
 * Verifies the integrity and compatibility of a .giti archive before
 * it is handed to TenantImporter. Two stages:
 *
 *   1. Structural: every required file exists in the ZIP.
 *   2. Cryptographic: SHA-256 checksum matches the value in manifest.json.
 *   3. Compatibility: schema_version and platform_version are within
 *      supported ranges.
 *
 * Returns a structured result instead of throwing, so the caller can
 * decide how to surface validation errors to the operator.
 */
final class TenantValidator
{
    public const RESULT_OK              = 'ok';
    public const RESULT_FILE_NOT_FOUND  = 'file_not_found';
    public const RESULT_CHECKSUM_MISMATCH = 'checksum_mismatch';
    public const RESULT_SCHEMA_TOO_OLD  = 'schema_too_old';
    public const RESULT_SCHEMA_TOO_NEW  = 'schema_too_new';
    public const RESULT_MANIFEST_INVALID = 'manifest_invalid';

    /** @var string[] Supported schema versions (must match migration filenames). */
    private const SUPPORTED_SCHEMAS = [
        '2026_10_03_000001',
        '2026_10_03_000002',
    ];

    /**
     * Validate a .giti archive.
     *
     * @param string $gitiPath Absolute path to the .giti file.
     * @return array{status:string,errors:string[],manifest:?array}
     */
    public function validate(string $gitiPath): array
    {
        $errors = [];

        if (!is_file($gitiPath)) {
            return [
                'status'    => self::RESULT_FILE_NOT_FOUND,
                'errors'    => [Lang::get('export.error.file_not_found')],
                'manifest'  => null,
            ];
        }

        $zip = new \ZipArchive();
        if ($zip->open($gitiPath) !== true) {
            return [
                'status'    => self::RESULT_FILE_NOT_FOUND,
                'errors'    => [Lang::get('export.error.cannot_open_zip')],
                'manifest'  => null,
            ];
        }

        // 1. Structural: required files must exist
        $required = ['manifest.json', 'database/tenant.sqlite', 'config/settings.json', 'README.txt'];
        foreach ($required as $rel) {
            if ($zip->locateName($rel) === false) {
                $errors[] = Lang::get('export.error.missing_file', ['file' => $rel]);
            }
        }

        // 2. Manifest must be valid JSON
        $manifestRaw = $zip->getFromName('manifest.json');
        if ($manifestRaw === false) {
            $errors[] = Lang::get('export.error.manifest_unreadable');
            $zip->close();
            return ['status' => self::RESULT_MANIFEST_INVALID, 'errors' => $errors, 'manifest' => null];
        }

        $manifest = json_decode($manifestRaw, true);
        if (!is_array($manifest)) {
            $errors[] = Lang::get('export.error.manifest_invalid_json');
            $zip->close();
            return ['status' => self::RESULT_MANIFEST_INVALID, 'errors' => $errors, 'manifest' => null];
        }

        // Close zip so we can hash the file
        $zip->close();

        // 3. Checksum: recompute SHA-256 of the archive and compare.
        // IMPORTANT: the manifest is appended AFTER the original checksum
        // computation in TenantExporter, so we must strip the manifest
        // entry's bytes before comparing. In practice, we re-run the
        // same flow: hash the file as-is, but accept that the manifest
        // entry's own bytes are excluded from the reference checksum.
        // The exporter writes the checksum BEFORE adding the manifest
        // (see TenantExporter::export), so we verify against the same
        // pre-manifest SHA-256 by computing the hash of the file with
        // the manifest entry removed.
        $actualChecksum = $this->recomputeChecksumExcludingManifest($gitiPath);
        $expectedChecksum = $manifest['checksum'] ?? '';

        if ($expectedChecksum === '' || !hash_equals($expectedChecksum, $actualChecksum)) {
            $errors[] = Lang::get('export.error.checksum_mismatch');
            return ['status' => self::RESULT_CHECKSUM_MISMATCH, 'errors' => $errors, 'manifest' => $manifest];
        }

        // 4. Schema compatibility
        $schemaVersion = $manifest['schema_version'] ?? '';
        if (!in_array($schemaVersion, self::SUPPORTED_SCHEMAS, true)) {
            $tooOld = $this->schemaOlderThan($schemaVersion, self::SUPPORTED_SCHEMAS[0]);
            $errors[] = $tooOld
                ? Lang::get('export.error.schema_too_old', ['have' => $schemaVersion, 'need' => self::SUPPORTED_SCHEMAS[0]])
                : Lang::get('export.error.schema_too_new', ['have' => $schemaVersion, 'max' => self::SUPPORTED_SCHEMAS[count(self::SUPPORTED_SCHEMAS) - 1]]);
            return [
                'status'    => $tooOld ? self::RESULT_SCHEMA_TOO_OLD : self::RESULT_SCHEMA_TOO_NEW,
                'errors'    => $errors,
                'manifest'  => $manifest,
            ];
        }

        return ['status' => self::RESULT_OK, 'errors' => [], 'manifest' => $manifest];
    }

    /**
     * Recompute SHA-256 of the .giti file with the manifest.json entry
     * removed. This mirrors the exporter's behavior of computing the
     * checksum before the manifest is appended.
     */
    private function recomputeChecksumExcludingManifest(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return '';
        }

        $tmp = tempnam(sys_get_temp_dir(), 'giti_');
        if ($tmp === false) {
            $zip->close();
            return '';
        }
        // tempnam creates a file; ZipArchive::open with CREATE will overwrite.
        @unlink($tmp);

        $out = new \ZipArchive();
        if ($out->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $zip->close();
            return '';
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || $name === 'manifest.json') {
                continue;
            }
            $content = $zip->getFromIndex($i);
            if ($content !== false) {
                $out->addFromString($name, $content);
            }
        }
        $out->close();
        $zip->close();

        $hash = hash_file('sha256', $tmp);
        @unlink($tmp);
        return $hash;
    }

    private function schemaOlderThan(string $candidate, string $minimum): bool
    {
        return strcmp($candidate, $minimum) < 0;
    }
}
