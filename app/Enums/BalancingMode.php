<?php

namespace App\Enums;

enum BalancingMode: string
{
    case Pcc = 'pcc';
    case Ecmp = 'ecmp';
    /** Static split of client IP pool into N contiguous ranges, one per exit (with FIB per exit). */
    case RangeSplit = 'range_split';

    public function label(): string
    {
        return match ($this) {
            self::Pcc => __('backend.balancing_mode_pcc'),
            self::Ecmp => __('backend.balancing_mode_ecmp'),
            self::RangeSplit => __('backend.balancing_mode_range_split'),
        };
    }
}
