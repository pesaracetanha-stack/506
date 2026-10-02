<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Localization;

/**
 * Validator
 *
 * Iranian-specific input validators. Every validation failure returns
 * a Persian error message (sourced from fa.json) — never an English one.
 *
 * Covers:
 *   - Iranian mobile numbers (09XXXXXXXXX, +989XXXXXXXXX, 00989XXXXXXXXX)
 *   - Iranian National ID (کد ملی) — with full checksum validation
 *   - Iranian postal code (کد پستی) — 10 digits, no all-same digit
 *   - Iranian bank card number (16 digits, optional Luhn check)
 */
final class Validator
{
    /**
     * Validate Iranian mobile phone number.
     * Accepts: 09123456789, +989123456789, 00989123456789, 9123456789
     *
     * @return bool
     */
    public static function isIranianMobile(string $input): bool
    {
        $digits = PersianDigits::toLatin($input);
        $digits = preg_replace('/[^\d]/', '', $digits) ?? '';

        // Strip leading 0098 or +98 prefix
        if (str_starts_with($digits, '0098')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '98')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        // Iranian mobiles start with 9 and have 10 digits total
        return preg_match('/^9\d{9}$/', $digits) === 1;
    }

    /**
     * Normalize an Iranian mobile number to canonical 09XXXXXXXXX form.
     *
     * @return string|null Returns null if input is not a valid mobile.
     */
    public static function normalizeMobile(string $input): ?string
    {
        if (!self::isIranianMobile($input)) {
            return null;
        }
        $digits = PersianDigits::toLatin($input);
        $digits = preg_replace('/[^\d]/', '', $digits) ?? '';
        if (str_starts_with($digits, '0098')) {
            $digits = substr($digits, 4);
        } elseif (str_starts_with($digits, '98')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        return '0' . $digits;
    }

    /**
     * Validate Iranian National ID (کد ملی) with full checksum algorithm.
     *
     * Algorithm:
     *   - 10 digits exactly.
     *   - First 9 digits × weighted positions (10..2) summed.
     *   - remainder = sum mod 11
     *   - control = (10th digit)
     *   - if remainder < 2: control must equal remainder
     *   - if remainder >= 2: control must equal (11 - remainder)
     */
    public static function isNationalId(string $input): bool
    {
        $digits = PersianDigits::toLatin($input);
        $digits = preg_replace('/[^\d]/', '', $digits) ?? '';

        if (strlen($digits) !== 10) {
            return false;
        }

        // Reject all-same-digit strings (0000000000, 1111111111, ...)
        if (preg_match('/^(\d)\1{9}$/', $digits)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $digits[$i] * (10 - $i);
        }
        $remainder = $sum % 11;
        $control   = (int) $digits[9];

        if ($remainder < 2) {
            return $control === $remainder;
        }
        return $control === (11 - $remainder);
    }

    /**
     * Validate Iranian postal code (کد پستی).
     * Rules: 10 digits, not all-same, first digit cannot be 0 in standard
     * format, but the platform accepts any 10-digit string with valid
     * Iranian postcode pattern.
     */
    public static function isPostalCode(string $input): bool
    {
        $digits = PersianDigits::toLatin($input);
        $digits = preg_replace('/[^\d]/', '', $digits) ?? '';

        if (strlen($digits) !== 10) {
            return false;
        }
        // Reject all-same-digit codes
        if (preg_match('/^(\d)\1{9}$/', $digits)) {
            return false;
        }
        // Iranian postal codes start with a non-zero digit
        if ($digits[0] === '0') {
            return false;
        }
        return true;
    }

    /**
     * Validate Iranian bank card number (16 digits) with Luhn algorithm.
     */
    public static function isBankCard(string $input): bool
    {
        $digits = PersianDigits::toLatin($input);
        $digits = preg_replace('/[^\d]/', '', $digits) ?? '';

        if (strlen($digits) !== 16) {
            return false;
        }
        return self::luhnCheck($digits);
    }

    /**
     * Return a Persian error message for a failed mobile validation.
     */
    public static function mobileError(): string
    {
        return Lang::get('validation.mobile_invalid');
    }

    public static function nationalIdError(): string
    {
        return Lang::get('validation.national_id_invalid');
    }

    public static function postalCodeError(): string
    {
        return Lang::get('validation.postal_code_invalid');
    }

    private static function luhnCheck(string $digits): bool
    {
        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = !$alt;
        }
        return ($sum % 10) === 0;
    }
}
