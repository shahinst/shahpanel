<?php

namespace App\Support;

use App\Models\TunnelGroup;
use App\Services\Tunneling\TunnelGroupOrchestrator;
use App\Services\Tunneling\TunnelTeardownService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs «کانفیگ و تست» after the HTTP response (works without exec/queue worker).
 */
class TunnelConfigureBackground
{
    public static function cacheKey(int $groupId): string
    {
        return "tunneling:configure:group:{$groupId}";
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
        if (self::isRunning($groupId) || TunnelWipeBackground::isRunning($groupId) || TunnelTeardownBackground::isRunning($groupId)) {
            return false;
        }

        Cache::put(self::cacheKey($groupId), [
            'status' => 'running',
            'step' => 'starting',
            'started_at' => now()->toIso8601String(),
        ], now()->addHours(2));

        TunnelBackgroundRunner::defer(static function () use ($groupId): void {
            @set_time_limit(0);
            Log::info('tunneling: configure deferred start', ['group_id' => $groupId]);

            try {
                $group = TunnelGroup::query()->find($groupId);

                if ($group === null) {
                    self::markFailed($groupId, 'group not found');

                    return;
                }

                app(TunnelGroupOrchestrator::class)->configureAndTest($group, sync: true, progressGroupId: $groupId);

                $group->refresh();

                self::markDone($groupId, [
                    'group_status' => $group->status->value,
                    'failed' => $group->status->value === 'error' ? 1 : 0,
                    'configure_result' => $group->meta['configure_result'] ?? null,
                ]);
            } catch (Throwable $e) {
                report($e);
                self::markFailed($groupId, $e->getMessage());
            }
        });

        return true;
    }

    public static function setStep(int $groupId, string $step): void
    {
        $state = self::state($groupId) ?? [];
        $state['status'] = 'running';
        $state['step'] = $step;

        Cache::put(self::cacheKey($groupId), $state, now()->addHours(2));
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public static function markDone(int $groupId, array $result): void
    {
        Cache::put(self::cacheKey($groupId), [
            'status' => 'done',
            'step' => 'finished',
            'group_status' => $result['group_status'] ?? null,
            'failed' => $result['failed'] ?? 0,
            'configure_result' => $result['configure_result'] ?? null,
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
