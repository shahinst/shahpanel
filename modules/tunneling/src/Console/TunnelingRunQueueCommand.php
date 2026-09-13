<?php

namespace Modules\Tunneling\Console;

use App\Support\TunnelQueueHealth;
use App\Support\TunnelQueueMaintenance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class TunnelingRunQueueCommand extends Command
{
    protected $signature = 'tunneling:run-queue
                            {--max-time=300 : Max seconds to process jobs}
                            {--purge-background : Delete piled-up metrics/evaluate/reconcile jobs first}';

    protected $description = 'Process tunneling queues (apply jobs first, metrics last)';

    public function handle(): int
    {
        if ($this->option('purge-background')) {
            $removed = TunnelQueueMaintenance::purgeBackgroundJobs();
            $this->warn("Purged {$removed} background job(s) from queue.");
        }

        $maxTime = max(10, (int) $this->option('max-time'));
        $queues = implode(',', TunnelQueueHealth::queueNames());

        $this->info("Processing queues: {$queues} (max {$maxTime}s)");

        Artisan::call('queue:work', [
            '--queue' => $queues,
            '--stop-when-empty' => true,
            '--max-time' => $maxTime,
            '--tries' => 3,
        ]);

        Cache::put('system_health.queue_worker_at', now()->timestamp, now()->addHours(6));

        $output = trim(Artisan::output());
        if ($output !== '') {
            $this->line($output);
        }

        $snapshot = TunnelQueueHealth::snapshot();
        $this->line('Pending: '.$snapshot['pending'].' | Failed: '.$snapshot['failed']);

        $breakdown = TunnelQueueMaintenance::pendingByClass();
        if ($breakdown !== []) {
            $this->newLine();
            $this->line('Remaining by class:');
            foreach ($breakdown as $class => $count) {
                $this->line("  {$class}: {$count}");
            }
        }

        return self::SUCCESS;
    }
}
