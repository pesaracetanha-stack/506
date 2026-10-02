<?php

declare(strict_types=1);

namespace GitiArts\Phase2\Tests;

use GitiArts\Phase2\Localization\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Iranian-specific input validator.
 *
 * Reference: official Iranian National ID checksum algorithm.
 */
final class ValidatorTest extends TestCase
{
    public function test_validates_canonical_mobile_format(): void
    {
        self::assertTrue(Validator::isIranianMobile('09123456789'));
    }

    public function test_validates_plus_98_prefix_mobile(): void
    {
        self::assertTrue(Validator::isIranianMobile('+989123456789'));
    }

    public function test_validates_0098_prefix_mobile(): void
    {
        self::assertTrue(Validator::isIranianMobile('00989123456789'));
    }

    public function test_rejects_too_short_mobile(): void
    {
        self::assertFalse(Validator::isIranianMobile('0912345678'));
    }

    public function test_rejects_mobile_without_leading_zero(): void
    {
        // 1234567890 — does not start with 9 after stripping
        self::assertFalse(Validator::isIranianMobile('1234567890'));
    }

    public function test_accepts_persian_digit_mobile_input(): void
    {
        self::assertTrue(Validator::isIranianMobile('۰۹۱۲۳۴۵۶۷۸۹'));
    }

    public function test_normalize_mobile_to_canonical_form(): void
    {
        self::assertSame('09123456789', Validator::normalizeMobile('+989123456789'));
        self::assertSame('09123456789', Validator::normalizeMobile('۰۰۹۸۹۱۲۳۴۵۶۷۸۹'));
        self::assertSame('09123456789', Validator::normalizeMobile('۹۱۲۳۴۵۶۷۸۹'));
    }

    public function test_validates_correct_national_id(): void
    {
        // Known-valid test code (NOT a real person's NID)
        self::assertTrue(Validator::isNationalId('0076229645'));
    }

    public function test_rejects_national_id_with_bad_checksum(): void
    {
        self::assertFalse(Validator::isNationalId('0076229646'));
    }

    public function test_rejects_short_national_id(): void
    {
        self::assertFalse(Validator::isNationalId('12345'));
    }

    public function test_rejects_all_same_digit_national_id(): void
    {
        // 1111111111 — passes checksum trivially, must be rejected
        self::assertFalse(Validator::isNationalId('1111111111'));
        self::assertFalse(Validator::isNationalId('0000000000'));
    }

    public function test_accepts_persian_digit_national_id(): void
    {
        self::assertTrue(Validator::isNationalId('۰۰۷۶۲۲۹۶۴۵'));
    }

    public function test_validates_correct_postal_code(): void
    {
        self::assertTrue(Validator::isPostalCode('1357913579'));
    }

    public function test_rejects_postal_code_starting_with_zero(): void
    {
        self::assertFalse(Validator::isPostalCode('0123456789'));
    }

    public function test_rejects_short_postal_code(): void
    {
        self::assertFalse(Validator::isPostalCode('12345'));
    }

    public function test_mobile_error_message_is_persian(): void
    {
        $msg = Validator::mobileError();
        self::assertStringContainsString('موبایل', $msg);
        self::assertStringContainsString('معتبر', $msg);
    }

    public function test_national_id_error_message_is_persian(): void
    {
        $msg = Validator::nationalIdError();
        self::assertStringContainsString('کد ملی', $msg);
    }
}
