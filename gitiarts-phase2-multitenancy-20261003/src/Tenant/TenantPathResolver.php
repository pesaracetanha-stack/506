<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Tenant;

/**
 * TenantPathResolver
 *
 * Translates a tenant UUID into a hash-based filesystem path. With 10,000+
 * tenants, writing all files into a single directory destroys filesystem
 * performance on every OS (ext4 / XFS / NTFS). We shard by the first 6 hex
 * chars of the UUID into three levels of 256-way buckets:
 *
 *     UUID a1b2c3d4-e5f6-7890-abcd-ef0123456789
 *     → /storage/tenants/a1/b2/c3/a1b2c3d4-e5f6-7890-abcd-ef0123456789/
 *
 * This guarantees no directory ever holds more than ~256 entries, while
 * remaining fully deterministic from the UUID alone (no DB lookup needed
 * to find a tenant's files).
 */
final class TenantPathResolver
{
    public function __construct(
        private readonly string $storageRoot
    ) {
    }

    /**
     * Return the absolute root directory for a tenant.
     * The directory is NOT created here; callers must call ensureTenantRoot()
     * before writing into it.
     */
    public function getTenantRoot(string $tenantId): string
    {
        $tenantId = strtolower(trim($tenantId));
        $this->assertValidUuid($tenantId);

        $h1 = substr($tenantId, 0, 2);
        $h2 = substr($tenantId, 2, 2);
        $h3 = substr($tenantId, 4, 2);

        return rtrim($this->storageRoot, '/') . "/{$h1}/{$h2}/{$h3}/{$tenantId}";
    }

    public function getDatabasePath(string $tenantId): string
    {
        return $this->getTenantRoot($tenantId) . '/data.sqlite';
    }

    public function getUploadsRoot(string $tenantId): string
    {
        return $this->getTenantRoot($tenantId) . '/uploads';
    }

    public function getUploadCategoryPath(string $tenantId, string $category): string
    {
        $category = preg_replace('/[^a-z0-9-]/', '', strtolower($category)) ?? '';
        if ($category === '') {
            throw new \InvalidArgumentException('Invalid upload category');
        }
        return $this->getUploadsRoot($tenantId) . '/' . $category;
    }

    public function getMetaPath(string $tenantId): string
    {
        return $this->getTenantRoot($tenantId) . '/meta.json';
    }

    public function getChecksumPath(string $tenantId): string
    {
        return $this->getTenantRoot($tenantId) . '/.giti-checksum';
    }

    /**
     * Recursively create all directories needed for a tenant.
     */
    public function ensureTenantRoot(string $tenantId): void
    {
        $root = $this->getTenantRoot($tenantId);
        if (!is_dir($root)) {
            mkdir($root, 0775, true);
        }
        $uploads = $this->getUploadsRoot($tenantId);
        if (!is_dir($uploads)) {
            mkdir($uploads, 0775, true);
        }
        foreach (['logo', 'menu-items', 'receipts', 'employees'] as $cat) {
            $p = $this->getUploadCategoryPath($tenantId, $cat);
            if (!is_dir($p)) {
                mkdir($p, 0775, true);
            }
        }
    }

    private function assertValidUuid(string $uuid): void
    {
        // Accept canonical UUIDs with or without hyphens, plus hex strings of 32 chars.
        $stripped = str_replace('-', '', $uuid);
        if (!preg_match('/^[0-9a-f]{32}$/', $stripped)) {
            throw new \InvalidArgumentException(sprintf('Invalid tenant UUID: %s', $uuid));
        }
    }
}
