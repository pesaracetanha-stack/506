<?php

declare(strict_types=1);

/**
 * GitiArts Menu Platform - Phase 2
 * Copyright (c) 2026 GitiArts. All rights reserved.
 */

namespace GitiArts\Phase2\Localization;

use GitiArts\Phase2\Support\Env;

/**
 * JalaliDate
 *
 * Pure-PHP Jalali (Shamsi / Persian Solar) calendar implementation.
 *
 * Algorithm source: the well-known conversion published by Kazimierz
 * M. Borkowski (used by jdf / Sallar Kaboli's jalaali-js, etc.).
 * No external dependency.
 *
 * Reference epoch: 1 Farvardin 1 = 22 March 622 CE (Gregorian).
 *
 * Persian month names:
 *   فروردین، اردیبهشت، خرداد، تیر، مرداد، شهریور،
 *   مهر، آبان، آذر، دی، بهمن، اسفند
 *
 * Persian weekday names (Saturday-first week, per Iranian convention):
 *   شنبه، یکشنبه، دوشنبه، سه‌شنبه، چهارشنبه، پنجشنبه، جمعه
 */
final class JalaliDate
{
    public const MONTHS_FA = [
        1  => 'فروردین',
        2  => 'اردیبهشت',
        3  => 'خرداد',
        4  => 'تیر',
        5  => 'مرداد',
        6  => 'شهریور',
        7  => 'مهر',
        8  => 'آبان',
        9  => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند',
    ];

    /** @var array<int,string> Saturday-first (Persian convention). */
    public const WEEKDAYS_FA = [
        6 => 'شنبه',      // Saturday  (PHP w=6)
        0 => 'یکشنبه',    // Sunday    (PHP w=0)
        1 => 'دوشنبه',    // Monday
        2 => 'سه‌شنبه',   // Tuesday
        3 => 'چهارشنبه',  // Wednesday
        4 => 'پنجشنبه',   // Thursday
        5 => 'جمعه',      // Friday
    ];

    /**
     * Convert a Gregorian date to a Jalali date string.
     *
     * @param string $gregorian "YYYY-MM-DD" or "YYYY-MM-DD HH:MM:SS".
     * @param string $separator Output separator (default "/").
     * @return string Persian-formatted Jalali date "YYYY/MM/DD" (with Persian digits if requested).
     */
    public static function toJalali(string $gregorian, string $separator = '/'): string
    {
        $parts = self::parseGregorian($gregorian);
        if ($parts === null) {
            return $gregorian;
        }
        [$gy, $gm, $gd] = $parts;
        [$jy, $jm, $jd] = self::gregorianToJalali($gy, $gm, $gd);

        return sprintf('%04d%s%02d%s%02d', $jy, $separator, $jm, $separator, $jd);
    }

    /**
     * Convert a Jalali date string to a Gregorian ISO date.
     *
     * @param string $jalali "YYYY/MM/DD" (Latin or Persian digits both accepted).
     * @return string Gregorian "YYYY-MM-DD" or input string on parse failure.
     */
    public static function toGregorian(string $jalali): string
    {
        $normalized = PersianDigits::toLatin($jalali);
        if (!preg_match('#(\d{4})[/\-\.](\d{1,2})[/\-\.](\d{1,2})#', $normalized, $m)) {
            return $jalali;
        }
        [$jy, $jm, $jd] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        [$gy, $gm, $gd] = self::jalaliToGregorian($jy, $jm, $jd);
        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }

