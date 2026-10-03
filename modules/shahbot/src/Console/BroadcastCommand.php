<?php

namespace Modules\ShahBot\Console;

use Illuminate\Console\Command;
use Modules\ShahBot\Services\BroadcastService;
use Modules\ShahBot\Support\BotSettings;

class BroadcastCommand extends Command
{
    protected $signature = 'shahbot:broadcast {--limit=400}';

    protected $description = 'Send the next batch of the queued bot broadcast';

    public function handle(BotSettings $settings, BroadcastService $broadcasts): int
    {
        if ($settings->get('bot_token') === '') {
            return self::SUCCESS;
        }

        $broadcast = $broadcasts->runBatch(max(1, (int) $this->option('limit')));

        if ($broadcast !== null) {
            $this->info("Broadcast #{$broadcast->id}: {$broadcast->sent} sent, {$broadcast->failed} failed, {$broadcast->status}");
        }

        return self::SUCCESS;
    }
}
