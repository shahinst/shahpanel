<?php

namespace Modules\Tunneling\Http\Controllers;

use App\Http\Controllers\Controller;

use AppHttpControllersController;
use App\Models\RouterScript;
use App\Models\TunnelAgent;
use App\Models\TunnelMetricSample;
use App\Services\Tunneling\QualityScoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives probe reports POSTed by the on-router vpnl-probe script every 10s.
 * Payload (form-encoded):
 *   token  per-server secret issued at script install
 *   server server id
 *   data   "agentId,received,sent,rxBytes,txBytes;…"
 *
 * Only the Iran-side report creates metric samples (single source of truth);
 * exit-side reports just refresh liveness.
 */
class TunnelReportController extends Controller
{
    public function store(Request $request, QualityScoreService $scores): JsonResponse
    {
        $serverId = (int) $request->input('server');
        $token = (string) $request->input('token');

        $script = RouterScript::query()
            ->where('server_id', $serverId)
            ->where('name', 'vpnl-probe')
            ->first();

        if ($script === null || $token === '' || ! hash_equals($script->report_token, $token)) {
            return response()->json(['ok' => false], 403);
        }

        $script->forceFill(['last_report_at' => now()])->save();

        $agents = TunnelAgent::query()
            ->with('group')
            ->whereIn('id', $this->agentIds((string) $request->input('data', '')))
            ->get()
            ->keyBy('id');

        foreach ($this->rows((string) $request->input('data', '')) as $row) {
            $agent = $agents->get($row['agent_id']);

            if ($agent === null || $agent->group === null) {
                continue;
            }

            $isIranSide = (int) $agent->group->iran_server_id === $serverId;

            if (! $isIranSide) {
                continue;
            }

            $this->ingest($agent, $row, $scores);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array{agent_id: int, received: int, sent: int, rx: int, tx: int}  $row
     */
    protected function ingest(TunnelAgent $agent, array $row, QualityScoreService $scores): void
    {
        $lossPct = $row['sent'] > 0
            ? round((1 - $row['received'] / $row['sent']) * 100, 1)
            : 100.0;
        $up = $row['received'] > 0;

        // bps from byte counter deltas against the previous report.
        $meta = $agent->meta ?? [];
        $previous = $meta['probe_counters'] ?? null;
        $now = now()->timestamp;
        $rxBps = 0;
        $txBps = 0;

        if (is_array($previous) && ($previous['at'] ?? 0) > 0 && $now > $previous['at']) {
            $elapsed = $now - (int) $previous['at'];

            // Counter reset (interface flap) → skip this delta.
            if ($row['rx'] >= ($previous['rx'] ?? 0) && $row['tx'] >= ($previous['tx'] ?? 0)) {
                $rxBps = (int) (($row['rx'] - $previous['rx']) * 8 / $elapsed);
                $txBps = (int) (($row['tx'] - $previous['tx']) * 8 / $elapsed);
            }
        }

        $meta['probe_counters'] = ['rx' => $row['rx'], 'tx' => $row['tx'], 'at' => $now];

        $baseline = max((int) ($agent->baseline_rx_bps ?? 0), (int) ($agent->baseline_tx_bps ?? 0));
        $current = max($rxBps, $txBps);
        $ratio = ($baseline > 0 && $current > 0) ? min(1.0, $current / $baseline) : null;

        $latency = isset($meta['last_rtt_ms']) ? (float) $meta['last_rtt_ms'] : null;

        $score = $up
            ? $scores->score($latency, null, $lossPct, $ratio)
            : 0.0;

        TunnelMetricSample::create([
            'tunnel_agent_id' => $agent->id,
            'sampled_at' => now(),
            'up' => $up,
            'latency_ms' => $latency !== null ? (int) round($latency) : null,
            'loss_pct' => $lossPct,
            'rx_bps' => $rxBps,
            'tx_bps' => $txBps,
            'score' => $score,
        ]);

        $agent->forceFill([
            'meta' => $meta,
            'last_seen_up_at' => $up ? now() : $agent->last_seen_up_at,
        ])->save();
    }

    /**
     * @return list<int>
     */
    protected function agentIds(string $data): array
    {
        return array_map(fn (array $row): int => $row['agent_id'], $this->rows($data));
    }

    /**
     * @return list<array{agent_id: int, received: int, sent: int, rx: int, tx: int}>
     */
    protected function rows(string $data): array
    {
        $rows = [];

        foreach (explode(';', trim($data)) as $chunk) {
            $parts = explode(',', trim($chunk));

            if (count($parts) < 5 || ! is_numeric($parts[0])) {
                continue;
            }

            $rows[] = [
                'agent_id' => (int) $parts[0],
                'received' => max(0, (int) $parts[1]),
                'sent' => max(1, (int) $parts[2]),
                'rx' => max(0, (int) $parts[3]),
                'tx' => max(0, (int) $parts[4]),
            ];
        }

        return $rows;
    }
}
