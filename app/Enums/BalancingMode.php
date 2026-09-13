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
            self::Pcc => 'PCC (بر اساس اتصال)',
            self::Ecmp => 'ECMP (وزن‌دار)',
            self::RangeSplit => 'تقسیم بر اساس محدوده IP (بین لوکیشن‌ها)',
        };
    }
}
