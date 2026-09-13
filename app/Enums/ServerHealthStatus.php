<?php

namespace App\Enums;

enum ServerHealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unreachable = 'unreachable';
    case Unknown = 'unknown';
}
