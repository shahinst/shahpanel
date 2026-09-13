<?php

namespace App\Enums;

enum PackageDurationTier: string
{
    case OneMonth = '1m';
    case ThreeMonths = '3m';
    case SixMonths = '6m';
    case OneYear = '1y';
    /** بدون سقف زمانی — انقضا فقط با اتمام حجم (PasarGuard: expire=null). */
    case UnlimitedTime = 'unlimited';
    case VolumeOnly = 'vol';
    case OneHour = '1h';
    case SixHours = '6h';
    case TwelveHours = '12h';
    case OneDay = '1d';

    public function label(): string
    {
        return match ($this) {
            self::OneMonth => __('packages.duration_1m'),
            self::ThreeMonths => __('packages.duration_3m'),
            self::SixMonths => __('packages.duration_6m'),
            self::OneYear => __('packages.duration_1y'),
            self::UnlimitedTime => __('packages.duration_unlimited'),
            self::VolumeOnly => __('packages.duration_vol'),
            self::OneHour => __('packages.duration_1h'),
            self::SixHours => __('packages.duration_6h'),
            self::TwelveHours => __('packages.duration_12h'),
            self::OneDay => __('packages.duration_1d'),
        };
    }

    public function isTest(): bool
    {
        return in_array($this, [
            self::OneHour,
            self::SixHours,
            self::TwelveHours,
            self::OneDay,
        ], true);
    }

    public function isVolumeOnly(): bool
    {
        return $this->hasNoTimeLimit();
    }

    public function hasNoTimeLimit(): bool
    {
        return in_array($this, [self::UnlimitedTime, self::VolumeOnly], true);
    }

    public function durationHours(): ?int
    {
        return match ($this) {
            self::OneMonth => 30 * 24,
            self::ThreeMonths => 90 * 24,
            self::SixMonths => 180 * 24,
            self::OneYear => 365 * 24,
            self::UnlimitedTime, self::VolumeOnly => null,
            self::OneHour => 1,
            self::SixHours => 6,
            self::TwelveHours => 12,
            self::OneDay => 24,
        };
    }

    /**
     * @return list<self>
     */
    public static function commercial(): array
    {
        return [
            self::UnlimitedTime,
            self::OneMonth,
            self::ThreeMonths,
            self::SixMonths,
            self::OneYear,
            self::VolumeOnly,
        ];
    }

    /**
     * @return list<self>
     */
    public static function test(): array
    {
        return [
            self::OneHour,
            self::SixHours,
            self::TwelveHours,
            self::OneDay,
        ];
    }
}
