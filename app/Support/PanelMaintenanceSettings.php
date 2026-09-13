<?php

namespace App\Support;

use App\Models\Setting;

final class PanelMaintenanceSettings
{
    public const KEY_ENABLED = 'panel_maintenance_enabled';

    public const KEY_MESSAGE = 'panel_maintenance_message';

    public static function isEnabled(): bool
    {
        return Setting::getValue(self::KEY_ENABLED, '0') === '1';
    }

    public static function setEnabled(bool $enabled): void
    {
        Setting::setValue(self::KEY_ENABLED, $enabled ? '1' : '0');
    }

    public static function message(): string
    {
        $stored = trim((string) Setting::getValue(self::KEY_MESSAGE, ''));

        if ($stored !== '') {
            return $stored;
        }

        return (string) __('maintenance.default_message');
    }

    public static function setMessage(?string $message): void
    {
        $text = trim((string) $message);
        Setting::setValue(self::KEY_MESSAGE, $text !== '' ? $text : null);
    }
}
