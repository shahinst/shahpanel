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
            self::Draft => 'در انتظار احراز',
            self::Verified => 'احراز شده',
            self::Locked => 'قفل (بیش از حد تلاش)',
            self::ResetRequested => 'درخواست ریست',
            self::Used => 'مصرف‌شده برای اکانت',
        };
    }
}
