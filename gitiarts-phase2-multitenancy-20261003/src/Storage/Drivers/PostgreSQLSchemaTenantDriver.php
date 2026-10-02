<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Storage\Drivers;

use GitiArts\Phase2\Contracts\TenantStorageInterface;
use GitiArts\Phase2\Support\Env;
use GitiArts\Phase2\Tenant\TenantPathResolver;

/**
 * PostgreSQLSchemaTenantDriver
 *
 * Future-state driver for the schema-per-tenant topology. Implemented as
 * a STUB with full method signatures so the SQLite → PostgreSQL migration
 * can be performed WITHOUT touching application code — we simply swap the
 * driver via .env and run the migration script.
 *
 * Topology:
 *   - Single PostgreSQL cluster (database: gitiarts).
 *   - Each tenant gets a dedicated schema named after a deterministic
 *     prefix + tenant UUID suffix (e.g. tenant_a1b2c3...).
 *   - All queries run with search_path set to the tenant schema, so
 *     application code does not need to prefix table names.
 *
 * Migration target: trigger at ~500 active tenants. See docs/MIGRATION_PLAN.md.
 *
 * NOTE: file storage still uses the hash-based path system from
 * TenantPathResolver — only the DB engine changes when migrating.
 */
final class PostgreSQLSchemaTenantDriver implements TenantStorageInterface
{
    private ?\PDO $masterConnection = null;
    private array $tenantConnections = [];
    private array $metaCache = [];

    private readonly string $host;
    private readonly int    $port;
    private readonly string $database;
    private readonly string $username;
    private readonly string $password;
    private readonly string $charset;
    private readonly string $schemaPrefix;

    public function __construct()
    {
        $this->host         = Env::get('PG_HOST', '127.0.0.1') ?? '127.0.0.1';
        $this->port         = Env::getInt('PG_PORT', 5432);
        $this->database     = Env::get('PG_DATABASE', 'gitiarts') ?? 'gitiarts';
        $this->username     = Env::get('PG_USERNAME', 'gitiarts_app') ?? 'gitiarts_app';
        $this->password     = Env::get('PG_PASSWORD', '') ?? '';
        $this->charset      = Env::get('PG_CHARSET', 'utf8') ?? 'utf8';
        $this->schemaPrefix = Env::get('PG_SCHEMA_PREFIX', 'tenant_') ?? 'tenant_';
    }

    public function getDriverName(): string
    {
        return 'postgres';
    }

    public function getConnection(string $tenantId): \PDO
    {
        if (isset($this->tenantConnections[$tenantId])) {
            return $this->tenantConnections[$tenantId];
        }

        // Master connection is reused; we only swap search_path per tenant.
        $pdo = $this->getMasterConnection();
        $schema = $this->schemaName($tenantId);

        // Ensure schema exists (idempotent for already-provisioned tenants)
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?');
        $stmt->execute([$schema]);
        if (!$stmt->fetchColumn()) {
            throw new \RuntimeException(
                sprintf('PostgreSQL schema "%s" not provisioned for tenant %s', $schema, $tenantId)
            );
        }

        $pdo->exec(sprintf('SET search_path TO %s, public', $schema));

        $this->tenantConnections[$tenantId] = $pdo;
        return $pdo;
    }

    public function putFile(string $tenantId, string $category, string $filename, string $sourcePath): string
    {
        $resolver = new TenantPathResolver(
            Env::get('SQLITE_STORAGE_ROOT', '/storage/tenants') ?? '/storage/tenants'
        );
        $resolver->ensureTenantRoot($tenantId);
        $dest = $resolver->getUploadCategoryPath($tenantId, $category) . '/' . basename($filename);
        if (!copy($sourcePath, $dest)) {
            throw new \RuntimeException(sprintf('Failed to copy file to %s', $dest));
        }
        return substr($dest, strlen(rtrim(Env::get('SQLITE_STORAGE_ROOT', '/storage/tenants') ?? '/storage/tenants', '/')));
    }

