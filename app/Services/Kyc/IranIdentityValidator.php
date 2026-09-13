<?php

namespace App\Services\Kyc;

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

    public static function normalizeCardNumber(string $value): string
    {
        return preg_replace('/\D+/', '', self::normalizeDigits($value)) ?? '';
    }

    public static function isValidCardNumber(string $value): bool
    {
        $card = self::normalizeCardNumber($value);
        if (strlen($card) !== 16 || ! ctype_digit($card)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 16; $i++) {
            $digit = (int) $card[$i];
            if ($i % 2 === 0) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return $sum % 10 === 0;
    }

    public static function normalizeJalaliBirthDate(string $value): ?string
    {
        $value = trim(self::normalizeDigits($value));
        $value = str_replace(['-', '.', ' '], '/', $value);
        if (! preg_match('#^(\d{3,4})/(\d{1,2})/(\d{1,2})$#', $value, $m)) {
            return null;
        }

        $year = (int) $m[1];
        $month = (int) $m[2];
        $day = (int) $m[3];

        if ($year < 1250 || $year > 1450 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        return $year.'/'.$month.'/'.$day;
    }

    public static function normalizePersonName(string $value): string
    {
        $value = trim($value);
        $value = str_replace(['ي', 'ك', '‌', '‍'], ['ی', 'ک', '', ''], $value);
        $value = preg_replace('/\s+/u', '', $value) ?? '';

        return mb_strtolower($value, 'UTF-8');
    }

    public static function namesMatch(string $firstName, string $lastName, ?string $apiFirst, ?string $apiLast): bool
    {
        $enteredFull = self::normalizePersonName($firstName.$lastName);
        $apiFull = self::normalizePersonName(((string) $apiFirst).((string) $apiLast));

        if ($enteredFull === '' || $apiFull === '') {
            return false;
        }

        if ($enteredFull === $apiFull) {
            return true;
        }

        $enteredFirst = self::normalizePersonName($firstName);
        $enteredLast = self::normalizePersonName($lastName);
        $remoteFirst = self::normalizePersonName((string) $apiFirst);
        $remoteLast = self::normalizePersonName((string) $apiLast);

        return $enteredFirst !== '' && $enteredLast !== ''
            && $enteredFirst === $remoteFirst
            && $enteredLast === $remoteLast;
    }

    public static function cardOwnerMatches(string $firstName, string $lastName, ?string $cardOwnerName): bool
    {
        $owner = self::normalizePersonName((string) $cardOwnerName);
        if ($owner === '') {
            return false;
        }

        $full = self::normalizePersonName($firstName.$lastName);
        if ($full !== '' && str_contains($owner, $full)) {
            return true;
        }

        $first = self::normalizePersonName($firstName);
        $last = self::normalizePersonName($lastName);

        return $first !== '' && $last !== ''
            && str_contains($owner, $first)
            && str_contains($owner, $last);
    }
}
