<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Rollback script: PostgreSQL → SQLite.
 *
 * Copies data back from the PostgreSQL schema-per-tenant into the
 * SQLite file. Used only as a last-resort rollback if the PG cutover
 * fails catastrophically within the maintenance window.
 *
 * Usage:
 *   php scripts/rollback_pg_to_sqlite.php <tenant_uuid>
 */

use GitiArts\Phase2\Storage\Drivers\PostgreSQLSchemaTenantDriver;
use GitiArts\Phase2\Storage\Drivers\SQLiteTenantDriver;
use GitiArts\Phase2\Support\Env;
use GitiArts\Phase2\Tenant\TenantPathResolver;

require __DIR__ . '/../vendor/autoload.php';

Env::load(__DIR__ . '/../.env');

$argv = $_SERVER['argv'];
array_shift($argv);
$tenantId = $argv[0] ?? null;

if ($tenantId === null) {
    fwrite(STDERR, "Usage: php rollback_pg_to_sqlite.php <tenant_uuid>\n");
    exit(1);
}

$resolver = new TenantPathResolver(
    Env::get('SQLITE_STORAGE_ROOT', '/storage/tenants') ?? '/storage/tenants'
);

$pg = new PostgreSQLSchemaTenantDriver();
$sq = new SQLiteTenantDriver($resolver);

fwrite(STDOUT, "Rolling back tenant {$tenantId} from PostgreSQL to SQLite\n");

// Provision (or recreate) SQLite DB
$resolver->ensureTenantRoot($tenantId);

$srcPdo = $pg->getConnection($tenantId);
$destPdo = $sq->getConnection($tenantId);

// Apply migrations to the SQLite side (idempotent)
$migrationsDir = __DIR__ . '/../migrations';
if (is_dir($migrationsDir)) {
    $files = glob($migrationsDir . '/*.php') ?: [];
    sort($files);
    foreach ($files as $file) {
        $migration = require $file;
        if (!is_object($migration) || !method_exists($migration, 'up')) {
            continue;
        }
        $schema = new \GitiArts\Phase2\Database\SchemaBuilder('');
        foreach ($migration->up($schema) as $sql) {
            try { $destPdo->exec($sql); } catch (\PDOException $e) {}
        }
    }
}

// Copy every table from PG schema back to SQLite
$tables = listTablesFromPg($srcPdo);
foreach ($tables as $table) {
    if ($table === 'migrations') {
        continue;
    }
    copyTableBack($srcPdo, $destPdo, $table);
}

fwrite(STDOUT, "Rollback complete.\n");

function listTablesFromPg(\PDO $pdo): array
{
    $rows = $pdo->query("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    return $rows ?: [];
}

function copyTableBack(\PDO $src, \PDO $dest, string $table): void
{
    fwrite(STDOUT, "  table: {$table} ... ");
    $dest->exec("DELETE FROM {$table}");

    $stmt = $src->query("SELECT * FROM {$table}");
    if (!$stmt) { fwrite(STDOUT, "skip\n"); return; }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { fwrite(STDOUT, "0 rows\n"); return; }

    $columns = array_keys($rows[0]);
    $colList = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $insert = $dest->prepare("INSERT INTO {$table} ({$colList}) VALUES ({$placeholders})");

    $dest->beginTransaction();
    try {
        $count = 0;
        foreach ($rows as $row) { $insert->execute(array_values($row)); $count++; }
        $dest->commit();
        fwrite(STDOUT, "{$count} rows\n");
    } catch (\Throwable $e) {
        $dest->rollBack();
        fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    }
}
