<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Database;

use GitiArts\Phase2\Contracts\TenantStorageInterface;

/**
 * MigrationRunner
 *
 * Applies migration files to a tenant's database. Migrations live in
 * the migrations/ directory and implement a simple contract:
 *
 *   - Filename pattern: YYYY_MM_DD_HHMMSS_snake_case_name.php
 *   - Class name: PascalCase of the snake_case name + "_Migration"
 *   - Each migration exposes: up(SchemaBuilder $schema): array<string>
 *     and optionally down(SchemaBuilder $schema): array<string>
 *   - Returns an array of raw SQL statements to execute.
 *
 * The runner tracks applied migrations in a `migrations` table inside
 * the tenant's database, ensuring idempotency across re-runs.
 */
final class MigrationRunner
{
    public function __construct(
        private readonly TenantStorageInterface $storage,
        private readonly string $migrationsDir
    ) {
    }

    /**
     * Run all pending migrations for the given tenant.
     *
     * @param string $tenantId
     * @return string[] Names of migrations applied (empty if none pending).
     */
    public function migrate(string $tenantId): array
    {
        $pdo = $this->storage->getConnection($tenantId);

        $this->ensureMigrationsTable($pdo);

        $applied = $this->listApplied($pdo);
        $files   = $this->listMigrationFiles();

        $pending = array_diff($files, $applied);
        sort($pending);

        $newlyApplied = [];
        foreach ($pending as $name) {
            $statements = $this->loadMigrationUp($name);
            $this->executeInTransaction($pdo, $statements);

            $stmt = $pdo->prepare('INSERT INTO migrations (name, applied_at) VALUES (?, ?)');
            $stmt->execute([$name, date('Y-m-d H:i:s')]);
            $newlyApplied[] = $name;
        }

        return $newlyApplied;
    }

    /**
     * Roll back the most recent N migrations for a tenant.
     *
     * @param string $tenantId
     * @param int $steps Number of migrations to roll back (default 1).
     * @return string[] Names of migrations rolled back.
     */
    public function rollback(string $tenantId, int $steps = 1): array
    {
        $pdo = $this->storage->getConnection($tenantId);
        $this->ensureMigrationsTable($pdo);

        $applied = $this->listApplied($pdo);
        rsort($applied); // newest first

        $toRollback = array_slice($applied, 0, max(0, $steps));

        $rolledBack = [];
        foreach ($toRollback as $name) {
            $statements = $this->loadMigrationDown($name);
            if ($statements !== []) {
                $this->executeInTransaction($pdo, $statements);
            }
            $stmt = $pdo->prepare('DELETE FROM migrations WHERE name = ?');
            $stmt->execute([$name]);
            $rolledBack[] = $name;
        }

        return $rolledBack;
    }

    private function ensureMigrationsTable(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                name        TEXT PRIMARY KEY,
                applied_at  TEXT NOT NULL
            )'
        );
    }

    /** @return string[] */
    private function listApplied(\PDO $pdo): array
    {
        $rows = $pdo->query('SELECT name FROM migrations ORDER BY name ASC')->fetchAll(\PDO::FETCH_COLUMN);
        return $rows ?: [];
    }

    /** @return string[] */
    private function listMigrationFiles(): array
    {
        if (!is_dir($this->migrationsDir)) {
            return [];
        }
        $files = glob($this->migrationsDir . '/*.php') ?: [];
        $names = array_map(fn(string $p) => basename($p, '.php'), $files);
        sort($names);
        return $names;
    }

    /** @return string[] */
    private function loadMigrationUp(string $name): array
    {
        $path = $this->migrationsDir . '/' . $name . '.php';
        if (!is_file($path)) {
            return [];
        }
        $migration = require $path;
        if (!is_object($migration) || !method_exists($migration, 'up')) {
            return [];
        }
        $schema = new SchemaBuilder(''); // dummy — used as parameter type only
        $result = $migration->up($schema);
        return is_array($result) ? $result : [];
    }

    /** @return string[] */
    private function loadMigrationDown(string $name): array
    {
        $path = $this->migrationsDir . '/' . $name . '.php';
        if (!is_file($path)) {
            return [];
        }
        $migration = require $path;
        if (!is_object($migration) || !method_exists($migration, 'down')) {
            return [];
        }
        $schema = new SchemaBuilder('');
        $result = $migration->down($schema);
        return is_array($result) ? $result : [];
    }

    /** @param string[] $statements */
    private function executeInTransaction(\PDO $pdo, array $statements): void
    {
        if ($statements === []) {
            return;
        }
        $pdo->beginTransaction();
        try {
            foreach ($statements as $sql) {
                $pdo->exec($sql);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
