<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Contracts;

/**
 * TenantStorageInterface
 *
 * Abstraction over per-tenant database access, file storage, and metadata.
 * Application code interacts ONLY with this interface — it never knows
 * whether the backing store is SQLite, PostgreSQL, or any future driver.
 *
 * Drivers MUST be stateless across tenants: every method takes a tenant_id
 * and resolves the physical storage internally. This is what allows
 * SQLite→PostgreSQL migration without touching application code.
 */
interface TenantStorageInterface
{
    /**
     * Open a PDO connection scoped to the given tenant.
     *
     * For SQLite: opens the tenant's data.sqlite file.
     * For PostgreSQL: opens the shared cluster connection with
     *                 search_path set to the tenant's dedicated schema.
     *
     * @param string $tenantId UUID of the tenant.
     * @return \PDO
     * @throws \RuntimeException If the tenant storage cannot be opened.
     */
    public function getConnection(string $tenantId): \PDO;

    /**
     * Store an uploaded file under the tenant's storage namespace.
     *
     * @param string $tenantId     Tenant UUID.
     * @param string $category     One of: logo, menu-items, receipts, employees.
     * @param string $filename     Target filename (without path).
     * @param string $sourcePath   Local file to read from.
     * @return string Relative URI of the stored file (e.g. "a1/b2/c3/{uuid}/uploads/menu-items/abc.jpg").
     */
    public function putFile(string $tenantId, string $category, string $filename, string $sourcePath): string;

    /**
     * Resolve the absolute filesystem path to a stored file.
     *
     * @param string $tenantId Tenant UUID.
     * @param string $category Upload category.
     * @param string $filename Filename.
     * @return string Absolute path.
     */
    public function getFilePath(string $tenantId, string $category, string $filename): string;

    /**
     * Return an absolute path to the tenant's data directory root.
     *
     * Used by the exporter to bundle uploads. Must be filesystem-agnostic
     * enough that the exporter can stream its contents into a ZIP.
     *
     * @param string $tenantId
     * @return string
     */
    public function getTenantRoot(string $tenantId): string;

    /**
     * Read a metadata key for a tenant (plan, created_at, owner_mobile, etc.).
     *
     * @param string $tenantId
     * @param string $key
     * @return mixed|null
     */
    public function getMeta(string $tenantId, string $key): mixed;

    /**
     * Write a metadata key for a tenant.
     *
     * @param string $tenantId
     * @param string $key
     * @param mixed  $value
     * @return void
     */
    public function setMeta(string $tenantId, string $key, mixed $value): void;

    /**
     * Return all metadata for a tenant as an associative array.
     *
     * @param string $tenantId
     * @return array<string,mixed>
     */
    public function getAllMeta(string $tenantId): array;

    /**
     * Provision a fresh tenant storage (create DB file / schema).
     *
     * @param string $tenantId
     * @return void
     */
    public function provision(string $tenantId): void;

    /**
     * Destroy a tenant's storage entirely (DB + uploads + metadata).
     * Used only by the platform admin "delete tenant" flow.
     *
     * @param string $tenantId
     * @return void
     */
    public function destroy(string $tenantId): void;

    /**
     * Return driver identifier: "sqlite" or "postgres".
     *
     * @return string
     */
    public function getDriverName(): string;
}
