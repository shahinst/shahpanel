<?php

namespace App\Enums;

enum PasarguardExpiryActivation: string
{
    /** انقضا از زمان ساخت — status=active و expire */
    case FromCreation = 'from_creation';
    /** انقضا از اولین اتصال — status=on_hold */
    case FromFirstConnection = 'from_first_connection';

    public function label(): string
    {
        return match ($this) {
            self::FromCreation => __('packages.pasarguard_expiry_from_creation'),
            self::FromFirstConnection => __('packages.pasarguard_expiry_from_first_connection'),
        };
    }

    public function usesOnHold(): bool
    {
        return $this === self::FromFirstConnection;
    }
}
