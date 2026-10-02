# SQLite → PostgreSQL Migration Plan

## Trigger Conditions

Migration to PostgreSQL schema-per-tenant topology is triggered when ANY of
these conditions are met:

| Trigger                                       | Threshold                          |
| --------------------------------------------- | ---------------------------------- |
| Active tenants                                | ≥ 500                              |
| Average DB file size                          | ≥ 500 MB                           |
| Concurrent requests/sec sustained             | ≥ 200 across all tenants           |
| Backup window exceeded                        | > 4 hours per nightly cycle         |
| Any tenant requesting SQL features unavailable in SQLite (window functions with CTEs, full-text `tsvector`, advanced JSON operators) | Single request |

At **500 active tenants** with an average of 1000 orders each, the SQLite
model still works but starts showing I/O contention under concurrent backups.

## Target Architecture

```
┌─────────────────────────────────────────────┐
│   Application Code (unchanged)              │
│   ↓ TenantStorageInterface::getConnection()│
├─────────────────────────────────────────────┤
│   PostgreSQLSchemaTenantDriver             │
│   ↓ search_path = tenant_<uuid>            │
├─────────────────────────────────────────────┤
│   Single PostgreSQL 15+ cluster             │
│   Database: gitiarts                        │
│   Schemas:                                  │
│     - platform (directory + tenant_meta)   │
│     - tenant_<uuid_1>                      │
│     - tenant_<uuid_2>                      │
│     - ...                                   │
└─────────────────────────────────────────────┘
```

Files (uploads, logos, receipts) STAY on the hash-based path system
described in `TenantPathResolver`. Only the DB engine changes.

## Zero-Downtime Cutover Strategy

### Phase 0 — Pre-Flight (Day -7)

- Provision the PostgreSQL 15 cluster on the same VPS or a sibling VPS.
- Apply baseline tuning: `shared_buffers = 4GB`, `work_mem = 16MB`,
  `maintenance_work_mem = 512MB`, `max_connections = 200`.
- Create the `gitiarts` database and `platform` schema.
- Create the application role `gitiarts_app` with `CONNECT` on `gitiarts`
  and `CREATE` on `platform`.

### Phase 1 — Dual-Write (Day -3 to Day 0)

- Flip env: `MIGRATION_DUAL_WRITE=true`
- Application writes to BOTH SQLite and PostgreSQL for every mutating query.
- Reads STILL come from SQLite (source of truth).
- Run `scripts/migrate_tenant_to_pg.php --all` to backfill historical data.
- Monitor for write errors in PG (logged to `/storage/logs/pg_dual.log`).

### Phase 2 — Verify (Day 0)

- Run `scripts/verify_dual_write.php` (not yet implemented — a row-count
  + checksum diff between SQLite and PG for every table).
- Any divergence → fix data, re-run verify. DO NOT proceed until clean.

### Phase 3 — Cutover (Day 0, Maintenance Window 02:00–04:00 Tehran time)

- Set platform in maintenance mode (read-only).
- Run final backfill: `scripts/migrate_tenant_to_pg.php --all --incremental`
- Flip env: `TENANT_STORAGE_DRIVER=postgres`
- Flip env: `MIGRATION_DUAL_WRITE=false`
- Take platform out of maintenance mode.

### Phase 4 — Soak (Day +1 to Day +7)

- Keep SQLite files in place but read-only (legacy backup).
- Monitor query latency; verify no regressions.
- After 7 clean days, archive SQLite files to cold storage (do not delete).

## Rollback Plan

If PostgreSQL shows critical issues during Phase 3 (data corruption,
latency > 5x baseline, connection storms):

1. Flip env back: `TENANT_STORAGE_DRIVER=sqlite`
2. Run `scripts/rollback_pg_to_sqlite.php <tenant_uuid>` per affected tenant
3. Re-enable dual-write (Phase 1) and investigate root cause
4. Do NOT re-attempt cutover for at least 7 days

Rollback is **only** safe within the 2-hour maintenance window because
SQLite was kept read-only during cutover. Once Phase 4 starts, new writes
have happened on PostgreSQL that SQLite doesn't have — rollback becomes
destructive.

## Operational Concerns

### Backup Strategy (PostgreSQL)

- `pg_dump --schema=platform` daily → off-site backup
- `pg_dump --schema=tenant_<uuid>` per-tenant on rotation
- Base backup + WAL archiving via `pg_basebackup` for PITR

### Connection Pooling

- Use PgBouncer in transaction-pooling mode
- Max client connections per VPS: 200
- Pool size: 50 server connections (sufficient for ~5 concurrent tenants
  with 10 queries each per request burst)

### Schema Migration Per-Tenant

When a new migration file is added:

1. Add it to `migrations/`
2. Run `php scripts/migrate_tenant_to_pg.php <tenant>` for each tenant OR
3. Add a background worker that applies pending migrations as tenants
   are accessed (lazy migration — useful for very large tenant counts)

## Risks

| Risk                                       | Mitigation                                            |
| ------------------------------------------ | ---------------------------------------------------- |
| Tenant-specific query fails on PG          | Verify in Phase 1 (dual-write catches it)            |
| Connection storm during cutover            | Use PgBouncer; cap `max_connections`                |
| Disk full from dual-write logs             | Monitor disk; alert at 80%                           |
| Schema drift between SQLite and PG         | SchemaBuilder generates portable DDL                 |
| Persian collation differences              | Both engines store TEXT in UTF-8; sorting handled by PHP ICU fallback |
| Backup window grows beyond 4h             | Trigger early at 400 tenants if backups > 3h         |
