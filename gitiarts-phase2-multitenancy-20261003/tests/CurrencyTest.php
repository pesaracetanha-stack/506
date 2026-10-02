<?php

declare(strict_types=1);

namespace GitiArts\Phase2\Tests;

use GitiArts\Phase2\Localization\Currency;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Toman currency formatter.
 */
final class CurrencyTest extends TestCase
{
    public function test_format_basic_amount(): void
    {
        self::assertSame('۱۵۰,۰۰۰ تومان', Currency::format(150000));
    }

    public function test_format_zero(): void
    {
        self::assertSame('۰ تومان', Currency::format(0));
    }

    public function test_format_large_amount(): void
    {
        self::assertSame('۱,۲۳۴,۵۶۷,۸۹۰ تومان', Currency::format(1234567890));
    }

    public function test_format_bare_without_currency_suffix(): void
    {
        self::assertSame('۱۵۰,۰۰۰', Currency::formatBare(150000));
    }

    public function test_format_short_thousand(): void
    {
        $result = Currency::formatShort(15000);
        self::assertStringContainsString('هزار', $result);
        self::assertStringContainsString('تومان', $result);
    }

    public function test_format_short_million(): void
    {
        $result = Currency::formatShort(1500000);
        self::assertStringContainsString('میلیون', $result);
    }

    public function test_format_short_billion(): void
    {
        $result = Currency::formatShort(2000000000);
        self::assertStringContainsString('میلیارد', $result);
    }

    public function test_format_small_amount_uses_full_format(): void
    {
        self::assertSame('۹۵۰ تومان', Currency::formatShort(950));
    }

    public function test_parse_strips_currency_suffix_and_separators(): void
    {
        self::assertSame(150000, Currency::parse('۱۵۰,۰۰۰ تومان'));
        self::assertSame(1500000, Currency::parse('1,500,000'));
    }

    public function test_parse_returns_null_on_invalid_input(): void
    {
        self::assertNull(Currency::parse('فقط حروف'));
    }
}
