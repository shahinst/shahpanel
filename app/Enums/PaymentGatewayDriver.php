<?php

namespace App\Enums;

enum PaymentGatewayDriver: string
{
    case NowPayments = 'nowpayments';
    case Zarinpal = 'zarinpal';
    case CardToCard = 'card_to_card';

    public function label(): string
    {
        return match ($this) {
            self::NowPayments => __('payment_gateways.driver_nowpayments'),
            self::Zarinpal => __('payment_gateways.driver_zarinpal'),
            self::CardToCard => __('payment_gateways.driver_card_to_card'),
        };
    }

    public function isImplemented(): bool
    {
        return true;
    }

    public function inputCurrency(): string
    {
        return match ($this) {
            self::NowPayments => 'usdt',
            self::Zarinpal, self::CardToCard => 'toman',
        };
    }

    public function adminEditView(): string
    {
        return match ($this) {
            self::NowPayments => 'nowpayments::edit',
            self::Zarinpal => 'admin.payment-gateways.edit-zarinpal',
            self::CardToCard => 'admin.payment-gateways.edit-card-to-card',
        };
    }
}
