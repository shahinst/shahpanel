<?php

namespace App\Enums;

enum PaymentRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('menu.status_pending'),
            self::Approved => __('menu.status_approved'),
            self::Rejected => __('menu.status_rejected'),
        };
    }
}
