<?php

namespace App\Services\Kyc;

use Morilog\Jalali\Jalalian;

final class IranIdentityValidator
{
    public static function normalizeDigits(string $value): string
    {
        $map = [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ];

        return strtr($value, $map);
    }

    public static function normalizeNationalCode(string $value): string
    {
        return preg_replace('/\D+/', '', self::normalizeDigits($value)) ?? '';
    }

    public static function isValidNationalCode(string $value): bool
    {
        $code = self::normalizeNationalCode($value);
        if (strlen($code) !== 10 || ! ctype_digit($code)) {
            return false;
        }

        if (preg_match('/^(\d)\1{9}$/', $code)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $code[$i] * (10 - $i);
        }

        $remainder = $sum % 11;
        $check = (int) $code[9];

        return ($remainder < 2 && $check === $remainder)
            || ($remainder >= 2 && $check === 11 - $remainder);
    }

    /**
     * Accepts Persian/Arabic digits and the +98 / 0098 / 98 / bare 9xxxxxxxxx forms,
     * and normalises everything to the canonical 09xxxxxxxxx shape.
     */
    public static function normalizeMobile(string $value): string
    {
        $digits = preg_replace('/\D+/', '', self::normalizeDigits($value)) ?? '';

        if (str_starts_with($digits, '0098')) {
            $digits = substr($digits, 4);
        } elseif (strlen($digits) === 12 && str_starts_with($digits, '98')) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
            $digits = '0'.$digits;
        }

        return $digits;
    }

    public static function isValidMobile(string $value): bool
    {
        return (bool) preg_match('/^09\d{9}$/', self::normalizeMobile($value));
    }

    /**
     * Returns a zero-padded Jalali date (YYYY/MM/DD) — the same convention
     * parse_jalali_date() and Jalalian::fromFormat('Y/m/d') expect — or null
     * when the date does not exist in the Jalali calendar (e.g. 1370/12/31).
     */
    public static function normalizeJalaliBirthDate(string $value): ?string
    {
        $value = trim(self::normalizeDigits($value));
        $value = str_replace(['-', '.', ' '], '/', $value);
        if (! preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $value, $m)) {
            return null;
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];

        if ($year < 1250 || $year > 1450) {
            return null;
        }

        $canonical = sprintf('%04d/%02d/%02d', $year, $month, $day);

        try {
            $jalali = Jalalian::fromFormat('Y/m/d', $canonical);
        } catch (\Throwable) {
            return null;
        }

        // fromFormat() silently rolls overflowing days forward on some inputs,
        // so round-trip the parts to reject dates that do not really exist.
        if ($jalali->getYear() !== $year || $jalali->getMonth() !== $month || $jalali->getDay() !== $day) {
            return null;
        }

        return $canonical;
    }
}
