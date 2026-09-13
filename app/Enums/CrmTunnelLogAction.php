<?php

namespace App\Enums;

enum CrmTunnelLogAction: string
{
    case Reset = 'reset';
    case Provision = 'provision';
    case Test = 'test';
    case Teardown = 'teardown';
}
