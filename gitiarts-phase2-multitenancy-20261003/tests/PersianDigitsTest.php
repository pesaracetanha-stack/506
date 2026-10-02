<?php

declare(strict_types=1);

namespace GitiArts\Phase2\Tests;

use GitiArts\Phase2\Localization\PersianDigits;
use PHPUnit\Framework\TestCase;

/**
 * Tests for PersianDigits — bidirectional digit conversion and formatting.
 */
final class PersianDigitsTest extends TestCase
{
    public function test_converts_latin_to_persian_digits(): void
    {
        self::assertSame('۱۲۳۴۵۶۷۸۹۰', PersianDigits::toPersian('1234567890'));
    }

    public function test_converts_integer_to_persian_digits(): void
    {
        self::assertSame('۱۴۰۵', PersianDigits::toPersian(1405));
    }

    public function test_converts_persian_to_latin_digits(): void
    {
        self::assertSame('1234567890', PersianDigits::toLatin('۱۲۳۴۵۶۷۸۹۰'));
    }

    public function test_converts_arabic_indic_digits_to_latin(): void
    {
        // Arabic-Indic digits (٠-٩) MUST also be normalized to Latin.
        self::assertSame('1234567890', PersianDigits::toLatin('١٢٣٤٥٦٧٨٩٠'));
    }

    public function test_format_number_adds_thousands_separator(): void
    {
        self::assertSame('۱,۲۳۴,۵۶۷', PersianDigits::formatNumber(1234567));
    }

    public function test_format_decimal_with_two_places(): void
    {
        self::assertSame('۱,۲۳۴.۵۰', PersianDigits::formatDecimal(1234.5, 2));
    }

    public function test_normalize_strips_persian_and_arabic_digits(): void
    {
        self::assertSame('09123456789', PersianDigits::normalize('۰۹۱۲۳۴۵۶۷۸۹'));
        self::assertSame('09123456789', PersianDigits::normalize('٠٩١٢٣٤٥٦٧٨٩'));
    }

    public function test_round_trip_preserves_value(): void
    {
        $original = '09123456789';
        $persian  = PersianDigits::toPersian($original);
        $back     = PersianDigits::toLatin($persian);
        self::assertSame($original, $back);
    }

    public function test_non_digit_characters_preserved(): void
    {
        self::assertSame('کافه ۱۲۳', PersianDigits::toPersian('کافه 123'));
    }
}
