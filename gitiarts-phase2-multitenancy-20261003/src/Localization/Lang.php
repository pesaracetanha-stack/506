<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Localization;

use GitiArts\Phase2\Support\Env;

/**
 * Lang
 *
 * Translation registry. Loads strings from resources/lang/{locale}.json
 * (the single source of truth) and resolves dotted keys like
 * "menu.item.add" → "افزودن آیتم منو".
 *
 * Design contract:
 *   - If a key is missing, returns the key itself. NEVER crashes.
 *   - Supports parameter interpolation: {name} placeholders.
 *   - Falls back to APP_FALLBACK_LOCALE if a key is missing in the active
 *     locale. For Iranian market this is intentionally Persian-only;
 *     English is provided as a sibling JSON file but Persian is the
 *     reference language.
 *   - Loaded once per process and cached in memory. Tests can call
 *     Lang::reload() to pick up JSON changes mid-process.
 */
final class Lang
{
    private static string $locale;
    private static string $fallback;
    private static array $cache = [];

    public static function init(): void
    {
        self::$locale   = Env::get('APP_LOCALE', 'fa') ?? 'fa';
        self::$fallback = Env::get('APP_FALLBACK_LOCALE', 'en') ?? 'en';
        self::load(self::$locale);
        self::load(self::$fallback);
    }

    public static function setLocale(string $locale): void
    {
        self::$locale = $locale;
        self::load($locale);
    }

    public static function getLocale(): string
    {
        return self::$locale ?? 'fa';
    }

    /**
     * Resolve a translation key.
     *
     * @param string $key      Dotted key, e.g. "menu.item.add".
     * @param array<string,string> $params  Interpolation values.
     * @return string Translated string. If the key is missing, the key
     *               itself is returned (never throws).
     */
    public static function get(string $key, array $params = []): string
    {
        if (!isset(self::$locale)) {
            self::init();
        }

        $value = self::$cache[self::$locale][$key]
            ?? self::$cache[self::$fallback][$key]
            ?? $key;

        if ($params === []) {
            return $value;
        }

        // Replace {name} placeholders
        foreach ($params as $k => $v) {
            $value = str_replace('{' . $k . '}', (string) $v, $value);
        }
        return $value;
    }

    /**
     * Return all loaded strings for a locale (mainly used by tests).
     *
     * @return array<string,string>
     */
    public static function all(string $locale = 'fa'): array
    {
        return self::$cache[$locale] ?? [];
    }

    public static function reload(): void
    {
        self::$cache = [];
        self::init();
    }

    private static function load(string $locale): void
    {
        if (isset(self::$cache[$locale])) {
            return;
        }

        $candidates = [
            __DIR__ . '/../../resources/lang/' . $locale . '.json',
            '/home/z/my-project/gitiarts-phase2/resources/lang/' . $locale . '.json',
        ];

        $path = null;
        foreach ($candidates as $c) {
            if (is_file($c)) {
                $path = $c;
                break;
            }
        }

        if ($path === null) {
            self::$cache[$locale] = [];
            return;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            self::$cache[$locale] = [];
            return;
        }

        $decoded = json_decode($raw, true);
        self::$cache[$locale] = is_array($decoded) ? $decoded : [];
    }
}