    public function getFilePath(string $tenantId, string $category, string $filename): string
    {
        $resolver = new TenantPathResolver(
            Env::get('SQLITE_STORAGE_ROOT', '/storage/tenants') ?? '/storage/tenants'
        );
        return $resolver->getUploadCategoryPath($tenantId, $category) . '/' . basename($filename);
    }

    public function getTenantRoot(string $tenantId): string
    {
        $resolver = new TenantPathResolver(
            Env::get('SQLITE_STORAGE_ROOT', '/storage/tenants') ?? '/storage/tenants'
        );
        return $resolver->getTenantRoot($tenantId);
    }

    public function getMeta(string $tenantId, string $key): mixed
    {
        return $this->getAllMeta($tenantId)[$key] ?? null;
    }

    public function setMeta(string $tenantId, string $key, mixed $value): void
    {
        $all = $this->getAllMeta($tenantId);
        $all[$key] = $value;

        // Upsert into the platform-level tenant_meta table
        $pdo = $this->getMasterConnection();
        $pdo->prepare(
            'INSERT INTO platform.tenant_meta (tenant_id, meta_key, meta_value, updated_at)
             VALUES (?, ?, ?, NOW())
             ON CONFLICT (tenant_id, meta_key)
             DO UPDATE SET meta_value = EXCLUDED.meta_value, updated_at = NOW()'
        )->execute([$tenantId, $key, json_encode($value, JSON_UNESCAPED_UNICODE)]);

        $this->metaCache[$tenantId] = $all;
    }

    public function getAllMeta(string $tenantId): array
    {
        if (isset($this->metaCache[$tenantId])) {
            return $this->metaCache[$tenantId];
        }

        $pdo = $this->getMasterConnection();
        $stmt = $pdo->prepare(
            'SELECT meta_key, meta_value FROM platform.tenant_meta WHERE tenant_id = ?'
        );
        $stmt->execute([$tenantId]);

        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $result[$row['meta_key']] = json_decode($row['meta_value'], true);
        }
        $this->metaCache[$tenantId] = $result;
        return $result;
    }

    public function provision(string $tenantId): void
    {
        $pdo = $this->getMasterConnection();
        $schema = $this->schemaName($tenantId);

        $pdo->exec(sprintf('CREATE SCHEMA IF NOT EXISTS %s', $schema));

        // Apply all tenant-scoped migrations into the new schema
        // (handled by MigrationRunner; called separately by the bootstrap script).
    }

    public function destroy(string $tenantId): void
    {
        $pdo = $this->getMasterConnection();
        $schema = $this->schemaName($tenantId);

        $pdo->exec(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $schema));
        $pdo->prepare('DELETE FROM platform.tenant_meta WHERE tenant_id = ?')->execute([$tenantId]);

        unset($this->tenantConnections[$tenantId], $this->metaCache[$tenantId]);
    }

    private function getMasterConnection(): \PDO
    {
        if ($this->masterConnection instanceof \PDO) {
            return $this->masterConnection;
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;options=--client_encoding=%s',
            $this->host,
            $this->port,
            $this->database,
            $this->charset
        );

        $pdo = new \PDO($dsn, $this->username, $this->password, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Ensure platform schema + tenant_meta table exist for metadata storage.
        $pdo->exec('CREATE SCHEMA IF NOT EXISTS platform');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS platform.tenant_meta (
                tenant_id   UUID NOT NULL,
                meta_key    TEXT NOT NULL,
                meta_value  TEXT,
                updated_at  TIMESTAMP NOT NULL DEFAULT NOW(),
                PRIMARY KEY (tenant_id, meta_key)
            )'
        );

        $this->masterConnection = $pdo;
        return $pdo;
    }

    private function schemaName(string $tenantId): string
    {
        // Replace dashes with underscores so the schema name is a valid PG identifier.
        return $this->schemaPrefix . str_replace('-', '_', strtolower($tenantId));
    }
}
