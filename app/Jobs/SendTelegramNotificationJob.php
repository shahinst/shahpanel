<?php

namespace App\Jobs;

use App\Services\ServerBackup\ServerBackupTelegramNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Copies a panel notification to the recipient's Telegram, through the bot the
 * admin configured for backups. Queued, so a slow or blocked api.telegram.org
 * never holds up the request or command that raised the notification.
 */
class SendTelegramNotificationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public string $chatId,
        public string $text,
    ) {}

    public function handle(ServerBackupTelegramNotifier $notifier): void
    {
        $notifier->sendMessageTo($this->chatId, $this->text);
    }
}
