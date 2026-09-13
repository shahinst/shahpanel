<?php

namespace App\Enums;

enum AgentHealth: string
{
    case Up = 'up';
    case Degraded = 'degraded';
    case Down = 'down';
    case Unknown = 'unknown';

    public function cssClass(): string
    {
        return match ($this) {
            self::Up => 'success',
            self::Degraded => 'warning',
            self::Down => 'danger',
            self::Unknown => 'secondary',
        };
    }
}
