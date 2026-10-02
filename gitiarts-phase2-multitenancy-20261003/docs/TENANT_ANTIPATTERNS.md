# Multi-Tenancy Antipatterns — GitiArts Phase 2

This document catalogues the mistakes that broke multi-tenant PHP projects
before this one. Every entry has three parts: **Problem**, **Why It Breaks**,
and **Correct Approach**. Code examples are illustrative, not production.

---

## 1. Cross-Tenant Data Leaks via Missing `tenant_id` Filter

**Problem:** A query like `SELECT * FROM orders WHERE status = 'pending'`
returns rows from ALL tenants in a shared-table topology.

**Why It Breaks:** Without `WHERE tenant_id = ?`, every other tenant's
pending orders are exposed. This is the #1 cause of multi-tenant data
breaches.

**Correct Approach:** Force every query through a central query builder
that injects `tenant_id = current_tenant` automatically. In Phase 2 we use
schema-per-tenant (PostgreSQL) and per-file (SQLite) isolation so the
`tenant_id` column is structurally unnecessary — but if you ever fall back
to a shared-schema topology, NEVER omit it.

---

## 2. Unindexed `tenant_id` Column

**Problem:** `tenant_id` is present in every row but not indexed.

**Why It Breaks:** Every tenant-scoped query becomes a full table scan.
At 100 tenants × 10k orders each, that's 1M rows scanned per request.

**Correct Approach:** Index `tenant_id` on every multi-tenant table.
Better: use schema-per-tenant (our approach) so the tenant filter is
structural, not index-based.

---

## 3. Shared Cache Without Tenant Namespace

**Problem:** `$cache->set('menu_items', $rows)` is called for every tenant.

**Why It Breaks:** Tenant A's menu is served to Tenant B on cache hit.
Extremely hard to detect in dev because dev usually has one tenant.

**Correct Approach:** Prefix every cache key with the tenant ID:
`tenant:{uuid}:menu_items`. In `Lang` class, locale strings are global
and shared; everything else MUST be namespaced.

---

## 4. File Upload Collisions

**Problem:** Two tenants upload `logo.png`. Second overwrites the first.

**Why It Breaks:** Filenames are not unique across tenants.

**Correct Approach:** TenantPathResolver places every tenant's uploads
under `/storage/tenants/a1/b2/c3/{uuid}/uploads/`. Filesystem isolation
is structural.

---

## 5. Shared Sessions Across Tenants

**Problem:** Session cookie `PHPSESSID` is shared; the same session ID
resolves to a different tenant's user when the user switches subdomains.

**Why It Breaks:** The session store has no tenant context. A user logged
into `miran.platform.ir` becomes "logged in" to `cafe2.platform.ir`.

**Correct Approach:** Include the tenant slug in the session key, OR
scope the cookie to the tenant's subdomain (`Domain=miran.platform.ir`).
In standalone mode this is moot (single tenant).

---

## 6. Global State for Current Tenant

**Problem:** `$GLOBALS['current_tenant'] = $tenant;` set during bootstrap.

**Why It Breaks:** In PHP-FPM with multiple concurrent requests sharing
worker state under rare configurations, the value can leak. Even in
proper FPM, it makes the codebase impossible to test in isolation.

**Correct Approach:** Use a request-scoped holder. Our `TenantContext`
class uses a static field that is reset at request start. Tests reset it
between cases.

---

## 7. Tenant Resolution on Every Query

**Problem:** `SELECT uuid FROM tenants WHERE slug = ?` runs on every
HTTP request.

**Why It Breaks:** At 200 requests/sec and 1000 tenants, that's 200
directory DB hits per second — wasted I/O.

**Correct Approach:** Cache the slug→uuid lookup in Redis (5 min TTL) or
file cache. Our `TenantResolver` does this. Negative lookups are also
cached to prevent DoS via invalid subdomain enumeration.

---

## 8. Hardcoded "Default" Tenant

**Problem:** Code like `if ($slug === '') { $slug = 'demo'; }`.

**Why It Breaks:** The "demo" tenant becomes a backdoor. Attackers probe
`demo.platform.ir` and get a real tenant context.

**Correct Approach:** No fallback. If resolution returns null, show the
platform landing page or a 404. Never silently substitute.

---

## 9. Migrations That Touch All Tenants Simultaneously

**Problem:** `ALTER TABLE orders ADD COLUMN notes TEXT` runs across 500
tenant schemas in a single transaction.

**Why It Breaks:** Long-held table locks; one slow tenant blocks the rest.

**Correct Approach:** Lazy migration: apply per-tenant on first access.
Track applied migrations in `migrations` table per tenant (our
`MigrationRunner` does this).

---

## 10. Cross-Tenant Joins (Forbidden)

**Problem:** `SELECT * FROM tenant_a.orders JOIN tenant_b.customers`.

**Why It Breaks:** Hard data leak. Should never be possible.

**Correct Approach:** In schema-per-tenant topology, this is
structurally impossible unless you explicitly `SET search_path` to
multiple schemas. Enforce `search_path = tenant_<uuid>, public` and
never grant `USAGE` on other tenants' schemas to the app role.

---

## 11. Storing Secrets in Tenant Databases

**Problem:** SMS API key written to `tenant_<uuid>.sms_settings`.

**Why It Breaks:** Tenant export (.giti archive) leaks the key to the
café owner. They can then impersonate the platform on the SMS gateway.

**Correct Approach:** Secrets that belong to the platform (e.g. the
master SMS key used in platform mode) live in the platform schema, NOT
in tenant schemas. Tenant-specific secrets (the café's own SMS key in
standalone mode) are explicitly allowed to be exported because they
belong to the café.

---

