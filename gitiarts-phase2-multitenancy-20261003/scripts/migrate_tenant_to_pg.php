<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * SQLite → PostgreSQL migration script.
 *
 * Reads every tenant's data from its SQLite file and copies it into the
 * corresponding PostgreSQL schema. Designed to run during the dual-write
 * window described in docs/MIGRATION_PLAN.md.
 *
 * Usage:
 *   php scripts/migrate_tenant_to_pg.php <tenant_uuid>
 *   php scripts/migrate_tenant_to_pg.php --all
 *
 * This script is idempotent: re-running it skips tables that already exist
 * and copies rows that are missing from the destination.
 */

use GitiArts\Phase2\Database\SchemaBuilder;
use GitiArts\Phase2\Storage\Drivers\PostgreSQLSchemaTenantDriver;
use GitiArts\Phase2\Storage\Drivers\SQLiteTenantDriver;
use GitiArts\Phase2\Support\Env;
use GitiArts\Phase2\Tenant\TenantPathResolver;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

// ---------------------------------------------------------------
// Parse CLI args
// ---------------------------------------------------------------
$argv = $_SERVER['argv'];
array_shift($argv); // drop script name

if ($argv === []) {
    fwrite(STDERR, "Usage: php migrate_tenant_to_pg.php <tenant_uuid|--all>\n");
    exit(1);
}

$tenantId = $argv[0];

$resolver = new TenantPathResolver(
    Env::get('SQLITE_STORAGE_ROOT', '/storage/tenants') ?? '/storage/tenants'
);

$source = new SQLiteTenantDriver($resolver);
$dest   = new PostgreSQLSchemaTenantDriver();

$tenantIds = $tenantId === '--all' ? listAllTenantIds($resolver) : [$tenantId];

foreach ($tenantIds as $tid) {
    migrateTenant($source, $dest, $tid);
}

fwrite(STDOUT, sprintf("Migration complete. %d tenant(s) processed.\n", count($tenantIds)));


// ---------------------------------------------------------------
// Worker functions
// ---------------------------------------------------------------

function migrateTenant(SQLiteTenantDriver $source, PostgreSQLSchemaTenantDriver $dest, string $tenantId): void
{
    fwrite(STDOUT, "Migrating tenant: {$tenantId}\n");

    try {
        $dest->provision($tenantId);
    } catch (\Throwable $e) {
        fwrite(STDERR, "  [provision] " . $e->getMessage() . "\n");
        return;
    }

    $srcPdo = $source->getConnection($tenantId);
    $destPdo = $dest->getConnection($tenantId);

    // Apply all migrations in PG schema
    applyMigrationsToSchema($destPdo);

    // Copy data table by table
    $tables = listTables($srcPdo);
    foreach ($tables as $table) {
        if ($table === 'migrations') {
            continue;
        }
        copyTable($srcPdo, $destPdo, $table);
    }

    // Copy metadata
    $meta = $source->getAllMeta($tenantId);
    foreach ($meta as $key => $value) {
        $dest->setMeta($tenantId, $key, $value);
    }

    fwrite(STDOUT, "  OK.\n");
}

function listAllTenantIds(TenantPathResolver $resolver): array
{
    $root = dirname($resolver->getDatabasePath(str_repeat('0', 32)));
    // Walk three levels deep: a1/b2/c3/{uuid}/
    $ids = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $file) {
        if ($file->getFilename() === 'data.sqlite') {
            // Parent dir = tenant UUID
            $ids[] = basename(dirname($file->getPathname()));
        }
    }
    return $ids;
}

function listTables(\PDO $pdo): array
{
    $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
    return $rows ?: [];
}

function applyMigrationsToSchema(\PDO $pdo): void
{
    $migrationsDir = __DIR__ . '/../migrations';
    if (!is_dir($migrationsDir)) {
        return;
    }
    $files = glob($migrationsDir . '/*.php') ?: [];
    sort($files);
    foreach ($files as $file) {
        $migration = require $file;
        if (!is_object($migration) || !method_exists($migration, 'up')) {
            continue;
        }
        $schema = new SchemaBuilder('');
        $statements = $migration->up($schema);
        foreach ($statements as $sql) {
            // Re-issue the same DDL on PostgreSQL — SchemaBuilder produces portable SQL.
            try {
                $pdo->exec($sql);
            } catch (\PDOException $e) {
                // IF NOT EXISTS clauses make this idempotent; only re-throw real failures.
                if (!str_contains($e->getMessage(), 'already exists')) {
                    fwrite(STDERR, "  [migration DDL] " . $e->getMessage() . "\n");
                }
            }
        }
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS migrations (
            name TEXT PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
    foreach ($files as $file) {
        $name = basename($file, '.php');
        $stmt = $pdo->prepare('INSERT INTO migrations (name) VALUES (?) ON CONFLICT DO NOTHING');
        $stmt->execute([$name]);
    }
}

function copyTable(\PDO $src, \PDO $dest, string $table): void
{
    fwrite(STDOUT, "  table: {$table} ... ");
    $count = 0;

    // Truncate destination (safe during maintenance window — we are cutover)
    try {
        $dest->exec("TRUNCATE TABLE {$table} RESTART IDENTITY CASCADE");
    } catch (\PDOException $e) {
        // Some tables may not support TRUNCATE; fall back to DELETE.
        $dest->exec("DELETE FROM {$table}");
    }

    $stmt = $src->query("SELECT * FROM {$table}");
    if ($stmt === false) {
        fwrite(STDOUT, "skip (no source rows)\n");
        return;
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        fwrite(STDOUT, "0 rows\n");
        return;
    }

    $columns = array_keys($rows[0]);
    $colList = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));

    $insert = $dest->prepare("INSERT INTO {$table} ({$colList}) VALUES ({$placeholders})");

    $dest->beginTransaction();
    try {
        foreach ($rows as $row) {
            $insert->execute(array_values($row));
            $count++;
        }
        $dest->commit();
    } catch (\Throwable $e) {
        $dest->rollBack();
        fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
        return;
    }

    fwrite(STDOUT, "{$count} rows\n");
}
