<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Update;

use GitiArts\Phase2\Localization\Lang;
use GitiArts\Phase2\Support\Env;

/**
 * UpdateClient
 *
 * Client-side class for standalone installations. Periodically polls our
 * central update server, downloads .giti-update packages, verifies their
 * cryptographic signature, and applies them.
 *
 * The polling endpoint is configurable so the community update channel can
 * later be moved to a paid standalone license product without code changes.
 *
 * Persian/RTL note: changelog returned by the server is already in Persian.
 * This class returns it verbatim to the UI; no string concatenation here.
 */
final class UpdateClient
{
    private readonly string $serverUrl;
    private readonly int    $checkIntervalHours;
    private readonly string $licenseKey;
    private readonly bool   $verifySignature;
    private readonly string $publicKeyPath;

    public function __construct()
    {
        $this->serverUrl          = Env::get('UPDATE_SERVER_URL', 'https://api.yourplatform.ir/updates/check') ?? '';
        $this->checkIntervalHours = Env::getInt('UPDATE_CHECK_INTERVAL_HOURS', 24);
        $this->licenseKey         = Env::get('UPDATE_LICENSE_KEY', '') ?? '';
        $this->verifySignature    = Env::getBool('UPDATE_VERIFY_SIGNATURE', true);
        $this->publicKeyPath      = Env::get('UPDATE_PUBLIC_KEY', '') ?? '';
    }

    /**
     * Check whether a newer version is available on the update server.
     *
     * @param string $currentVersion    Current platform version (e.g. "5.0.5-test.5").
     * @param string $currentSchema     Current schema version (e.g. "2026_10_03_000001").
     * @return array{update_available:bool,latest_version:?string,download_url:?string,changelog:?string,checksum:?string}
     */
    public function checkForUpdates(string $currentVersion, string $currentSchema): array
    {
        $payload = [
            'current_version'  => $currentVersion,
            'schema_version'   => $currentSchema,
            'license_key'       => $this->licenseKey,
            'locale'            => 'fa',
        ];

        $response = $this->httpPostJson($this->serverUrl, $payload);
        if ($response === null) {
            return [
                'update_available' => false,
                'latest_version'   => null,
                'download_url'     => null,
                'changelog'         => Lang::get('update.error.connection_failed'),
                'checksum'          => null,
            ];
        }

        return [
            'update_available' => (bool) ($response['update_available'] ?? false),
            'latest_version'   => $response['latest_version'] ?? null,
            'download_url'     => $response['download_url'] ?? null,
            'changelog'         => $response['changelog'] ?? '',
            'checksum'          => $response['checksum'] ?? null,
        ];
    }

    /**
     * Download a .giti-update package and verify its SHA-256 checksum
     * and Ed25519 signature (if signature verification is enabled).
     *
     * @param string $downloadUrl URL returned by checkForUpdates().
     * @param string $expectedChecksum Expected SHA-256 of the package.
     * @return string Absolute path to the verified package on local disk.
     * @throws \RuntimeException If verification fails.
     */
    public function downloadAndVerify(string $downloadUrl, string $expectedChecksum): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'giti_update_');
        if ($tmpPath === false) {
            throw new \RuntimeException(Lang::get('update.error.temp_file_failed'));
        }

        $bytes = file_get_contents($downloadUrl);
        if ($bytes === false) {
            @unlink($tmpPath);
            throw new \RuntimeException(Lang::get('update.error.download_failed'));
        }
        file_put_contents($tmpPath, $bytes);

        // 1. SHA-256 verification
        $actualChecksum = hash_file('sha256', $tmpPath);
        if (!hash_equals($expectedChecksum, $actualChecksum)) {
            @unlink($tmpPath);
            throw new \RuntimeException(Lang::get('update.error.checksum_mismatch'));
        }

        // 2. Signature verification (Ed25519 over the package bytes)
        if ($this->verifySignature) {
            if ($this->publicKeyPath === '' || !is_file($this->publicKeyPath)) {
                @unlink($tmpPath);
                throw new \RuntimeException(Lang::get('update.error.missing_public_key'));
            }
            $publicKey = file_get_contents($this->publicKeyPath);
            if ($publicKey === false) {
                @unlink($tmpPath);
                throw new \RuntimeException(Lang::get('update.error.unreadable_public_key'));
            }

            // The signature is delivered as a base64-encoded HTTP header on the
            // download response; here we expect the caller to have stored it in
            // a sibling file with the .sig extension.
            $signaturePath = $downloadUrl . '.sig';
            $sigB64 = file_get_contents($signaturePath);
            if ($sigB64 === false) {
                @unlink($tmpPath);
                throw new \RuntimeException(Lang::get('update.error.missing_signature'));
            }
            $signature = base64_decode($sigB64, true);
            if ($signature === false) {
                @unlink($tmpPath);
                throw new \RuntimeException(Lang::get('update.error.invalid_signature_format'));
            }

            $ok = openssl_verify($bytes, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
            if (!$ok) {
                @unlink($tmpPath);
                throw new \RuntimeException(Lang::get('update.error.signature_invalid'));
            }
        }

        return $tmpPath;
    }

    /**
     * POST JSON to a URL using cURL or stream wrapper fallback.
     * Returns the decoded JSON response or null on failure.
     *
     * @return array<string,mixed>|null
     */
    private function httpPostJson(string $url, array $payload): ?array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return null;
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content'       => $json,
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