## 12. Unbounded Tenant Growth in a Single Folder

**Problem:** `/storage/tenants/{uuid}/data.sqlite` for 10,000 tenants.

**Why It Breaks:** ext4 and XFS both degrade with > 100k entries per
directory. `readdir()` slows; backups become painful.

**Correct Approach:** Hash-based sharded paths (our `TenantPathResolver`):
`/a1/b2/c3/{uuid}/data.sqlite` — max 256 entries per directory.

---

## 13. No Cleanup on Tenant Deletion

**Problem:** `DELETE FROM tenants WHERE uuid = ?` removes the directory
record but leaves the DB file, uploads, and cache entries orphaned.

**Why It Breaks:** Storage grows indefinitely; GDPR/right-to-be-forgotten
violations; old data resurrects if the slug is reused.

**Correct Approach:** `TenantStorageInterface::destroy()` cascades: drop
schema (PG) or remove directory (SQLite), invalidate caches, delete meta.
Run it inside a transaction with the directory DB.

---

## 14. Single Tenant's Slow Query Starves Others

**Problem:** One café runs a complex analytics query; all other cafés
on the same DB cluster stall.

**Why It Breaks:** Shared connection pool + no per-tenant query budget.

**Correct Approach:** In PostgreSQL, set `statement_timeout` per role
(e.g. 30s for app role, 5min for admin role). Use a separate read-only
replica for analytics. In SQLite (current), the WAL mode partially
mitigates this but not entirely — that's why we migrate at 500 tenants.

---

## 15. Assuming `localhost` in Standalone Mode

**Problem:** Standalone installation hardcodes `localhost` in config.

**Why It Breaks:** Café's own host has a different name (e.g.
`miran-cafe.ir`). Configuration fails to start.

**Correct Approach:** Every host-specific value is read from `.env` or
computed at request time from `$_SERVER['HTTP_HOST']`. No hardcoded
hosts anywhere in the codebase.

---

## Persian / RTL Specific Pitfalls

### P1. Storing Persian Text Without Proper Collation

**Problem:** `CREATE TABLE ... (name TEXT)` with default collation
(usually `C` or `POSIX` in PostgreSQL).

**Why It Breaks:** Persian alphabet sorting is wrong: `آ` comes before
`ا` but binary collation puts `آ` after `ا`. Search results feel "off" to
Persian users.

**Correct Approach:** Use `COLLATE fa_IR` (requires ICU-enabled PG build,
standard in PG 15+) OR perform final sorting in PHP using `Collator`
from the `intl` extension. In SQLite, store with `PRAGMA encoding='UTF-8'`
and sort in PHP.

### P2. Using `strlen()` on Persian Strings

**Problem:** `if (strlen($name) > 30) { ... }` for validation.

**Why It Breaks:** Persian characters are multibyte (2-3 bytes each in
UTF-8). `strlen()` returns byte count, not character count. A 10-char
Persian name might trigger the 30-byte limit.

**Correct Approach:** Always use `mb_strlen($str, 'UTF-8')`. The codebase
has a unit test (`LangTest::test_persian_strings_are_multibyte_safe`) that
catches regressions.

### P3. Sorting Persian Names Without Locale-Aware Collation

**Problem:** `ORDER BY name_fa ASC` in raw SQL.

**Why It Breaks:** Default sort orders by Unicode code point, which puts
`آ` (U+0622) AFTER `ا` (U+0627) — wrong for Persian.

**Correct Approach:** Use `Collator::sort()` from PHP `intl`, or sort
via SQL with `COLLATE fa_IR` if PG is built with ICU.

### P4. Mixing Gregorian and Jalali Dates in the Same Table

**Problem:** `created_at` stored as Jalali "1405/07/11" but
`updated_at` stored as Gregorian "2026-10-03".

**Why It Breaks:** Impossible to compare; date arithmetic breaks.

**Correct Approach:** Store ALL dates as Gregorian ISO 8601 in DB. Format
as Jalali ONLY at the presentation layer via `JalaliDate::format()`.
This is non-negotiable.

### P5. Using Latin Digits in URLs While Displaying Persian Digits

**Problem:** URL is `/orders/1234` but UI displays "سفارش ۱۲۳۴".

**Why It Breaks:** If the user copies the displayed number and searches,
nothing matches. Bookmarks become inconsistent.

**Correct Approach:** URLs and DB keys use Latin digits (HTTP standard).
UI displays use `PersianDigits::toPersian()`. When accepting user input
(e.g. "search order ۱۲۳۴"), normalize via `PersianDigits::toLatin()`
before lookup.

### P6. Forgetting `dir="rtl"` and `lang="fa"` on HTML Root

**Problem:** Persian text renders correctly because the browser detects
the script, but layout direction stays LTR.

**Why It Breaks:** Forms submit left-to-right; tab order is wrong; icons
that should be on the right are on the left.

**Correct Approach:** Every HTML response from this codebase MUST begin
with `<html dir="rtl" lang="fa">`. Never rely on browser auto-detection.

### P7. Email/SMS Templates With Latin-Only Body

**Problem:** OTP SMS sent in English: "Your code is 12345".

**Why It Breaks:** Iranian users expect Persian. Some carriers truncate
long English SMS; some users can't read English.

**Correct Approach:** All SMS templates come from `resources/lang/fa.json`
under the `sms.*` namespace. Same for email templates (`email.*`).

### P8. Number Formatting With `number_format()` on Persian Strings

**Problem:** `number_format($row['price'])` produces "1,234,567" (Latin).

**Why It Breaks:** Mixed script looks unprofessional. Users have to
mentally translate.

**Correct Approach:** Always go through `PersianDigits::formatNumber()`
or `Currency::format()` for monetary values.
