<?php

namespace Modules\Tunneling\Console;

use App\Support\TunnelQueueHealth;
use App\Support\TunnelQueueMaintenance;
use Illuminate\Console\Command;

class TunnelingPurgeQueueCommand extends Command
{
    protected $signature = 'tunneling:purge-queue
                            {--background : Remove piled-up metrics/evaluate/reconcile jobs}
                            {--metrics : Remove only CollectServerMetricsJob}
                            {--failed : Remove failed background jobs too}';

    protected $description = 'Delete stale tunneling background jobs blocking apply/reconcile';

    public function handle(): int
    {
        $before = TunnelQueueHealth::pendingJobsCount();

        $removed = 0;

        if ($this->option('metrics')) {
            $removed += TunnelQueueMaintenance::purgePendingClass('CollectServerMetricsJob');
            $this->line('Removed CollectServerMetricsJob: '.$removed);
        } elseif ($this->option('background') || ! $this->option('metrics')) {
            $removed = TunnelQueueMaintenance::purgeBackgroundJobs();
            $this->line("Removed background jobs: {$removed}");
        }

        if ($this->option('failed')) {
            $failed = TunnelQueueMaintenance::purgeFailedBackgroundJobs();
            $this->line("Removed failed background jobs: {$failed}");
        }

        $after = TunnelQueueHealth::pendingJobsCount();
        $this->info("Queue: {$before} → {$after} pending");

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
