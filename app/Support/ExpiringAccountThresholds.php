<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Panel-wide thresholds for the "expiring accounts" list: an account is shown
 * when it expires within N days OR has less than V of volume left. The admin
 * sets both in Automation; agents/sellers read the same values.
 */
class ExpiringAccountThresholds
{
    public const DAYS_KEY = 'expiring_accounts_days';
    public const VOLUME_MB_KEY = 'expiring_accounts_volume_mb';

    public const DEFAULT_DAYS = 7;
    public const MIN_DAYS = 1;
    public const MAX_DAYS = 7;

    public const DEFAULT_VOLUME_MB = 2048;   // 2 GB
    public const MIN_VOLUME_MB = 100;        // 100 MB
    public const MAX_VOLUME_MB = 5120;       // 5 GB

    public static function days(): int
    {
        $value = (int) (Setting::getValue(self::DAYS_KEY, (string) self::DEFAULT_DAYS) ?: self::DEFAULT_DAYS);

        return self::clampDays($value);
    }

    public static function volumeMb(): int
    {
        $value = (int) (Setting::getValue(self::VOLUME_MB_KEY, (string) self::DEFAULT_VOLUME_MB) ?: self::DEFAULT_VOLUME_MB);

        return self::clampVolumeMb($value);
    }

    public static function volumeBytes(): int
    {
        return self::volumeMb() * 1024 * 1024;
    }

    public static function clampDays(int $value): int
    {
        return max(self::MIN_DAYS, min(self::MAX_DAYS, $value));
    }

    public static function clampVolumeMb(int $value): int
    {
        return max(self::MIN_VOLUME_MB, min(self::MAX_VOLUME_MB, $value));
    }

    /** Human label for the configured volume threshold (e.g. "2 گیگابایت"). */
    public static function volumeLabel(): string
    {
        return format_data_size(self::volumeBytes());
    }
}
