<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Database;

use GitiArts\Phase2\Storage\TenantStorageManager;
use GitiArts\Phase2\Tenant\TenantContext;

/**
 * Connection
 *
 * Convenience facade for getting the active tenant's PDO connection.
 * Reads the current tenant from TenantContext and asks the storage
 * manager for the configured driver.
 *
 * Typical usage:
 *   $pdo = Connection::tenant();
 *   $stmt = $pdo->prepare('SELECT * FROM menu_items WHERE id = ?');
 */
final class Connection
{
    private static array $cache = [];

    public static function tenant(): \PDO
    {
        $tenantId = TenantContext::requireCurrent();

        if (isset(self::$cache[$tenantId])) {
            return self::$cache[$tenantId];
        }

        $pdo = TenantStorageManager::getDriver()->getConnection($tenantId);
        self::$cache[$tenantId] = $pdo;
        return $pdo;
    }

    /**
     * Platform-level connection (for the directory DB that lists all tenants).
     * Not used in standalone mode.
     */
    public static function platform(): \PDO
    {
        if (isset(self::$cache['__platform__'])) {
            return self::$cache['__platform__'];
        }

        $dsn = 'sqlite:' . (\GitiArts\Phase2\Support\Env::get('PLATFORM_DB_PATH', '/storage/platform.sqlite') ?? '/storage/platform.sqlite');
        $pdo = new \PDO($dsn);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec("PRAGMA encoding = 'UTF-8'");
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        self::$cache['__platform__'] = $pdo;
        return $pdo;
    }

    public static function reset(): void
    {
        self::$cache = [];
    }
}
