<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Storage\Drivers;

use GitiArts\Phase2\Contracts\TenantStorageInterface;
use GitiArts\Phase2\Tenant\TenantPathResolver;

/**
 * SQLiteTenantDriver
 *
 * Production driver for the current platform mode. One SQLite database
 * file per tenant, stored under a hash-based path (see TenantPathResolver).
 *
 * Design choices:
 *   - WAL journal mode + busy_timeout to support concurrent reads.
 *   - Foreign keys ON for referential integrity.
 *   - utf8 encoding set on every connection so Persian text (تستی) sorts
 *     and stores correctly.
 *   - Metadata stored as a flat JSON file (meta.json) — avoids a separate
 *     "platform" database lookup for every meta read.
 */
final class SQLiteTenantDriver implements TenantStorageInterface
{
    private array $connections = [];
    private array $metaCache   = [];

    public function __construct(
        private readonly TenantPathResolver $pathResolver
    ) {
    }

    public function getDriverName(): string
    {
        return 'sqlite';
    }

    public function getConnection(string $tenantId): \PDO
    {
        if (isset($this->connections[$tenantId])) {
            return $this->connections[$tenantId];
        }

        $this->pathResolver->ensureTenantRoot($tenantId);
        $path = $this->pathResolver->getDatabasePath($tenantId);

        $dsn = 'sqlite:' . $path;
        $pdo = new \PDO($dsn);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);

        // Enable WAL + sane busy timeout
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        // UTF-8 encoding — Persian text correctness (تستی، منو، کارمند)
        $pdo->exec("PRAGMA encoding = 'UTF-8'");

        $this->connections[$tenantId] = $pdo;
        return $pdo;
    }

    public function putFile(string $tenantId, string $category, string $filename, string $sourcePath): string
    {
        $this->pathResolver->ensureTenantRoot($tenantId);
        $dest = $this->getFilePath($tenantId, $category, $filename);

        if (!is_dir(dirname($dest))) {
            mkdir(dirname($dest), 0775, true);
        }

        if (!copy($sourcePath, $dest)) {
            throw new \RuntimeException(sprintf('Failed to copy file to %s', $dest));
        }

        return $this->relativeUri($tenantId, $category, $filename);
    }

    public function getFilePath(string $tenantId, string $category, string $filename): string
    {
        return $this->pathResolver->getUploadCategoryPath($tenantId, $category) . '/' . basename($filename);
    }

    public function getTenantRoot(string $tenantId): string
    {
        return $this->pathResolver->getTenantRoot($tenantId);
    }

    public function getMeta(string $tenantId, string $key): mixed
    {
        $all = $this->getAllMeta($tenantId);
        return $all[$key] ?? null;
    }

    public function setMeta(string $tenantId, string $key, mixed $value): void
    {
        $all = $this->getAllMeta($tenantId);
        $all[$key] = $value;
        $this->writeMeta($tenantId, $all);
    }

    public function getAllMeta(string $tenantId): array
    {
        if (isset($this->metaCache[$tenantId])) {
            return $this->metaCache[$tenantId];
        }

        $path = $this->pathResolver->getMetaPath($tenantId);
        if (!is_file($path)) {
            $this->metaCache[$tenantId] = [];
            return [];
        }

        $raw = file_get_contents($path) ?: '{}';
        $data = json_decode($raw, true) ?: [];
        $this->metaCache[$tenantId] = $data;
        return $data;
    }

    private function writeMeta(string $tenantId, array $data): void
    {
        $path = $this->pathResolver->getMetaPath($tenantId);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents(
            $path,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
        $this->metaCache[$tenantId] = $data;
    }

    public function provision(string $tenantId): void
    {
        $this->pathResolver->ensureTenantRoot($tenantId);
        // Touch the DB file (creates it)
        $pdo = $this->getConnection($tenantId);
        $pdo->exec('SELECT 1');
    }

    public function destroy(string $tenantId): void
    {
        // Close connection first
        unset($this->connections[$tenantId], $this->metaCache[$tenantId]);

        $root = $this->pathResolver->getTenantRoot($tenantId);
        if (is_dir($root)) {
            $this->rrmdir($root);
        }
    }

    private function relativeUri(string $tenantId, string $category, string $filename): string
    {
        $root = $this->pathResolver->getTenantRoot($tenantId);
        $full = $root . '/uploads/' . $category . '/' . basename($filename);
        // Strip the leading storage root so the URI is portable across hosts.
        $storageRoot = rtrim(\GitiArts\Phase2\Support\Env::get('SQLITE_STORAGE_ROOT', '/storage/tenants') ?? '/storage/tenants', '/');
        $rel = substr($full, strlen($storageRoot));
        return ltrim($rel, '/');
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
