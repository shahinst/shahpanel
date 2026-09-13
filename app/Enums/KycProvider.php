<?php

namespace App\Enums;

enum KycProvider: string
{
    case ApiIr = 'api_ir';

    public function label(): string
    {
        return match ($this) {
            self::ApiIr => 'api.ir (Switch1)',
        };
    }
}
