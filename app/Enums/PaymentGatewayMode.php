<?php

namespace App\Enums;

enum PaymentGatewayMode: string
{
    case Test = 'test';
    case Live = 'live';

    public function label(): string
    {
        return match ($this) {
            self::Test => __('payment_gateways.mode_test'),
            self::Live => __('payment_gateways.mode_live'),
        };
    }

    public function isLive(): bool
    {
        return $this === self::Live;
    }
}
