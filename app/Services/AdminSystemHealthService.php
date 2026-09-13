<?php

namespace App\Services;

use App\Support\CronDocumentation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AdminSystemHealthService
{
    public function __construct(
        protected PanelHostMonitorService $hostMonitor,
        protected DatabaseMaintenanceService $databaseMaintenance,
    ) {}

    /**
     * @return array{
     *     host: array<string, mixed>,
     *     services: list<array<string, mixed>>,
     *     cron_jobs: list<array<string, mixed>>,
     *     checked_at: string
     * }
     */
    public function snapshot(): array
    {
        try {
            return [
                'host' => $this->hostMonitor->snapshot(),
                'services' => $this->servicesHealth(),
                'cron_jobs' => $this->cronJobsHealth(),
                'checked_at' => now()->toIso8601String(),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'host' => [
                    'health' => 'warning',
                    'error' => $exception->getMessage(),
                    'checked_at' => now()->toIso8601String(),
                ],
                'services' => [],
                'cron_jobs' => [],
                'checked_at' => now()->toIso8601String(),
            ];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function servicesHealth(): array
    {
        return [
            $this->checkDatabase(),
            $this->checkCache(),
            $this->checkStorage(),
            $this->checkQueue(),
            $this->checkPhp(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkDatabase(): array
    {
        try {
            DB::connection()->select('select 1 as ok');
            $missing = $this->databaseMaintenance->missingTables();

            return [
                'key' => 'database',
                'label' => __('dashboard.health.database'),
                'status' => $missing === [] ? 'healthy' : 'warning',
                'detail' => $missing === []
                    ? __('dashboard.health.database_ok')
                    : __('dashboard.health.database_missing_tables', ['count' => count($missing)]),
            ];
        } catch (Throwable $exception) {
            return [
                'key' => 'database',
                'label' => __('dashboard.health.database'),
                'status' => 'critical',
                'detail' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkCache(): array
    {
        try {
            $token = 'health-'.uniqid('', true);
            Cache::put($token, '1', 30);
            $ok = Cache::pull($token) === '1';

            return [
                'key' => 'cache',
                'label' => __('dashboard.health.cache'),
                'status' => $ok ? 'healthy' : 'critical',
                'detail' => $ok ? __('dashboard.health.cache_ok') : __('dashboard.health.cache_failed'),
            ];
        } catch (Throwable $exception) {
            return [
                'key' => 'cache',
                'label' => __('dashboard.health.cache'),
                'status' => 'critical',
                'detail' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkStorage(): array
    {
        $paths = [
            storage_path(),
            storage_path('framework/cache'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ];

        $failed = [];

        foreach ($paths as $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $failed[] = $path;
            }
        }

        return [
            'key' => 'storage',
            'label' => __('dashboard.health.storage'),
            'status' => $failed === [] ? 'healthy' : 'critical',
            'detail' => $failed === []
                ? __('dashboard.health.storage_ok')
                : __('dashboard.health.storage_failed', ['path' => $failed[0] ?? '']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkQueue(): array
    {
        $driver = (string) config('queue.default', 'sync');

        if ($driver === 'sync') {
            return [
                'key' => 'queue',
                'label' => __('dashboard.health.queue'),
                'status' => 'warning',
                'detail' => __('dashboard.health.queue_sync'),
            ];
        }

        try {
            Queue::connection()->size();
            $pending = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : null;
            $failed = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : null;

            $status = 'healthy';
            $detail = __('dashboard.health.queue_ok', ['driver' => $driver]);

            if ($failed !== null && $failed > 0) {
                $status = 'warning';
                $detail = __('dashboard.health.queue_failed_jobs', ['count' => $failed]);
            } elseif ($pending !== null && $pending > 200) {
                $status = 'warning';
                $detail = __('dashboard.health.queue_backlog', ['count' => $pending]);
            }

            return [
                'key' => 'queue',
                'label' => __('dashboard.health.queue'),
                'status' => $status,
                'detail' => $detail,
            ];
        } catch (Throwable $exception) {
            return [
                'key' => 'queue',
                'label' => __('dashboard.health.queue'),
                'status' => 'critical',
                'detail' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function checkPhp(): array
    {
        $extensions = ['pdo', 'mbstring', 'openssl', 'json', 'dom'];
        $missing = array_values(array_filter($extensions, fn (string $ext): bool => ! extension_loaded($ext)));

        return [
            'key' => 'php',
            'label' => __('dashboard.health.php'),
            'status' => $missing === [] ? 'healthy' : 'warning',
            'detail' => $missing === []
                ? 'PHP '.PHP_VERSION
                : __('dashboard.health.php_missing_ext', ['ext' => implode(', ', $missing)]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cronJobStatuses(): array
    {
        return $this->cronJobsHealth();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function cronJobsHealth(): array
    {
        $now = now();

        return collect(CronDocumentation::entries())
            ->map(function (array $job) use ($now): array {
                $lastRun = $this->lastRunForJob($job['internal']);

                $status = $this->resolveCronStatus($job, $lastRun, $now);

                return [
                    'label' => $job['label'],
                    'required' => $job['required'],
                    'cron' => $job['cron'],
                    'internal' => $job['internal'],
                    'description' => $job['description'],
                    'status' => $status,
                    'last_run_at' => $lastRun?->toIso8601String(),
                    'last_run_human' => $lastRun?->diffForHumans(),
                    'status_label' => __('dashboard.health.cron_status_'.$status),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Resolve the last run time for a documented cron entry (cache-backed).
     */
    protected function lastRunForJob(string $internal): ?Carbon
    {
        $key = $this->monitorKeyForJob($internal);
        $value = $key !== null ? Cache::get($key) : null;

        return is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : null;
    }

    protected function monitorKeyForJob(string $internal): ?string
    {
        if (str_contains($internal, 'schedule:run')) {
            return 'system_health.schedule_runner_at';
        }

        return match (true) {
            str_contains($internal, 'sync:usage') => 'system_health.job.sync_usage_at',
            str_contains($internal, 'accounts:check-expiry') => 'system_health.job.accounts_expiry_at',
            str_contains($internal, 'alerts:dispatch') => 'system_health.job.alerts_at',
            str_contains($internal, 'backup:database') => 'system_health.job.backup_at',
            str_contains($internal, 'reports:daily-rollup') => 'system_health.job.reports_at',
            str_contains($internal, 'tickets:auto-close') => 'system_health.job.tickets_at',
            str_contains($internal, 'queue:work') => 'system_health.queue_worker_at',
            default => null,
        };
    }

    /**
     * @param  array{required: bool, internal: string, cron: string}  $job
     */
    protected function resolveCronStatus(array $job, ?\Illuminate\Support\Carbon $lastRun, \Illuminate\Support\Carbon $now): string
    {
        $internal = $job['internal'];

        if (str_contains($internal, 'queue:work')) {
            $last = Cache::get('system_health.queue_worker_at');

            if (! is_numeric($last)) {
                return 'warning';
            }

            return ((int) $last) >= $now->copy()->subMinutes(10)->timestamp ? 'healthy' : 'warning';
        }

        if (str_contains($internal, 'schedule:run')) {
            if ($lastRun === null) {
                return 'critical';
            }

            return $lastRun->greaterThan($now->copy()->subMinutes(3)) ? 'healthy' : 'critical';
        }

        if ($lastRun === null) {
            return $job['required'] ? 'warning' : 'unknown';
        }

        $maxAgeMinutes = $this->expectedMaxAgeMinutes($job['cron']);

        return $lastRun->greaterThan($now->copy()->subMinutes($maxAgeMinutes)) ? 'healthy' : 'warning';
    }

    protected function expectedMaxAgeMinutes(string $cronExpression): int
    {
        if ($cronExpression === '* * * * *' || str_starts_with($cronExpression, '*/')) {
            $step = 1;
            if (preg_match('/^\*\/(\d+)/', $cronExpression, $matches) === 1) {
                $step = max(1, (int) $matches[1]);
            }

            return ($step * 3) + 2;
        }

        if (str_contains($cronExpression, '0 * * * *')) {
            return 130;
        }

        if (str_contains($cronExpression, '0 0') || str_contains($cronExpression, '0 2') || str_contains($cronExpression, '0 3')) {
            return 60 * 30;
        }

        return 180;
    }
}
