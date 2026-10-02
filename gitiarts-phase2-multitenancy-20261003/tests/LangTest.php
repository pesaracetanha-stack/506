<?php

declare(strict_types=1);

namespace GitiArts\Phase2\Tests;

use GitiArts\Phase2\Localization\Lang;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Lang class — verifies Persian string loading,
 * fallback behavior, and interpolation.
 */
final class LangTest extends TestCase
{
    protected function setUp(): void
    {
        // Force a clean reload so test isolation is maintained.
        Lang::reload();
    }

    public function test_returns_persian_string_for_known_key(): void
    {
        $result = Lang::get('menu.item.add');
        self::assertSame('افزودن آیتم منو', $result);
    }

    public function test_returns_key_itself_for_missing_key(): void
    {
        $result = Lang::get('nonexistent.key.does.not.exist');
        self::assertSame('nonexistent.key.does.not.exist', $result);
    }

    public function test_interpolates_placeholders_in_persian_strings(): void
    {
        $result = Lang::get('validation.min_length', ['min' => '۳']);
        self::assertStringContainsString('حداقل', $result);
        self::assertStringContainsString('کاراکتر', $result);
    }

    public function test_loads_strings_from_fa_json_file(): void
    {
        self::assertNotEmpty(Lang::all('fa'));
        self::assertArrayHasKey('menu.item.add', Lang::all('fa'));
    }

    public function test_can_switch_locale(): void
    {
        Lang::setLocale('en');
        self::assertSame('Add menu item', Lang::get('menu.item.add'));

        Lang::setLocale('fa');
        self::assertSame('افزودن آیتم منو', Lang::get('menu.item.add'));
    }

    public function test_persian_strings_are_multibyte_safe(): void
    {
        $value = Lang::get('menu.item.add');
        // Persian text MUST be handled with mb_* functions; verify length
        self::assertSame(15, mb_strlen($value, 'UTF-8'));
        self::assertNotEquals(strlen($value), mb_strlen($value, 'UTF-8'));
    }
}
