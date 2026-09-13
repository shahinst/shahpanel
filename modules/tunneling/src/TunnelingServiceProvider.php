<?php

namespace Modules\Tunneling;

use App\Models\DesiredNetworkObject;
use App\Models\Server;
use App\Models\TunnelGroup;
use App\Services\Tunneling\CapacityAlarmService;
use App\Support\TunnelQueueHealth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the whole "tunneling" module. While this module is
 * active it owns the entire tunneling section: the admin pages and wizard, the
 * CRM tunnel API, the on-router probe report endpoint, the tunnels:* console
 * commands and every tunneling schedule entry. Deactivating the module removes
 * all of it, so the menu entry, the pages and the background work disappear.
 *
 * The models, jobs and services stay in the core (same arrangement the payments
 * module uses for its gateway classes): they are inert plumbing that nothing
 * reaches while the module is off, and the migrations that own their tables
 * must keep running regardless.
 */
class TunnelingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views/tunneling', 'tunneling');

        // withRouting(web: ...) applies the "web" group for the core route file;
        // module routes are registered separately and get nothing by default, so
        // without this the pages render with no session, no CSRF and no shared
        // $errors bag.
        Route::middleware('web')->group(__DIR__.'/../routes/web.php');

        // Laravel's own withRouting(api: ...) is not used any more — these are
        // the only API routes in the panel, so the module applies the prefix.
        // The route file declares the middleware it needs itself.
        Route::prefix('api')->group(__DIR__.'/../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\LegacyTunnelTeardownCommand::class,
                Console\TunnelingConfigureGroupCommand::class,
                Console\TunnelingDoctorCommand::class,
                Console\TunnelingForceDeleteGroupCommand::class,
                Console\TunnelingPurgeQueueCommand::class,
                Console\TunnelingRunQueueCommand::class,
                Console\TunnelingTeardownGroupCommand::class,
                Console\TunnelingWipeGroupCommand::class,
                Console\TunnelMetricsPruneCommand::class,
                Console\TunnelMetricsRollupCommand::class,
            ]);
        }

        $this->app->booted(function (): void {
            $this->scheduleTunnelingWork($this->app->make(Schedule::class));
        });
    }

    /**
     * Tunneling schedule entries (desired-state system). All heavy I/O runs
     * inside queue jobs processed by the core's cron-driven
     * `queue:work --stop-when-empty` worker, which stays in routes/console.php
     * because the whole panel depends on it.
     */
    protected function scheduleTunnelingWork(Schedule $schedule): void
    {
        // Capacity metrics for every active MikroTik (CPU/RAM/conntrack/throughput).
        $schedule->call(function (): void {
            if ($this->queueIsBacklogged()) {
                return;
            }

            Server::query()
                ->active()
                ->where('type', \App\Enums\ServerType::Mikrotik)
                ->pluck('id')
                ->each(fn (int $id) => \App\Jobs\Tunneling\CollectServerMetricsJob::dispatch($id));
        })->everyMinute()->name('tunneling.collect_server_metrics');

        // Drift detection + auto-repair on every router that has desired objects.
        $schedule->call(function (): void {
            if ($this->queueIsBacklogged()) {
                return;
            }

            DesiredNetworkObject::query()
                ->select('server_id')
                ->distinct()
                ->pluck('server_id')
                ->each(fn (int $id) => \App\Jobs\Tunneling\ReconcileServerJob::dispatch($id));
        })->everyFiveMinutes()->name('tunneling.reconcile');

        // Hourly metric rollups + retention pruning (MySQL instead of a TSDB).
        $schedule->command('tunnels:rollup-metrics')
            ->hourlyAt(4)
            ->withoutOverlapping();

        $schedule->command('tunnels:prune-metrics')
            ->dailyAt('03:30')
            ->withoutOverlapping();

        // Agent health evaluation from received probe reports (marks down agents,
        // triggers DPI switches and load-balancer reweighting).
        $schedule->call(function (): void {
            if ($this->queueIsBacklogged()) {
                return;
            }

            TunnelGroup::query()
                ->whereIn('status', ['active', 'degraded', 'down'])
                ->pluck('id')
                ->each(fn (int $id) => \App\Jobs\Tunneling\EvaluateTunnelGroupJob::dispatch($id));
        })->everyMinute()->name('tunneling.evaluate_groups');

        // Capacity alarms (CPU/conntrack/throughput near cap) via Telegram.
        $schedule->call(function (): void {
            app(CapacityAlarmService::class)->evaluate();
        })->everyFiveMinutes()->name('tunneling.capacity_alarms');
    }

    protected function queueIsBacklogged(): bool
    {
        return TunnelQueueHealth::pendingJobsCount()
            > (int) config('tunneling.queue.skip_low_priority_above', 30);
    }
}
