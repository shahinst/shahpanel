<?php

namespace App\Enums;

enum PaymentCardApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('payment_cards.status_pending'),
            self::Approved => __('payment_cards.status_approved'),
            self::Rejected => __('payment_cards.status_rejected'),
        };
    }
}
