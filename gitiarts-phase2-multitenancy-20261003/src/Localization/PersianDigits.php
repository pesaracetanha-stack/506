<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Localization;

/**
 * PersianDigits
 *
 * Conversion and formatting helpers for Persian digits (۰-۹).
 *
 * Persian uses Eastern Arabic-Indic digits:
 *   0→۰  1→۱  2→۲  3→۳  4→۴  5→۵  6→۶  7→۷  8→۸  9→۹
 *
 * All numeric UI output MUST pass through this class. Never use
 * raw int casting or number_format() on user-facing strings —
 * they produce Latin digits which are a BUG in this codebase.
 *
 * Persian uses the same thousands separator as English (comma)
 * but with Persian digits: 1,234,567 → ۱,۲۳۴,۵۶۷
 */
final class PersianDigits
{
    private const LATIN_TO_PERSIAN = [
        '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
        '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
    ];

    private const PERSIAN_TO_LATIN = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        // Arabic-Indic digits often leak into input — convert them too
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    /**
     * Convert every Latin digit in a string to its Persian equivalent.
     * Non-digit characters are preserved as-is.
     *
     * @param string|int|float $input
     */
    public static function toPersian(string|int|float $input): string
    {
        return strtr((string) $input, self::LATIN_TO_PERSIAN);
    }

    /**
     * Convert every Persian (and Arabic-Indic) digit to Latin.
     * Use this before storing user input in the database or doing math.
     *
     * @param string $input
     */
    public static function toLatin(string $input): string
    {
        return strtr($input, self::PERSIAN_TO_LATIN);
    }

    /**
     * Format an integer with thousands separators using Persian digits.
     *
     * @param int|float $number
     * @return string E.g. formatNumber(1234567) → "۱,۲۳۴,۵۶۷"
     */
    public static function formatNumber(int|float $number): string
    {
        // number_format with 0 decimals produces "1,234,567"
        $latin = number_format((float) $number, 0, '.', ',');
        return self::toPersian($latin);
    }

    /**
     * Format a decimal number with a fixed number of decimal places.
     *
     * @param float  $number
     * @param int    $decimals
     * @return string E.g. formatDecimal(1234.5, 2) → "۱,۲۳۴.۵۰"
     */
    public static function formatDecimal(float $number, int $decimals = 2): string
    {
        $latin = number_format($number, $decimals, '.', ',');
        return self::toPersian($latin);
    }

    /**
     * Normalize a user-entered number string for storage.
     * Strips Persian/Arabic digits and returns a clean Latin numeric string.
     */
    public static function normalize(string $input): string
    {
        return self::toLatin($input);
    }
}
