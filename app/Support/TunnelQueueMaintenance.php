<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Helpers for the tunneling database queue (purge stale background jobs, etc.).
 */
final class TunnelQueueMaintenance
{
    /** Job classes that cron may pile up; safe to bulk-delete when backed up. */
    public const BACKGROUND_JOB_CLASSES = [
        'CollectServerMetricsJob',
        'EvaluateTunnelGroupJob',
        'ReconcileServerJob',
    ];

    /**
     * @return array<string, int>
     */
    public static function pendingByClass(): array
    {
        if (! Schema::hasTable('jobs')) {
            return [];
        }

        $counts = [];

        foreach (DB::table('jobs')->select('payload')->cursor() as $row) {
            $class = self::classFromPayload((string) $row->payload) ?? 'unknown';
            $counts[$class] = ($counts[$class] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /** Delete pending jobs whose serialized payload contains $className. */
    public static function purgePendingClass(string $className): int
    {
        if (! Schema::hasTable('jobs')) {
            return 0;
        }

        return DB::table('jobs')
            ->where('payload', 'like', '%'.$className.'%')
            ->delete();
    }

    /** @return int Total rows removed */
    public static function purgeBackgroundJobs(): int
    {
        $total = 0;

        foreach (self::BACKGROUND_JOB_CLASSES as $class) {
            $total += self::purgePendingClass($class);
        }

        return $total;
    }

    public static function purgeFailedBackgroundJobs(): int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return 0;
        }

        $total = 0;

        foreach (self::BACKGROUND_JOB_CLASSES as $class) {
            $total += DB::table('failed_jobs')
                ->where('payload', 'like', '%'.$class.'%')
                ->delete();
        }

        return $total;
    }

    public static function classFromPayload(string $payload): ?string
    {
        if (preg_match('/"displayName":\s*"([^"]+)"/', $payload, $matches) === 1) {
            return class_basename($matches[1]);
        }

        return null;
    }
}
