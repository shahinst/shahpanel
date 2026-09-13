<?php

namespace App\Enums;

enum SyncLogStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';
}
