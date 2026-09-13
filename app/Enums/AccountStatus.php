<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Disabled = 'disabled';
    case Expired = 'expired';
    case Exhausted = 'exhausted';
}
