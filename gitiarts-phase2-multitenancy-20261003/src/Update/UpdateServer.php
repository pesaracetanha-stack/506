<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Update;

/**
 * UpdateServer
 *
 * Server-side class used by the central update endpoint
 * (https://api.yourplatform.ir/updates/check). It serves versioned
 * .giti-update packages containing only the files that changed between
 * the requesting installation's version and the latest version, plus
 * the necessary DB migration scripts.
 *
 * A .giti-update package is structurally identical to a .giti archive,
 * except:
 *   - database/tenant.sqlite is replaced by database/migrations/{from}_to_{to}.sql
 *   - uploads/ may contain only new/changed assets
 *   - manifest.json contains additional fields: delta_from, delta_to
 *
 * Persian/RTL note: the changelog content is fetched from a version-keyed
 * Persian changelog file — no English text is returned to the client.
 */
final class UpdateServer
{
    /** @var array<string,string> Maps version string → changelog (Persian). */
    private array $changelogRegistry = [];

    /** @var array<string,string> Maps version string → package download URL. */
    private array $packageRegistry = [];

    /** @var array<string,string> Maps version string → SHA-256 of package. */
    private array $checksumRegistry = [];

    /** @var string|null Maps version string → base64-encoded Ed25519 signature. */
    private ?string $signingPrivateKey = null;

    public function __construct(
        private readonly string $packagesRoot
    ) {
        $this->loadRegistry();
    }

    /**
     * Handle an incoming update-check request.
     *
     * @param array<string,mixed> $request Parsed JSON body.
     * @return array<string,mixed>
     */
    public function handleCheckRequest(array $request): array
    {
        $currentVersion = (string) ($request['current_version'] ?? '');
        $currentSchema  = (string) ($request['schema_version'] ?? '');
        $licenseKey     = (string) ($request['license_key'] ?? '');

        if (!$this->validateLicense($licenseKey)) {
            return [
                'update_available' => false,
                'error'             => 'license_invalid',
                'error_message_fa'  => 'کلید لایسنس نامعتبر است یا منقضی شده.',
            ];
        }

        $latestVersion = $this->getLatestVersion();
        $latestSchema  = $this->getLatestSchema();

        if ($this->versionLte($latestVersion, $currentVersion)) {
            return [
                'update_available' => false,
                'latest_version'    => $latestVersion,
                'schema_version'    => $latestSchema,
                'changelog'         => '',
                'download_url'      => null,
                'checksum'          => null,
            ];
        }

        // Compute delta changelog: concatenate Persian changelogs for all
        // versions strictly newer than currentVersion.
        $changelog = $this->buildDeltaChangelog($currentVersion);

        return [
            'update_available' => true,
            'latest_version'    => $latestVersion,
            'schema_version'    => $latestSchema,
            'changelog'         => $changelog,
            'download_url'      => $this->packageRegistry[$latestVersion] ?? null,
            'checksum'          => $this->checksumRegistry[$latestVersion] ?? null,
        ];
    }

    /**
     * Sign a package binary using Ed25519 and return base64-encoded signature.
     * Used by the release tooling when publishing a new package.
     */
    public function signPackage(string $packagePath): string
    {
        if ($this->signingPrivateKey === null) {
            throw new \RuntimeException('No signing key configured');
        }
        $data = file_get_contents($packagePath);
        if ($data === false) {
            throw new \RuntimeException('Cannot read package for signing');
        }
        $signature = '';
        if (!openssl_sign($data, $signature, $this->signingPrivateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Signing failed: ' . openssl_error_string());
        }
        return base64_encode($signature);
    }

    private function loadRegistry(): void
    {
        $registryFile = rtrim($this->packagesRoot, '/') . '/registry.json';
        if (!is_file($registryFile)) {
            return;
        }
        $raw = file_get_contents($registryFile);
        if ($raw === false) {
            return;
        }
        $registry = json_decode($raw, true);
        if (!is_array($registry)) {
            return;
        }

        foreach ($registry as $version => $entry) {
            $this->changelogRegistry[$version] = $entry['changelog_fa'] ?? '';
            $this->packageRegistry[$version]   = $entry['download_url'] ?? '';
            $this->checksumRegistry[$version]  = $entry['checksum'] ?? '';
        }
    }

    private function getLatestVersion(): string
    {
        if (empty($this->packageRegistry)) {
            return '5.0.5-test.5';
        }
        $versions = array_keys($this->packageRegistry);
        usort($versions, fn($a, $b) => $this->versionCompare($a, $b));
        return end($versions) ?: '5.0.5-test.5';
    }

    private function getLatestSchema(): string
    {
        return '2026_10_03_000002';
    }

    /**
     * Concatenate all Persian changelog entries strictly newer than
     * $currentVersion, sorted ascending so the user reads changes oldest→newest.
     */
    private function buildDeltaChangelog(string $currentVersion): string
    {
        $newer = array_filter(
            array_keys($this->changelogRegistry),
            fn(string $v) => $this->versionCompare($v, $currentVersion) > 0
        );
        usort($newer, fn($a, $b) => $this->versionCompare($a, $b));

        $parts = [];
        foreach ($newer as $v) {
            $parts[] = "نسخه {$v}:\n" . $this->changelogRegistry[$v];
        }
        return implode("\n\n", $parts);
    }

    private function versionCompare(string $a, string $b): int
    {
        return version_compare($a, $b);
    }

    private function versionLte(string $a, string $b): bool
    {
        return $this->versionCompare($a, $b) <= 0;
    }

    private function validateLicense(string $key): bool
    {
        // Empty license accepted only for the free community update channel.
        // Paid standalone license validation is delegated to the platform's
        // billing service — see platform/licenses/verify_license.php.
        if ($key === '') {
            return true; // free community channel
        }
        // Stub: real validation hits platform.tenant_licenses table.
        return strlen($key) >= 16;
    }
}
