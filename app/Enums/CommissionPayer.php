<?php

namespace App\Enums;

enum CommissionPayer: string
{
    case User = 'user';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::User => __('payment_gateways.commission_payer_user'),
            self::Admin => __('payment_gateways.commission_payer_admin'),
        };
    }
}
