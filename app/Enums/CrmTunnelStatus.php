<?php

namespace App\Enums;

enum CrmTunnelStatus: string
{
    case Pending = 'pending';
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Failed = 'failed';
    case Down = 'down';
}
