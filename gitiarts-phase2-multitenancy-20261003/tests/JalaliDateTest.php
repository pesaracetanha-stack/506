<?php

declare(strict_types=1);

namespace GitiArts\Phase2\Tests;

use GitiArts\Phase2\Localization\JalaliDate;
use PHPUnit\Framework\TestCase;

/**
 * Tests for JalaliDate — Gregorian ↔ Jalali conversion algorithm.
 *
 * Reference test dates verified against Iran's official calendar
 * (https://time.ir) on 2026-10-03.
 */
final class JalaliDateTest extends TestCase
{
    public function test_known_gregorian_to_jalali_conversion(): void
    {
        // 2026-10-03 (Gregorian) → 1405/07/11 (Jalali)
        self::assertSame('1405/07/11', JalaliDate::toJalali('2026-10-03'));
    }

    public function test_known_jalali_to_gregorian_conversion(): void
    {
        self::assertSame('2026-10-03', JalaliDate::toGregorian('1405/07/11'));
    }

    public function test_accepts_persian_digits_in_jalali_input(): void
    {
        // User typed Persian digits → must still parse correctly.
        self::assertSame('2026-10-03', JalaliDate::toGregorian('۱۴۰۵/۰۷/۱۱'));
    }

    public function test_round_trip_gregorian_to_jalali_back_to_gregorian(): void
    {
        $original = '2026-03-21'; // Persian New Year (Nowruz) 1405
        $jalali = JalaliDate::toJalali($original);
        $back = JalaliDate::toGregorian($jalali);
        self::assertSame($original, $back);
    }

    public function test_nowruz_date(): void
    {
        // Nowruz 1405 = 21 March 2026
        self::assertSame('1405/01/01', JalaliDate::toJalali('2026-03-21'));
    }

    public function test_format_returns_persian_digits(): void
    {
        $result = JalaliDate::format('2026-10-03 14:30', 'Y/m/d H:i');
        // All digits must be Persian
        self::assertStringContainsString('۱۴۰۵', $result);
        self::assertStringContainsString('۰۷', $result);
        self::assertStringContainsString('۱۱', $result);
        self::assertStringContainsString('۱۴:۳۰', $result);
    }

    public function test_month_names_are_persian(): void
    {
        self::assertSame('فروردین', JalaliDate::monthName(1));
        self::assertSame('مهر', JalaliDate::monthName(7));
        self::assertSame('اسفند', JalaliDate::monthName(12));
    }

    public function test_weekday_names_are_persian(): void
    {
        // Saturday (Persian start of week)
        self::assertSame('شنبه', JalaliDate::weekdayName(6));
        // Friday (Persian weekend)
        self::assertSame('جمعه', JalaliDate::weekdayName(5));
    }

    public function test_format_includes_persian_month_name_when_requested(): void
    {
        $result = JalaliDate::format('2026-10-03', 'd F Y');
        self::assertStringContainsString('مهر', $result);
    }
}
