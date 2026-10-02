<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Localization;

/**
 * Currency
 *
 * Persian currency (Toman) formatter. The Toman (تومان) is the de-facto
 * currency used in Iranian daily commerce. 1 Toman = 10 Rials; prices in
 * GitiArts are stored as integer Tomans.
 *
 * Output requirements:
 *   - All digits Persian
 *   - Thousand separator: comma
 *   - Currency suffix: "تومان"
 *   - Short form for large amounts: "۱.۵ میلیون تومان"
 */
final class Currency
{
    /**
     * Format an amount in Toman with Persian digits and thousands separator.
     *
     * @param int $amountToman Amount in Toman (integer).
     * @return string E.g. format(150000) → "۱۵۰,۰۰۰ تومان"
     */
    public static function format(int $amountToman): string
    {
        $formatted = PersianDigits::formatNumber($amountToman);
        return $formatted . ' تومان';
    }

    /**
     * Format with Persian digits and no currency suffix.
     * Used in tables where the currency is implied by the column header.
     */
    public static function formatBare(int $amountToman): string
    {
        return PersianDigits::formatNumber($amountToman);
    }

    /**
     * Short human-readable form for very large amounts.
     *
     * @param int $amountToman
     * @return string E.g. formatShort(1500000) → "۱.۵ میلیون تومان"
     */
    public static function formatShort(int $amountToman): string
    {
        $abs = abs($amountToman);
        $sign = $amountToman < 0 ? '−' : '';

        if ($abs >= 1_000_000_000) {
            $value = $abs / 1_000_000_000;
            $fa = PersianDigits::toPersian(rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'));
            return $sign . $fa . ' میلیارد تومان';
        }
        if ($abs >= 1_000_000) {
            $value = $abs / 1_000_000;
            $fa = PersianDigits::toPersian(rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'));
            return $sign . $fa . ' میلیون تومان';
        }
        if ($abs >= 1_000) {
            $value = $abs / 1_000;
            $fa = PersianDigits::toPersian(rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'));
            return $sign . $fa . ' هزار تومان';
        }

        return self::format($amountToman);
    }

    /**
     * Parse a user-entered amount string into an integer Toman value.
     * Strips Persian/Arabic digits, thousand separators, and currency suffix.
     *
     * @return int|null Returns null if parsing fails.
     */
    public static function parse(string $input): ?int
    {
        $normalized = PersianDigits::normalize($input);
        // Remove "تومان", "Toman", "TMN", commas, spaces
        $normalized = preg_replace('/[^\d\-]/', '', $normalized) ?? '';
        if ($normalized === '' || $normalized === '-') {
            return null;
        }
        return (int) $normalized;
    }
}
