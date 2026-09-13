<?php

namespace App\Enums;

enum BroadcastStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('broadcasts.status_pending'),
            self::Approved => __('broadcasts.status_approved'),
            self::Rejected => __('broadcasts.status_rejected'),
        };
    }
}
