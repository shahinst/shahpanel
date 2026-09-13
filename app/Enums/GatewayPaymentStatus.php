<?php

namespace App\Enums;

enum GatewayPaymentStatus: string
{
    case Pending = 'pending';
    case AwaitingPayment = 'awaiting_payment';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('payment_gateways.status_pending'),
            self::AwaitingPayment => __('payment_gateways.status_awaiting_payment'),
            self::Processing => __('payment_gateways.status_processing'),
            self::Completed => __('payment_gateways.status_completed'),
            self::Failed => __('payment_gateways.status_failed'),
            self::Expired => __('payment_gateways.status_expired'),
            self::Cancelled => __('payment_gateways.status_cancelled'),
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Failed,
            self::Expired,
            self::Cancelled,
        ], true);
    }

    public function isSuccessful(): bool
    {
        return $this === self::Completed;
    }
}
