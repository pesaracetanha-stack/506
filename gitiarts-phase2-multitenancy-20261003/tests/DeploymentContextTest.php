<?php

declare(strict_types=1);

namespace GitiArts\Phase2\Tests;

use GitiArts\Phase2\Deployment\DeploymentContext;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the DeploymentContext class.
 *
 * We can't easily flip the .env in tests, so we instantiate the class
 * with env injection by setting environment variables before construction.
 */
final class DeploymentContextTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset env so other tests aren't affected
        putenv('DEPLOYMENT_MODE');
        unset($_ENV['DEPLOYMENT_MODE'], $_SERVER['DEPLOYMENT_MODE']);
    }

    public function test_platform_mode_enables_platform_features(): void
    {
        putenv('DEPLOYMENT_MODE=platform');
        $ctx = new DeploymentContext();

        self::assertTrue($ctx->isPlatform());
        self::assertFalse($ctx->isStandalone());
        self::assertSame('platform', $ctx->getMode());

        self::assertTrue($ctx->isFeatureEnabled('registration.signup'));
        self::assertTrue($ctx->isFeatureEnabled('platform.admin_panel'));
        self::assertTrue($ctx->isFeatureEnabled('billing.subscription'));
        self::assertFalse($ctx->isFeatureEnabled('update.checker'));
    }

    public function test_standalone_mode_disables_platform_features(): void
    {
        putenv('DEPLOYMENT_MODE=standalone');
        $ctx = new DeploymentContext();

        self::assertFalse($ctx->isPlatform());
        self::assertTrue($ctx->isStandalone());
        self::assertSame('standalone', $ctx->getMode());

        self::assertFalse($ctx->isFeatureEnabled('registration.signup'));
        self::assertFalse($ctx->isFeatureEnabled('platform.admin_panel'));
        self::assertFalse($ctx->isFeatureEnabled('billing.subscription'));
        self::assertTrue($ctx->isFeatureEnabled('update.checker'));
    }

    public function test_core_features_always_enabled_in_both_modes(): void
    {
        putenv('DEPLOYMENT_MODE=platform');
        $ctx = new DeploymentContext();
        foreach (['menu.management', 'order.management', 'employee.management', 'sms.otp'] as $f) {
            self::assertTrue($ctx->isFeatureEnabled($f), "Platform mode should enable {$f}");
        }

        putenv('DEPLOYMENT_MODE=standalone');
        $ctx2 = new DeploymentContext();
        foreach (['menu.management', 'order.management', 'employee.management', 'sms.otp'] as $f) {
            self::assertTrue($ctx2->isFeatureEnabled($f), "Standalone mode should enable {$f}");
        }
    }

    public function test_unknown_feature_returns_false(): void
    {
        putenv('DEPLOYMENT_MODE=platform');
        $ctx = new DeploymentContext();
        self::assertFalse($ctx->isFeatureEnabled('nonexistent.feature'));
    }

    public function test_invalid_mode_throws(): void
    {
        putenv('DEPLOYMENT_MODE=invalid_mode_xyz');
        $this->expectException(\InvalidArgumentException::class);
        new DeploymentContext();
    }

    public function test_tenant_id_settable_only_in_platform_mode(): void
    {
        putenv('DEPLOYMENT_MODE=platform');
        $ctx = new DeploymentContext();
        $ctx->setTenantId('some-uuid');
        self::assertSame('some-uuid', $ctx->getTenantId());

        putenv('DEPLOYMENT_MODE=standalone');
        $ctx2 = new DeploymentContext();
        $ctx2->setTenantId('should-be-ignored');
        self::assertNull($ctx2->getTenantId());
    }
}