    /**
     * Format a date/time string in Persian style with Persian digits.
     *
     * @param string $datetime Any strtotime()-parseable string, or "now".
     * @param string $format   PHP date format (Y/m/d H:i is common).
     * @return string Persian-formatted output. Month/day names replaced
     *               with Persian equivalents; digits replaced with Persian.
     */
    public static function format(string $datetime, string $format = 'Y/m/d H:i'): string
    {
        $timestamp = $datetime === 'now' ? time() : strtotime($datetime);
        if ($timestamp === false) {
            return $datetime;
        }

        // First convert the Gregorian timestamp into a Jalali Y/m/d
        $gy = (int) date('Y', $timestamp);
        $gm = (int) date('n', $timestamp);
        $gd = (int) date('j', $timestamp);
        [$jy, $jm, $jd] = self::gregorianToJalali($gy, $gm, $gd);

        // Replace supported format tokens
        $weekday = (int) date('w', $timestamp);

        $out = $format;
        $out = str_replace(['Y', 'y', 'm', 'n', 'd', 'j', 'H', 'i', 's'], [
            sprintf('%04d', $jy),
            sprintf('%02d', $jy % 100),
            sprintf('%02d', $jm),
            (string) $jm,
            sprintf('%02d', $jd),
            (string) $jd,
            date('H', $timestamp),
            date('i', $timestamp),
            date('s', $timestamp),
        ], $out);

        // Persian weekday name (l) and short (D)
        $out = str_replace(['l', 'D'], [
            self::WEEKDAYS_FA[$weekday] ?? '',
            mb_substr(self::WEEKDAYS_FA[$weekday] ?? '', 0, 3, 'UTF-8'),
        ], $out);

        // Persian month name (F)
        $out = str_replace('F', self::MONTHS_FA[$jm] ?? '', $out);

        return PersianDigits::toPersian($out);
    }

    /**
     * Return the Persian month name for a Jalali month number.
     */
    public static function monthName(int $month): string
    {
        return self::MONTHS_FA[$month] ?? '';
    }

    /**
     * Return the Persian weekday name for a PHP date('w') value.
     */
    public static function weekdayName(int $weekday): string
    {
        return self::WEEKDAYS_FA[$weekday] ?? '';
    }

    // --------------------------------------------------------------
    // Core conversion algorithm (Borkowski).
    // --------------------------------------------------------------

    /**
     * @return array{int,int,int} [$jy, $jm, $jd]
     */
    public static function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];

        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + (int) (($gy2 + 3) / 4) - (int) (($gy2 + 99) / 100)
              + (int) (($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1];

        $jy = -1595 + (33 * (int) ($days / 12053));
        $days %= 12053;

        $jy += 4 * (int) ($days / 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += (int) (($days - 1) / 365);
            $days = ($days - 1) % 365;
        }

        $jm = ($days < 186) ? (1 + (int) ($days / 31)) : (7 + (int) (($days - 186) / 30));
        $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));

        return [$jy, $jm, $jd];
    }

    /**
     * @return array{int,int,int} [$gy, $gm, $gd]
     */
    public static function jalaliToGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + ((int) ($jy / 33)) * 8 + (int) ((($jy % 33) + 3) / 4)
              + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30 + 186));

        $gy = 400 * (int) ($days / 146097);
        $days %= 146097;

        if ($days > 36524) {
            $gy += 100 * (int) (--$days / 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }

        $gy += 4 * (int) ($days / 1461);
        $days %= 1461;

        if ($days > 365) {
            $gy += (int) (($days - 1) / 365);
            $days = ($days - 1) % 365;
        }

        $gd = $days + 1;

        $sal_a = [0, 31, 29, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 0;
        foreach ($sal_a as $m => $md) {
            if ($gd <= $md) {
                $gm = $m;
                break;
            }
            $gd -= $md;
        }

        // Handle leap-year adjustment for non-leap Gregorian years
        if ($gm === 0) {
            $gm = 12;
            $gd = 31;
        }

        return [$gy, $gm, $gd];
    }

    /**
     * Parse a Gregorian date or datetime string into Y/m/d integers.
     *
     * @return array{int,int,int}|null
     */
    private static function parseGregorian(string $input): ?array
    {
        $normalized = PersianDigits::toLatin($input);
        if (!preg_match('#(\d{4})[/\-\.](\d{1,2})[/\-\.](\d{1,2})#', $normalized, $m)) {
            return null;
        }
        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }
}
