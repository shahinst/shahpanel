<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('tickets.status_open'),
            self::InProgress => __('tickets.status_in_progress'),
            self::Resolved => __('tickets.status_resolved'),
            self::Closed => __('tickets.status_closed'),
        };
    }
}
