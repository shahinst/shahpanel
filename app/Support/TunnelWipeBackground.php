<?php

namespace App\Support;

use App\Models\TunnelGroup;
use App\Services\Tunneling\TunnelGroupOrchestrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs tunnel-group router wipes after the HTTP response (works without exec/queue worker).
 */
class TunnelWipeBackground
{
    public static function cacheKey(int $groupId): string
    {
        return "tunneling:wipe:group:{$groupId}";
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

    public static function dispatch(int $groupId, bool $apply): bool
    {
        if (self::isRunning($groupId)) {
            return false;
        }

        Cache::put(self::cacheKey($groupId), [
            'status' => 'running',
            'apply' => $apply,
            'started_at' => now()->toIso8601String(),
        ], now()->addHours(2));

        TunnelBackgroundRunner::defer(static function () use ($groupId, $apply): void {
            @set_time_limit(0);
            Log::info('tunneling: wipe deferred start', ['group_id' => $groupId, 'apply' => $apply]);

            try {
                $group = TunnelGroup::query()->find($groupId);

                if ($group === null) {
                    self::markFailed($groupId, 'group not found');

                    return;
                }

                $orchestrator = app(TunnelGroupOrchestrator::class);
                $result = $apply
                    ? $orchestrator->wipeAndApplySync($group)
                    : $orchestrator->wipeRoutersSync($group);

                self::markDone($groupId, array_merge($result, ['apply' => $apply]));
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
            'apply' => (bool) ($result['apply'] ?? false),
            'removed' => $result['removed'] ?? null,
            'errors' => $result['errors'] ?? 0,
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
