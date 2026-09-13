<?php

namespace Modules\Tunneling\Console;

use App\Models\TunnelMetricRollup;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates raw tunnel_metric_samples into hourly tunnel_metric_rollups.
 * Runs hourly; re-running the same bucket is harmless (upsert).
 */
class TunnelMetricsRollupCommand extends Command
{
    protected $signature = 'tunnels:rollup-metrics {--hours=3 : How many past hours to (re)aggregate}';

    protected $description = 'Aggregate raw tunnel metric samples into hourly rollups';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $from = now()->subHours($hours)->startOfHour();

        $rows = DB::table('tunnel_metric_samples')
            ->selectRaw(implode(', ', [
                'tunnel_agent_id',
                "DATE_FORMAT(sampled_at, '%Y-%m-%d %H:00:00') as bucket",
                'COUNT(*) as samples',
                'SUM(up) as up_samples',
                'AVG(latency_ms) as latency_avg',
                'MAX(latency_ms) as latency_max',
                'AVG(jitter_ms) as jitter_avg',
                'AVG(loss_pct) as loss_avg',
                'AVG(rx_bps) as rx_avg',
                'AVG(tx_bps) as tx_avg',
                'MAX(rx_bps) as rx_max',
                'MAX(tx_bps) as tx_max',
                'AVG(score) as score_avg',
            ]))
            ->where('sampled_at', '>=', $from)
            ->groupBy('tunnel_agent_id', 'bucket')
            ->get();

        foreach ($rows as $row) {
            TunnelMetricRollup::query()->updateOrCreate(
                [
                    'tunnel_agent_id' => $row->tunnel_agent_id,
                    'bucket_at' => Carbon::parse($row->bucket),
                ],
                [
                    'samples' => (int) $row->samples,
                    'up_samples' => (int) $row->up_samples,
                    'latency_avg_ms' => $row->latency_avg !== null ? (int) round($row->latency_avg) : null,
                    'latency_max_ms' => $row->latency_max !== null ? (int) $row->latency_max : null,
                    'jitter_avg_ms' => $row->jitter_avg !== null ? (int) round($row->jitter_avg) : null,
                    'loss_avg_pct' => $row->loss_avg !== null ? round((float) $row->loss_avg, 2) : null,
                    'rx_bps_avg' => (int) round((float) $row->rx_avg),
                    'tx_bps_avg' => (int) round((float) $row->tx_avg),
                    'rx_bps_max' => (int) $row->rx_max,
                    'tx_bps_max' => (int) $row->tx_max,
                    'score_avg' => $row->score_avg !== null ? round((float) $row->score_avg, 2) : null,
                ],
            );
        }

        $this->info("Rolled up {$rows->count()} bucket(s).");

        return self::SUCCESS;
    }
}
