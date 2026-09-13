<?php

namespace App\Enums;

enum TunnelGroupStatus: string
{
    case Draft = 'draft';
    case Applying = 'applying';
    case Active = 'active';
    case Degraded = 'degraded';
    case Down = 'down';
    case Removing = 'removing';
    case Error = 'error';

    public function label(): string
    {
        return __('tunneling.group_status_'.$this->value);
    }

    public function cssClass(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Applying, self::Removing => 'info',
            self::Degraded => 'warning',
            self::Down, self::Error => 'danger',
            self::Draft => 'secondary',
        };
    }
}
