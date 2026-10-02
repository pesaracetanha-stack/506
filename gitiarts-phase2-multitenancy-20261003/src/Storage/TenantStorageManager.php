<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Storage;

use GitiArts\Phase2\Contracts\TenantStorageInterface;
use GitiArts\Phase2\Tenant\TenantPathResolver;

/**
 * TenantStorageManager
 *
 * Factory + facade over the active driver. Reads the configured driver
 * from env, instantiates the proper implementation, and returns it.
 *
 * Application code uses this exclusively; it never instantiates a driver
 * directly. This is the single switch-over point if we change drivers
 * globally (e.g. SQLite → PostgreSQL migration).
 */
final class TenantStorageManager
{
    private static ?TenantStorageInterface $instance = null;

    public static function getDriver(): TenantStorageInterface
    {
        if (self::$instance instanceof TenantStorageInterface) {
            return self::$instance;
        }

        $driver = strtolower(\GitiArts\Phase2\Support\Env::get('TENANT_STORAGE_DRIVER', 'sqlite') ?? 'sqlite');

        $resolver = new TenantPathResolver(
            \GitiArts\Phase2\Support\Env::get('SQLITE_STORAGE_ROOT', '/storage/tenants') ?? '/storage/tenants'
        );

        return match ($driver) {
            'sqlite', 'sqlite3'  => self::$instance = new Drivers\SQLiteTenantDriver($resolver),
            'postgres', 'pgsql'  => self::$instance = new Drivers\PostgreSQLSchemaTenantDriver(),
            default              => throw new \InvalidArgumentException(
                sprintf('Unknown TENANT_STORAGE_DRIVER: %s', $driver)
            ),
        };
    }

    /**
     * Force a driver — used by tests and by the SQLite→PostgreSQL
     * migration tooling to write to both drivers simultaneously.
     */
    public static function override(TenantStorageInterface $driver): void
    {
        self::$instance = $driver;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
