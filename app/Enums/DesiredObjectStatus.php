<?php

namespace App\Enums;

enum DesiredObjectStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Drift = 'drift';
    case Error = 'error';
    case Removing = 'removing';
}
