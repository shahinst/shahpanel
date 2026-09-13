<?php

namespace App\Enums;

enum PasarguardConnectionMode: string
{
    /** نمایندگی / ادمین محدود — فقط کاربران، بدون سینک inbound */
    case Reseller = 'reseller';
    /** اتصال به پنل اصلی (sudo) — inbound و node در دسترس است */
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Reseller => __('servers.pasarguard_mode_reseller'),
            self::Admin => __('servers.pasarguard_mode_admin'),
        };
    }

    public function isReseller(): bool
    {
        return $this === self::Reseller;
    }
}
