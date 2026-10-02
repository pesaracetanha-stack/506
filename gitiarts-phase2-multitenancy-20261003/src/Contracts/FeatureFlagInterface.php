<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Contracts;

/**
 * FeatureFlagInterface
 *
 * Abstraction for feature gating. Concrete implementation is
 * DeploymentContext, but the interface allows alternative backends
 * (per-tenant overrides, A/B tests, etc.) without breaking callers.
 */
interface FeatureFlagInterface
{
    /**
     * Returns true if a named feature is enabled in the current context.
     *
     * @param string $feature Feature key (e.g. "billing.subscription").
     */
    public function isFeatureEnabled(string $feature): bool;

    /**
     * Returns the active deployment mode.
     *
     * @return string "platform" | "standalone"
     */
    public function getMode(): string;
}
