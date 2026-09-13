<?php

namespace App\Support;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches tunneling jobs. When queue worker is inactive (or sync_on_action),
 * runs work after the HTTP response instead of silently queueing forever.
 */
final class TunnelJobDispatcher
{
    public static function dispatch(object $job): void
    {
        if (self::shouldDeferSync()) {
            TunnelBackgroundRunner::defer(static fn () => self::dispatchSync($job));

            return;
        }

        dispatch($job);
    }

    /**
     * @param  list<object>  $jobs
     */
    public static function dispatchChain(array $jobs): void
    {
        if ($jobs === []) {
            return;
        }

        if (self::shouldDeferSync()) {
            TunnelBackgroundRunner::defer(static function () use ($jobs): void {
                foreach ($jobs as $job) {
                    self::dispatchSync($job);
                }
            });

            return;
        }

        Bus::chain($jobs)->dispatch();
    }

    public static function dispatchSync(object $job): void
    {
        @set_time_limit(max(300, (int) config('tunneling.queue.job_timeout', 180) + 60));

        app(Dispatcher::class)->dispatchSync($job);
    }

    private static function shouldDeferSync(): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        if (! TunnelQueueHealth::shouldRunSynchronously()) {
            return false;
        }

        Log::info('tunneling: sync after HTTP response', [
            'sync_on_action' => config('tunneling.queue.sync_on_action'),
            'worker_healthy' => TunnelQueueHealth::workerHealthy(),
        ]);

        return true;
    }
}
