<?php

use App\Support\TunnelQueueHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    Cache::put('system_health.schedule_runner_at', now()->timestamp, now()->addHours(6));
})->everyMinute()->name('system_health.schedule_runner');

Schedule::command('sync:usage')
    ->everyFiveMinutes()
    // Full multi-server sync can exceed 5 minutes; keep mutex until the child finishes.
    ->withoutOverlapping(45)
    ->runInBackground()
    ->after(fn () => Cache::put('system_health.job.sync_usage_at', now()->timestamp, now()->addHours(6)));

Schedule::command('accounts:check-expiry')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health.job.accounts_expiry_at', now()->timestamp, now()->addHours(6)));

Schedule::command('alerts:dispatch')
    ->hourly()
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health.job.alerts_at', now()->timestamp, now()->addHours(6)));

Schedule::command('backup:database')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health.job.backup_at', now()->timestamp, now()->addDays(3)));

Schedule::command('reports:daily-rollup')
    ->dailyAt('00:00')
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health.job.reports_at', now()->timestamp, now()->addDays(3)));

Schedule::command('tickets:auto-close')
    ->daily()
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health.job.tickets_at', now()->timestamp, now()->addDays(3)));

/*
|--------------------------------------------------------------------------
| Tunneling (desired-state system) — all heavy I/O runs inside queue jobs
| processed by the cron-driven `queue:work --stop-when-empty` worker.
|--------------------------------------------------------------------------
*/

// Capacity metrics for every active MikroTik (CPU/RAM/conntrack/throughput).
Schedule::call(function (): void {
    if (TunnelQueueHealth::pendingJobsCount() > (int) config('tunneling.queue.skip_low_priority_above', 30)) {
        return;
    }

    \App\Models\Server::query()
        ->active()
        ->where('type', \App\Enums\ServerType::Mikrotik)
        ->pluck('id')
        ->each(fn (int $id) => \App\Jobs\Tunneling\CollectServerMetricsJob::dispatch($id));
})->everyMinute()->name('tunneling.collect_server_metrics');

// Drift detection + auto-repair on every router that has desired objects.
Schedule::call(function (): void {
    if (TunnelQueueHealth::pendingJobsCount() > (int) config('tunneling.queue.skip_low_priority_above', 30)) {
        return;
    }

    \App\Models\DesiredNetworkObject::query()
        ->select('server_id')
        ->distinct()
        ->pluck('server_id')
        ->each(fn (int $id) => \App\Jobs\Tunneling\ReconcileServerJob::dispatch($id));
})->everyFiveMinutes()->name('tunneling.reconcile');

// Hourly metric rollups + retention pruning (MySQL instead of a TSDB).
Schedule::command('tunnels:rollup-metrics')
    ->hourlyAt(4)
    ->withoutOverlapping();

Schedule::command('tunnels:prune-metrics')
    ->dailyAt('03:30')
    ->withoutOverlapping();

// Agent health evaluation from received probe reports (marks down agents,
// triggers DPI switches and load-balancer reweighting).
Schedule::call(function (): void {
    if (TunnelQueueHealth::pendingJobsCount() > (int) config('tunneling.queue.skip_low_priority_above', 30)) {
        return;
    }

    \App\Models\TunnelGroup::query()
        ->whereIn('status', ['active', 'degraded', 'down'])
        ->pluck('id')
        ->each(fn (int $id) => \App\Jobs\Tunneling\EvaluateTunnelGroupJob::dispatch($id));
})->everyMinute()->name('tunneling.evaluate_groups');

// Capacity alarms (CPU/conntrack/throughput near cap) via Telegram.
Schedule::call(function (): void {
    app(\App\Services\Tunneling\CapacityAlarmService::class)->evaluate();
})->everyFiveMinutes()->name('tunneling.capacity_alarms');

// Process database queue (tunneling + default). Without this, apply/reconcile
// jobs sit in `jobs` forever when crontab only runs schedule:run.
Schedule::command('queue:work', [
    '--queue' => implode(',', \App\Support\TunnelQueueHealth::queueNames()),
    '--stop-when-empty' => true,
    '--max-time' => 55,
    '--tries' => 3,
])
    ->everyMinute()
    ->withoutOverlapping(120)
    ->runInBackground()
    ->after(fn () => Cache::put('system_health.queue_worker_at', now()->timestamp, now()->addHours(6)));

Schedule::command('firewall prune')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('firewall resync')->hourly()->withoutOverlapping();
Schedule::command('firewall:sync-country-data')->weeklyOn(1, '03:30')->withoutOverlapping();
