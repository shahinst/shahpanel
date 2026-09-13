<?php

namespace App\Enums;

enum TunnelDirection: string
{
    case Normal = 'normal';
    case Reverse = 'reverse';

    public function opposite(): self
    {
        return $this === self::Normal ? self::Reverse : self::Normal;
    }
}
