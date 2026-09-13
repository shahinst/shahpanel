<?php

namespace App\Jobs\Tunneling;

use App\Models\Server;
use App\Models\ServerMetricSample;
use App\Services\MikrotikService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Samples router capacity (CPU/RAM/conntrack/WAN throughput) into
 * server_metric_samples and denormalizes the latest values onto the server row
 * for cheap dashboard reads. Throughput is derived from interface byte
 * counters of the previous sample.
 *
 * Runs on the low-priority queue; at most one pending job per server.
 */
class CollectServerMetricsJob extends BaseTunnelingJob implements ShouldBeUnique
{
    public int $uniqueFor = 180;

    public int $timeout = 45;

    public function __construct(public int $serverId)
    {
        parent::__construct();
        $this->onQueue((string) config('tunneling.queue.low_name', 'tunneling-low'));
    }

    public function uniqueId(): string
    {
        return 'metrics:'.$this->serverId;
    }

    public function handle(MikrotikService $mikrotik): void
    {
        $server = Server::query()->find($this->serverId);

        if ($server === null || ! $server->isMikrotik() || ! $server->is_active) {
            return;
        }

        try {
            $resource = $mikrotik->queryRouterForMetrics($server, '/system/resource/print')[0] ?? [];
            $cores = $mikrotik->queryRouterForMetrics($server, '/system/resource/cpu/print');
        } catch (Throwable $e) {
            Log::warning('tunneling: metrics collection failed', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $conntrack = $this->readConntrack($mikrotik, $server);
        [$rxBps, $txBps, $counters] = $this->readThroughput($mikrotik, $server);

        $cpuPct = isset($resource['cpu-load']) ? (float) $resource['cpu-load'] : null;
        $ramPct = $this->ramPct($resource);

        $perCore = collect($cores)
            ->map(fn (array $core): array => [
                'cpu' => (string) ($core['cpu'] ?? ''),
                'load' => (int) ($core['load'] ?? 0),
            ])
            ->values()
            ->all();

        ServerMetricSample::create([
            'server_id' => $server->id,
            'sampled_at' => now(),
            'cpu_pct' => $cpuPct,
            'ram_pct' => $ramPct,
            'conntrack' => $conntrack['count'],
            'conntrack_max' => $conntrack['max'],
            'rx_bps' => $rxBps,
            'tx_bps' => $txBps,
            'per_core' => ['cores' => $perCore, '_counters' => $counters],
        ]);

        $server->forceFill([
            'last_cpu_pct' => $cpuPct,
            'last_throughput_bps' => $rxBps + $txBps,
            'last_conntrack' => $conntrack['count'],
            'last_conntrack_max' => $conntrack['max'],
            'metrics_sampled_at' => now(),
        ])->save();
    }

    /**
     * @return array{count: ?int, max: ?int}
     */
    protected function readConntrack(MikrotikService $mikrotik, Server $server): array
    {
        try {
            $tracking = $mikrotik->queryRouterForMetrics($server, '/ip/firewall/connection/tracking/print')[0] ?? [];

            return [
                'count' => isset($tracking['total-entries']) ? (int) $tracking['total-entries'] : null,
                'max' => isset($tracking['max-entries']) ? (int) $tracking['max-entries'] : null,
            ];
        } catch (Throwable) {
            return ['count' => null, 'max' => null];
        }
    }

    /**
     * Sum rx/tx byte counters over physical/WAN-ish interfaces, then derive
     * bps from the previous sample's counters.
     *
     * @return array{0: int, 1: int, 2: array{rx: int, tx: int, at: int}}
     */
    protected function readThroughput(MikrotikService $mikrotik, Server $server): array
    {
        $rxTotal = 0;
        $txTotal = 0;

        try {
            foreach ($mikrotik->queryRouterForMetrics($server, '/interface/print') as $iface) {
                if (($iface['type'] ?? '') !== 'ether') {
                    continue;
                }

                $rxTotal += (int) ($iface['rx-byte'] ?? 0);
                $txTotal += (int) ($iface['tx-byte'] ?? 0);
            }
        } catch (Throwable) {
            return [0, 0, ['rx' => 0, 'tx' => 0, 'at' => now()->timestamp]];
        }

        $counters = ['rx' => $rxTotal, 'tx' => $txTotal, 'at' => now()->timestamp];

        $previous = ServerMetricSample::query()
            ->where('server_id', $server->id)
            ->orderByDesc('sampled_at')
            ->first();

        $prevCounters = $previous?->per_core['_counters'] ?? null;

        if (! is_array($prevCounters) || ($prevCounters['at'] ?? 0) <= 0) {
            return [0, 0, $counters];
        }

        $elapsed = max(1, $counters['at'] - (int) $prevCounters['at']);
        $rxDelta = max(0, $rxTotal - (int) ($prevCounters['rx'] ?? 0));
        $txDelta = max(0, $txTotal - (int) ($prevCounters['tx'] ?? 0));

        return [
            (int) ($rxDelta * 8 / $elapsed),
            (int) ($txDelta * 8 / $elapsed),
            $counters,
        ];
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    protected function ramPct(array $resource): ?float
    {
        $total = (int) ($resource['total-memory'] ?? 0);
        $free = (int) ($resource['free-memory'] ?? 0);

        if ($total <= 0) {
            return null;
        }

        return round((($total - $free) / $total) * 100, 2);
    }
}
