<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Tenant;

use GitiArts\Phase2\Support\Env;

/**
 * TenantResolver
 *
 * Identifies which tenant is active for the current HTTP request.
 *
 * Two strategies, switchable via env:
 *   1. subdomain — miran.platform.ir → "miran"
 *   2. path      — platform.ir/t/miran → "miran"
 *
 * Slug lookups are cached in Redis (preferred) or a file cache fallback.
 * Cache hit avoids the database on every single request, which is critical
 * at 10k+ tenants / requests/sec.
 */
final class TenantResolver
{
    private const CACHE_TTL_DEFAULT = 300; // 5 minutes
    private const CACHE_KEY_PREFIX = 'tenant:slug:';

    public function __construct(
        private readonly \PDO $directoryDb,
        private readonly ?\Redis $redis = null,
        private readonly string $cacheRoot = '/storage/cache/tenant'
    ) {
    }

    /**
     * Resolve the active tenant UUID from request data.
     *
     * @param array<string,mixed> $server Typically $_SERVER.
     * @return array{uuid:?string,slug:?string,strategy:string}
     *   Returns null uuid/slug if no tenant matches. The caller MUST fail
     *   closed (show platform landing page or 404) when both are null.
     */
    public function resolveFromRequest(array $server): array
    {
        $strategy = Env::get('TENANT_RESOLUTION_STRATEGY', 'subdomain') ?? 'subdomain';

        if ($strategy === 'path') {
            return $this->resolveFromPath($server);
        }

        // Default: subdomain
        return $this->resolveFromSubdomain($server);
    }

    private function resolveFromSubdomain(array $server): array
    {
        $host       = (string) ($server['HTTP_HOST'] ?? '');
        $baseDomain = Env::get('PLATFORM_BASE_DOMAIN', 'platform.ir') ?? 'platform.ir';

        // Strip port if present
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;

        if (!str_ends_with($host, '.' . $baseDomain)) {
            // Either apex domain or unknown host → no tenant
            return ['uuid' => null, 'slug' => null, 'strategy' => 'subdomain'];
        }

        $prefix = substr($host, 0, -strlen('.' . $baseDomain));
        if ($prefix === '' || $prefix === 'www' || str_contains($prefix, '.')) {
            // Apex, www, or multi-level subdomain → no tenant
            return ['uuid' => null, 'slug' => null, 'strategy' => 'subdomain'];
        }

        $slug = strtolower($prefix);
        return [
            'uuid'     => $this->lookupTenantId($slug),
            'slug'     => $slug,
            'strategy' => 'subdomain',
        ];
    }

    private function resolveFromPath(array $server): array
    {
        $uri    = (string) ($server['REQUEST_URI'] ?? '/');
        $prefix = Env::get('TENANT_PATH_PREFIX', '/t/') ?? '/t/';

        // /t/miran/orders → slug = "miran"
        $pattern = '#^' . preg_quote($prefix, '#') . '([a-z0-9][a-z0-9\-]{1,63})#i';
        if (preg_match($pattern, $uri, $m)) {
            $slug = strtolower($m[1]);
            return [
                'uuid'     => $this->lookupTenantId($slug),
                'slug'     => $slug,
                'strategy' => 'path',
            ];
        }

        return ['uuid' => null, 'slug' => null, 'strategy' => 'path'];
    }

    /**
     * Look up the tenant UUID for a slug, using cache when possible.
     */
    private function lookupTenantId(string $slug): ?string
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $slug;

        // Try Redis first if configured
        if ($this->redis !== null) {
            $cached = $this->redis->get($cacheKey);
            if (is_string($cached)) {
                return $cached === 'NULL' ? null : $cached;
            }
        } else {
            $fileCache = $this->fileCachePath($slug);
            if (is_file($fileCache) && (time() - filemtime($fileCache)) < self::CACHE_TTL_DEFAULT) {
                $val = file_get_contents($fileCache);
                if ($val === 'NULL') {
                    return null;
                }
                return $val !== false ? $val : null;
            }
        }

        // Cache miss → directory DB lookup
        $stmt = $this->directoryDb->prepare(
            'SELECT uuid FROM tenants WHERE slug = :slug AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([':slug' => $slug]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $uuid = $row['uuid'] ?? null;

        // Write back to cache (including negative lookups to avoid DB hammer)
        $this->writeCache($cacheKey, $slug, $uuid);

        return $uuid;
    }

    private function writeCache(string $cacheKey, string $slug, ?string $uuid): void
    {
        $value = $uuid ?? 'NULL';

        if ($this->redis !== null) {
            $this->redis->setex($cacheKey, self::CACHE_TTL_DEFAULT, $value);
            return;
        }

        $path = $this->fileCachePath($slug);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $value, LOCK_EX);
    }

    private function fileCachePath(string $slug): string
    {
        $safe = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug)) ?? 'unknown';
        return rtrim($this->cacheRoot, '/') . '/' . substr($safe, 0, 2) . '/' . $safe . '.cache';
    }

    /**
     * Invalidate the cache entry for a slug (used after rename / delete).
     */
    public function invalidate(string $slug): void
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $slug;

        if ($this->redis !== null) {
            $this->redis->del($cacheKey);
            return;
        }

        $path = $this->fileCachePath($slug);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
