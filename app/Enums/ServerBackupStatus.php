<?php

namespace App\Enums;

enum ServerBackupStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('server_backups.status_pending'),
            self::Running => __('server_backups.status_running'),
            self::Completed => __('server_backups.status_completed'),
            self::Failed => __('server_backups.status_failed'),
        };
    }
}
