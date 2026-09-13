<?php

namespace Modules\Tunneling\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Retention pruning for the MySQL metric tables (shared hosting — keep them
 * small): raw samples after 48h, server samples after 72h, rollups after 60d.
 * Deletes in chunks to avoid long locks.
 */
class TunnelMetricsPruneCommand extends Command
{
    protected $signature = 'tunnels:prune-metrics';

    protected $description = 'Prune old tunnel/server metric samples and rollups';

    public function handle(): int
    {
        $deleted = 0;

        $deleted += $this->pruneChunked(
            'tunnel_metric_samples',
            'sampled_at',
            now()->subHours((int) config('tunneling.metrics.raw_retention_hours', 48)),
        );

        $deleted += $this->pruneChunked(
            'server_metric_samples',
            'sampled_at',
            now()->subHours((int) config('tunneling.metrics.server_raw_retention_hours', 72)),
        );

        $deleted += $this->pruneChunked(
            'tunnel_metric_rollups',
            'bucket_at',
            now()->subDays((int) config('tunneling.metrics.rollup_retention_days', 60)),
        );

        $deleted += $this->pruneChunked('tunnel_group_events', 'created_at', now()->subDays(30));

        $this->info("Pruned {$deleted} row(s).");

        return self::SUCCESS;
    }

    protected function pruneChunked(string $table, string $column, \DateTimeInterface $before): int
    {
        $total = 0;

        do {
            $batch = DB::table($table)->where($column, '<', $before)->limit(5000)->delete();
            $total += $batch;
        } while ($batch > 0);

        return $total;
    }
}
