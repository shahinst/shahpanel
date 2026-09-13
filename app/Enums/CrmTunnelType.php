<?php

namespace App\Enums;

enum CrmTunnelType: string
{
    case Gre = 'gre';
    case Wireguard = 'wireguard';
    case Gre6 = 'gre6';
    case Eoip = 'eoip';
}
