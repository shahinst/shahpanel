<?php

namespace App\Support;

use App\Models\Setting;

final class PaymentSettings
{
    public const KEY_USDT_TOMAN_RATE = 'payment_usdt_toman_rate';

    public static function usdtTomanRate(): ?string
    {
        $value = Setting::getValue(self::KEY_USDT_TOMAN_RATE);

        if ($value === null || trim($value) === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    public static function setUsdtTomanRate(?string $rate): void
    {
        if ($rate === null || trim($rate) === '') {
            Setting::setValue(self::KEY_USDT_TOMAN_RATE, null);

            return;
        }

        Setting::setValue(self::KEY_USDT_TOMAN_RATE, number_format((float) $rate, 2, '.', ''));
    }

    public static function hasUsdtTomanRate(): bool
    {
        $rate = self::usdtTomanRate();

        return $rate !== null && bccomp($rate, '0', 2) > 0;
    }
}
