<?php

namespace App\Enums;

enum KycVerificationStatus: string
{
    case Draft = 'draft';
    case Verified = 'verified';
    case Locked = 'locked';
    case ResetRequested = 'reset_requested';
    case Used = 'used';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('backend.kyc_status_draft'),
            self::Verified => __('backend.kyc_status_verified'),
            self::Locked => __('backend.kyc_status_locked'),
            self::ResetRequested => __('backend.kyc_status_reset_requested'),
            self::Used => __('backend.kyc_status_used'),
        };
    }
}
