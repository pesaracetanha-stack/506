<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Deployment;

use GitiArts\Phase2\Contracts\FeatureFlagInterface;
use GitiArts\Phase2\Support\Env;

/**
 * DeploymentContext
 *
 * The ONLY class in the entire codebase that knows whether we are running
 * in platform (multi-tenant SaaS) or standalone (single café self-hosted)
 * mode. Application code MUST call methods on this class instead of reading
 * the DEPLOYMENT_MODE env var directly.
 *
 * Persian/RTL note: this class itself contains no user-facing strings.
 * Mode names ("platform" / "standalone") are technical identifiers,
 * not UI labels.
 */
final class DeploymentContext implements FeatureFlagInterface
{
    public const MODE_PLATFORM   = 'platform';
    public const MODE_STANDALONE = 'standalone';

    /** @var array<string,bool> Feature map for platform mode. */
    private const PLATFORM_FEATURES = [
        // Tenant lifecycle
        'registration.signup'              => true,
        'platform.admin_panel'             => true,
        'billing.subscription'             => true,
        'billing.invoice_generation'       => true,
        'tenant.export_to_giti'            => true,
        'tenant.import_from_giti'          => true,
        'tenant.update_channel_server'     => true,
        'platform.tenant_management'       => true,

        // Core café features (always on)
        'menu.management'                  => true,
        'order.management'                 => true,
        'employee.management'             => true,
        'receipt.printing'                => true,
        'customer.loyalty'                => true,

        // Infrastructure
        'sms.otp'                         => true, // uses platform SMS keys
        'update.checker'                  => false, // N/A in platform mode
        'update.poll_remote'              => false,
    ];

    /** @var array<string,bool> Feature map for standalone mode. */
    private const STANDALONE_FEATURES = [
        // Tenant lifecycle — disabled in standalone
        'registration.signup'              => false,
        'platform.admin_panel'            => false,
        'billing.subscription'            => false,
        'billing.invoice_generation'      => false,
        'tenant.export_to_giti'           => true, // still allowed (backup)
        'tenant.import_from_giti'         => true,
        'tenant.update_channel_server'    => false,
        'platform.tenant_management'      => false,

        // Core café features (always on)
        'menu.management'                 => true,
        'order.management'                => true,
        'employee.management'             => true,
        'receipt.printing'                => true,
        'customer.loyalty'                => true,

        // Infrastructure
        'sms.otp'                        => true, // uses café's own SMS keys
        'update.checker'                 => true, // polls our server
        'update.poll_remote'             => true,
    ];

    private string $mode;
    private ?string $tenantId;

    public function __construct()
    {
        $raw    = Env::get('DEPLOYMENT_MODE', self::MODE_PLATFORM) ?? self::MODE_PLATFORM;
        $this->mode = strtolower(trim($raw));

        if (!in_array($this->mode, [self::MODE_PLATFORM, self::MODE_STANDALONE], true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid DEPLOYMENT_MODE "%s". Must be "platform" or "standalone".', $raw)
            );
        }

        // In standalone mode the tenant_id is NULL by definition — there is
        // only one café. In platform mode it is resolved per-request.
        $this->tenantId = $this->mode === self::MODE_STANDALONE ? null : null;
    }

    public function isPlatform(): bool
    {
        return $this->mode === self::MODE_PLATFORM;
    }

    public function isStandalone(): bool
    {
        return $this->mode === self::MODE_STANDALONE;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Set the resolved tenant ID for the current request.
     * Only meaningful in platform mode. Standalone mode ignores it.
     */
    public function setTenantId(?string $tenantId): void
    {
        if ($this->mode === self::MODE_PLATFORM) {
            $this->tenantId = $tenantId;
        }
    }

    public function getTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function isFeatureEnabled(string $feature): bool
    {
        $map = $this->mode === self::MODE_PLATFORM
            ? self::PLATFORM_FEATURES
            : self::STANDALONE_FEATURES;

        // Unknown features default to OFF (fail-closed).
        return $map[$feature] ?? false;
    }

    /**
     * Return the full feature map for the active mode.
     * Useful for debugging / admin display.
     *
     * @return array<string,bool>
     */
    public function getFeatureMap(): array
    {
        return $this->mode === self::MODE_PLATFORM
            ? self::PLATFORM_FEATURES
            : self::STANDALONE_FEATURES;
    }
}
