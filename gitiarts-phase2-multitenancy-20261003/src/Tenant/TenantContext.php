<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Tenant;

/**
 * TenantContext
 *
 * Tiny request-scoped holder of the resolved tenant ID. Once the
 * TenantResolver has identified the tenant for a request, the value is
 * stored here so any deeper code can access it without re-resolving.
 *
 * This is NOT a singleton in the GoF sense — it is a request-scoped
 * carrier. In a long-running process it is reset between requests.
 */
final class TenantContext
{
    private static ?string $currentTenantId = null;

    public static function setCurrent(?string $tenantId): void
    {
        self::$currentTenantId = $tenantId;
    }

    public static function getCurrent(): ?string
    {
        return self::$currentTenantId;
    }

    public static function requireCurrent(): string
    {
        $id = self::$currentTenantId;
        if ($id === null) {
            throw new \RuntimeException(
                'No active tenant context. Request must resolve a tenant before reaching this code path.'
            );
        }
        return $id;
    }

    public static function reset(): void
    {
        self::$currentTenantId = null;
    }
}
