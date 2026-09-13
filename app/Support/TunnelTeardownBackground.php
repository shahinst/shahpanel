<?php

namespace App\Support;

use App\Services\Tunneling\TunnelTeardownService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs tunnel-group teardown after the HTTP response (works without exec/queue worker).
 */
class TunnelTeardownBackground
{
    public static function cacheKey(int $groupId): string
    {
        return "tunneling:teardown:group:{$groupId}";
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function state(int $groupId): ?array
    {
        $state = Cache::get(self::cacheKey($groupId));

        return is_array($state) ? $state : null;
    }

    public static function isRunning(int $groupId): bool
    {
        $state = self::state($groupId);

        if (($state['status'] ?? '') !== 'running') {
            return false;
        }

        if (TunnelBackgroundRunner::runningLockExpired($state)) {
            Cache::forget(self::cacheKey($groupId));

            return false;
        }

        return true;
    }

    public static function dispatch(int $groupId): bool
    {
        if (self::isRunning($groupId)) {
            return false;
        }

        if (TunnelConfigureBackground::isRunning($groupId) || TunnelWipeBackground::isRunning($groupId)) {
            return false;
        }

        Cache::put(self::cacheKey($groupId), [
            'status' => 'running',
            'started_at' => now()->toIso8601String(),
        ], now()->addHours(2));

        TunnelBackgroundRunner::defer(static function () use ($groupId): void {
            @set_time_limit(0);
            Log::info('tunneling: teardown deferred start', ['group_id' => $groupId]);

            try {
                $result = app(TunnelTeardownService::class)->run($groupId);
                self::markDone($groupId, $result);
            } catch (Throwable $e) {
                report($e);
                self::markFailed($groupId, $e->getMessage());
            }
        });

        return true;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public static function markDone(int $groupId, array $result): void
    {
        Cache::put(self::cacheKey($groupId), [
            'status' => 'done',
            'router_errors' => (int) ($result['router_errors'] ?? 0),
            'finished_at' => now()->toIso8601String(),
        ], now()->addHours(2));
    }

    public static function markFailed(int $groupId, string $message): void
    {
        Cache::put(self::cacheKey($groupId), [
            'status' => 'failed',
            'message' => $message,
            'finished_at' => now()->toIso8601String(),
        ], now()->addHours(2));
    }
}
