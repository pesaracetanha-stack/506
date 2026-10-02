<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 *
 * Lightweight environment loader (no external dependency).
 * Replaces vlucas/phpdotenv to satisfy the zero-dependency constraint.
 */

namespace GitiArts\Phase2\Support;

/**
 * Env
 *
 * Loads a .env file into the process environment ($_ENV / $_SERVER / getenv)
 * and provides typed accessors. Designed to be ~80 lines, dependency-free,
 * and tolerant of missing files (production servers often inject env vars
 * directly via the process supervisor).
 */
final class Env
{
    private static bool $loaded = false;

    /**
     * Load a .env file into the process environment.
     *
     * @param string $path Absolute path to .env file.
     * @return void
     * @throws \RuntimeException If path exists but is unreadable.
     */
    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        if (!file_exists($path)) {
            // Production environments may inject env vars directly;
            // absence of a .env file is not a fatal error.
            self::$loaded = true;
            return;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException(sprintf('Cannot read .env file at %s', $path));
        }

        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            // Skip blank lines and comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // Skip lines without an = sign
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Strip surrounding quotes if present
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last  = $value[strlen($value) - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            // Don't overwrite existing env vars (process-supervisor wins)
            if (getenv($key) === false && !array_key_exists($key, $_ENV)) {
                $_ENV[$key]    = $value;
                $_SERVER[$key] = $value;
                putenv("{$key}={$value}");
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? null;
        }
        return $value === null ? $default : $value;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }
        return (int) $value;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        $value = strtolower(trim($value));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public static function getArray(string $key, string $separator = ','): array
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return [];
        }
        return array_map('trim', explode($separator, $value));
    }
}
