<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Detects whether the tunneling database queue is actually being processed.
 */
final class TunnelQueueHealth
{
    public static function workerLastRunAt(): ?Carbon
    {
        $ts = Cache::get('system_health.queue_worker_at');

        return is_numeric($ts) ? Carbon::createFromTimestamp((int) $ts) : null;
    }

    public static function workerHealthy(): bool
    {
        $last = self::workerLastRunAt();
        $staleMinutes = max(3, (int) config('tunneling.queue.worker_stale_minutes', 10));

        return $last !== null && $last->greaterThan(now()->subMinutes($staleMinutes));
    }

    public static function pendingJobsCount(): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        return (int) DB::table('jobs')
            ->whereIn('queue', self::queueNames())
            ->count();
    }

    public static function failedJobsCount(): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        return (int) DB::table('failed_jobs')->count();
    }

    /** Run apply/reconcile inline when the queue worker is missing or stale. */
    public static function shouldRunSynchronously(): bool
    {
        if (filter_var(config('tunneling.queue.sync_on_action', false), FILTER_VALIDATE_BOOL)) {
            return true;
        }

        return ! self::workerHealthy();
    }

    /**
     * @return array{
     *     healthy: bool,
     *     pending: int,
     *     failed: int,
     *     last_run: ?string,
     *     sync_fallback: bool
     * }
     */
    public static function snapshot(): array
    {
        $last = self::workerLastRunAt();

        return [
            'healthy' => self::workerHealthy(),
            'pending' => self::pendingJobsCount(),
            'failed' => self::failedJobsCount(),
            'last_run' => $last?->diffForHumans(),
            'sync_fallback' => self::shouldRunSynchronously(),
        ];
    }

    /** @return list<string> Worker priority: critical apply jobs first, background last. */
    public static function queueNames(): array
    {
        $critical = (string) config('tunneling.queue.name', 'tunneling');
        $low = (string) config('tunneling.queue.low_name', 'tunneling-low');

        return array_values(array_unique([$critical, 'default', $low]));
    }
}
