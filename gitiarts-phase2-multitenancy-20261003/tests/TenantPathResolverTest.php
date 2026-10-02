<?php

declare(strict_types=1);

namespace GitiArts\Phase2\Tests;

use GitiArts\Phase2\Tenant\TenantPathResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the hash-based tenant path resolver.
 *
 * Verifies the deterministic sharding scheme: same UUID → same path,
 * every directory contains at most 256 siblings.
 */
final class TenantPathResolverTest extends TestCase
{
    private const TEST_UUID = 'a1b2c3d4-e5f6-7890-abcd-ef0123456789';

    public function test_resolves_to_hash_based_subpath(): void
    {
        $resolver = new TenantPathResolver('/storage/tenants');
        $root = $resolver->getTenantRoot(self::TEST_UUID);

        self::assertSame(
            '/storage/tenants/a1/b2/c3/a1b2c3d4-e5f6-7890-abcd-ef0123456789',
            $root
        );
    }

    public function test_database_path_is_under_tenant_root(): void
    {
        $resolver = new TenantPathResolver('/storage/tenants');
        $dbPath = $resolver->getDatabasePath(self::TEST_UUID);

        self::assertSame(
            '/storage/tenants/a1/b2/c3/a1b2c3d4-e5f6-7890-abcd-ef0123456789/data.sqlite',
            $dbPath
        );
    }

    public function test_uploads_path_includes_category(): void
    {
        $resolver = new TenantPathResolver('/storage/tenants');
        $path = $resolver->getUploadCategoryPath(self::TEST_UUID, 'menu-items');

        self::assertStringEndsWith('/uploads/menu-items', $path);
    }

    public function test_same_uuid_always_resolves_to_same_path(): void
    {
        $resolver = new TenantPathResolver('/storage/tenants');
        $first  = $resolver->getTenantRoot(self::TEST_UUID);
        $second = $resolver->getTenantRoot(self::TEST_UUID);

        self::assertSame($first, $second);
    }

    public function test_different_uuids_resolve_to_different_paths(): void
    {
        $resolver = new TenantPathResolver('/storage/tenants');
        $a = $resolver->getTenantRoot('11111111-1111-1111-1111-111111111111');
        $b = $resolver->getTenantRoot('22222222-2222-2222-2222-222222222222');

        self::assertNotSame($a, $b);
    }

    public function test_rejects_invalid_uuid(): void
    {
        $resolver = new TenantPathResolver('/storage/tenants');
        $this->expectException(\InvalidArgumentException::class);
        $resolver->getTenantRoot('not-a-uuid');
    }

    public function test_sanitizes_upload_category(): void
    {
        $resolver = new TenantPathResolver('/storage/tenants');
        // Path traversal attempt — must be sanitized to empty → throw.
        $this->expectException(\InvalidArgumentException::class);
        $resolver->getUploadCategoryPath(self::TEST_UUID, '../../../etc');
    }
}
