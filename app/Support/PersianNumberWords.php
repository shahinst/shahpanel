<?php

namespace App\Support;

/**
 * Whole numbers in Persian words, for the "amount in words" line on invoices
 * (۱۲۵۰۰۰ → «صد و بیست و پنج هزار»).
 */
class PersianNumberWords
{
    private const ONES = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه'];

    private const TEENS = ['ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];

    private const TENS = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];

    private const HUNDREDS = ['', 'صد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];

    private const SCALES = ['', 'هزار', 'میلیون', 'میلیارد', 'هزار میلیارد'];

    public static function convert(int|float|string $number): string
    {
        $number = (int) floor(abs((float) $number));

        if ($number === 0) {
            return 'صفر';
        }

        $parts = [];
        $scale = 0;

        while ($number > 0 && $scale < count(self::SCALES)) {
            $chunk = $number % 1000;

            if ($chunk > 0) {
                // «هزار», not «یک هزار».
                $parts[] = $chunk === 1 && $scale === 1
                    ? self::SCALES[1]
                    : trim(self::threeDigits($chunk).' '.self::SCALES[$scale]);
            }

            $number = intdiv($number, 1000);
            $scale++;
        }

        return implode(' و ', array_reverse($parts));
    }

    private static function threeDigits(int $n): string
    {
        $words = [];

        if ($n >= 100) {
            $words[] = self::HUNDREDS[intdiv($n, 100)];
            $n %= 100;
        }

        if ($n >= 20) {
            $words[] = self::TENS[intdiv($n, 10)];
            $n %= 10;
        } elseif ($n >= 10) {
            $words[] = self::TEENS[$n - 10];
            $n = 0;
        }

        if ($n > 0) {
            $words[] = self::ONES[$n];
        }

        return implode(' و ', $words);
    }
}
