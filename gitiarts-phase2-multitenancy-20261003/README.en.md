# GitiArts Menu Platform — Phase 2 (Multi-Tenancy + Portability + Persian-First Localization)

Version: 5.0.5-test.5
Phase: 2

---

## Overview

This package implements Phase 2 of the GitiArts Menu Platform — a
multi-tenant SaaS platform for Iranian cafés and restaurants. Three
key capabilities are added:

1. **Multi-Tenancy** — multiple cafés on one platform instance with full
   data isolation
2. **Portability Guarantee** — any café can leave the platform at any time
   and install the entire system on their own hosting
3. **Persian-First Localization** — every UI string is Persian, every
   layout is RTL, every digit is Persian (۰-۹), every date is Jalali

## Architecture

```
┌──────────────────────────────────────────────────────┐
│ Application Code (uses Lang::get, Connection::tenant)│
└──────────────────────────────────────────────────────┘
                       │
        ┌──────────────┴───────────────┐
        │                              │
        ▼                              ▼
┌─────────────────┐         ┌──────────────────────┐
│ DeploymentContext│         │ TenantResolver       │
│ (platform mode?) │         │ (subdomain/path)     │
└─────────────────┘         └──────────────────────┘
        │                              │
        ▼                              ▼
┌──────────────────────────────────────────────────────┐
│ TenantStorageInterface                               │
│   ├── SQLiteTenantDriver       (current)            │
│   └── PostgreSQLSchemaTenantDriver (future, 500+)   │
└──────────────────────────────────────────────────────┘
```

Persian is handled architecturally:
- All Persian strings live in `resources/lang/fa.json`
- All Persian digits go through `PersianDigits::toPersian()`
- All Persian dates go through `JalaliDate::format()`
- All Persian currency goes through `Currency::format()` (Toman)
- All Persian validation messages come from `Validator::*Error()`

## Folder Structure

```
gitiarts-phase2/
├── composer.json
├── .env.example
├── src/
│   ├── Contracts/         Interfaces (TenantStorageInterface, etc.)
│   ├── Deployment/        DeploymentContext (mode switch)
│   ├── Tenant/            TenantResolver, TenantPathResolver, TenantContext
│   ├── Storage/           Drivers (SQLite + PostgreSQL stub)
│   ├── Export/            .giti Exporter, Importer, Validator
│   ├── Update/            Update channel client + server
│   ├── Database/         SchemaBuilder, MigrationRunner, Connection
│   ├── Localization/     Lang, PersianDigits, JalaliDate, Currency, Validator
│   └── Support/           Env loader
├── resources/lang/       fa.json + en.json
├── migrations/           Portable migrations (SQLite + PostgreSQL)
├── scripts/              migrate_tenant_to_pg.php, rollback_pg_to_sqlite.php
├── tests/                PHPUnit tests (8 test files)
└── docs/                 MIGRATION_PLAN, ANTIPATTERNS, LOCALIZATION_GUIDE
```

## Installation

### Requirements
- PHP 8.1+
- Extensions: pdo, json, zip, mbstring, openssl
- Optional: Redis for tenant resolution cache

### Steps

1. Copy files into your project root:
   ```bash
   cp -r gitiarts-phase2/* /var/www/gitiarts/
   ```

2. Install dependencies:
   ```bash
   cd /var/www/gitiarts
   composer install --no-dev --optimize-autoloader
   ```

3. Configure environment:
   ```bash
   cp .env.example .env
   # Edit .env with your values
   ```

4. Create storage directories:
   ```bash
   mkdir -p /storage/tenants /storage/exports /storage/cache /storage/logs
   chmod -R 775 /storage
   ```

5. Run migrations per tenant:
   ```php
   use GitiArts\Phase2\Database\MigrationRunner;
   use GitiArts\Phase2\Storage\TenantStorageManager;

   $runner = new MigrationRunner(
       TenantStorageManager::getDriver(),
       __DIR__ . '/migrations'
   );
   $runner->migrate($tenantId);
   ```

## Deployment Modes

### Platform Mode (Multi-Tenant SaaS)

```env
DEPLOYMENT_MODE=platform
TENANT_STORAGE_DRIVER=sqlite
TENANT_RESOLUTION_STRATEGY=subdomain
PLATFORM_BASE_DOMAIN=platform.ir
```

### Standalone Mode (Self-Hosted Café)

```env
DEPLOYMENT_MODE=standalone
TENANT_STORAGE_DRIVER=sqlite
UPDATE_SERVER_URL=https://api.yourplatform.ir/updates/check
UPDATE_LICENSE_KEY=YOUR_LICENSE_KEY
```

**CRITICAL:** Application code MUST NEVER read `DEPLOYMENT_MODE` directly.
Always go through `DeploymentContext`.

## Tests

```bash
composer test
# or
./vendor/bin/phpunit tests/
```

Tests include:
- Persian string multibyte safety
- Persian ↔ Latin digit conversion
- Gregorian ↔ Jalali date conversion (algorithm verified against time.ir)
- Iranian National ID checksum validation
- Iranian mobile number validation
- Hash-based tenant path resolution
- DeploymentContext feature gating

## Documentation

- `docs/MIGRATION_PLAN.md` — SQLite → PostgreSQL migration strategy
- `docs/TENANT_ANTIPATTERNS.md` — 15 multi-tenant antipatterns + Persian/RTL pitfalls
- `docs/LOCALIZATION_GUIDE.md` — How to add new Persian strings

## Persian-First Guarantee

This platform is built for the Iranian market. Every UI element is Persian.
Every date is Jalali. Every digit is Persian (۰-۹). Every currency is
Toman. English strings in UI = BUG.

## License

Proprietary. © 2026 GitiArts. All rights reserved.
