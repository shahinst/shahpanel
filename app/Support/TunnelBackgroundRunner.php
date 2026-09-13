<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Runs long tunneling work after the HTTP response is sent (no exec/shell needed).
 */
final class TunnelBackgroundRunner
{
    /** @var list<callable(): void> */
    private static array $tasks = [];

    private static bool $registered = false;

    /**
     * Queue work to run after Laravel sends the response to the browser.
     */
    public static function defer(callable $task): void
    {
        self::$tasks[] = $task;

        if (self::$registered) {
            return;
        }

        self::$registered = true;

        register_shutdown_function(static function (): void {
            if (self::$tasks === []) {
                return;
            }

            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }

            ignore_user_abort(true);
            @set_time_limit(0);

            foreach (self::$tasks as $task) {
                try {
                    $task();
                } catch (\Throwable $e) {
                    report($e);
                    Log::error('tunneling: deferred task failed', ['error' => $e->getMessage()]);
                }
            }

            self::$tasks = [];
        });
    }

    /**
     * When running inside an HTTP request with sync fallback enabled, defer; otherwise run now.
     */
    public static function deferOrRunNow(callable $task): void
    {
        if (! app()->runningInConsole() && TunnelQueueHealth::shouldRunSynchronously()) {
            self::defer($task);

            return;
        }

        @set_time_limit(0);
        $task();
    }

    public static function runningLockExpired(?array $state, int $staleMinutes = 20): bool
    {
        if (($state['status'] ?? '') !== 'running') {
            return true;
        }

        $started = $state['started_at'] ?? null;

        if (! is_string($started) || $started === '') {
            return false;
        }

        try {
            return Carbon::parse($started)->lt(now()->subMinutes($staleMinutes));
        } catch (\Throwable) {
            return false;
        }
    }
}
