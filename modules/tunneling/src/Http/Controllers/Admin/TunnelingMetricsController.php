<?php

namespace Modules\Tunneling\Http\Controllers\Admin;

use AppHttpControllersController;
use App\Http\Controllers\Controller;
use App\Models\ServerMetricSample;
use App\Models\TunnelGroup;
use App\Models\TunnelMetricSample;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON endpoints polled by the Chart.js widgets (shared hosting: HTTP polling
 * instead of websockets). Raw samples are bucketed per minute to keep payloads
 * small.
 */
class TunnelingMetricsController extends Controller
{
    /** Per-agent latency/loss/score/throughput series for a tunnel group. */
    public function group(Request $request, TunnelGroup $group): JsonResponse
    {
        $hours = min(48, max(1, (int) $request->query('hours', 3)));
        $since = now()->subHours($hours);

        $group->load('exits.server', 'exits.agents');

        $agents = $group->exits->flatMap->agents;

        $samples = TunnelMetricSample::query()
            ->whereIn('tunnel_agent_id', $agents->pluck('id'))
            ->where('sampled_at', '>=', $since)
            ->orderBy('sampled_at')
            ->get()
            ->groupBy('tunnel_agent_id');

        $series = [];

        foreach ($group->exits as $exit) {
            foreach ($exit->agents as $agent) {
                $buckets = ($samples->get($agent->id) ?? collect())
                    ->groupBy(fn (TunnelMetricSample $s): string => $s->sampled_at->format('Y-m-d H:i'));

                $points = $buckets->map(fn ($bucket, string $minute): array => [
                    't' => $minute,
                    'latency' => round((float) $bucket->avg('latency_ms'), 1),
                    'loss' => round((float) $bucket->avg('loss_pct'), 1),
                    'score' => round((float) $bucket->avg('score'), 1),
                    'rx_mbps' => round((float) $bucket->avg('rx_bps') / 1_000_000, 2),
                    'tx_mbps' => round((float) $bucket->avg('tx_bps') / 1_000_000, 2),
                ])->values();

                $series[] = [
                    'agent_id' => $agent->id,
                    'label' => $agent->iran_interface.' ('.($exit->server?->name ?? '?').')',
                    'kind' => $agent->kind->label(),
                    'health' => $agent->health->value,
                    'score' => $agent->quality_score,
                    'weight' => $agent->weight,
                    'points' => $points,
                ];
            }
        }

        return response()->json([
            'status' => $group->status->value,
            'status_message' => $group->status_message,
            'mtu' => $group->effectiveMtu(),
            'series' => $series,
        ]);
    }

    /** Light status payload for fast polling (badges, agent table refresh). */
    public function groupStatus(TunnelGroup $group): JsonResponse
    {
        $group->load('exits.server', 'exits.agents');

        return response()->json([
            'status' => $group->status->value,
            'status_label' => $group->status->label(),
            'status_message' => $group->status_message,
            'last_applied_at' => $group->last_applied_at?->diffForHumans(),
            'agents' => $group->exits->flatMap(fn ($exit) => $exit->agents->map(fn ($agent): array => [
                'id' => $agent->id,
                'exit' => $exit->server?->name,
                'interface' => $agent->iran_interface,
                'kind' => $agent->kind->label(),
                'health' => $agent->health->value,
                'health_class' => $agent->health->cssClass(),
                'score' => $agent->quality_score,
                'weight' => $agent->weight,
                'enabled' => $agent->is_enabled,
                'udp_port' => $agent->udp_port,
            ]))->values(),
        ]);
    }

    /** CPU/RAM/conntrack/throughput series for the group's routers. */
    public function servers(Request $request, TunnelGroup $group): JsonResponse
    {
        $hours = min(48, max(1, (int) $request->query('hours', 3)));
        $since = now()->subHours($hours);

        $group->load('iranServer', 'exits.server');

        $servers = collect([$group->iranServer])
            ->merge($group->exits->map->server)
            ->filter()
            ->unique('id');

        $samples = ServerMetricSample::query()
            ->whereIn('server_id', $servers->pluck('id'))
            ->where('sampled_at', '>=', $since)
            ->orderBy('sampled_at')
            ->get()
            ->groupBy('server_id');

        $series = $servers->map(function ($server) use ($samples): array {
            $points = ($samples->get($server->id) ?? collect())->map(fn (ServerMetricSample $s): array => [
                't' => $s->sampled_at->format('Y-m-d H:i'),
                'cpu' => $s->cpu_pct,
                'ram' => $s->ram_pct,
                'conntrack' => $s->conntrack,
                'mbps' => round(($s->rx_bps + $s->tx_bps) / 1_000_000, 2),
            ])->values();

            return [
                'server_id' => $server->id,
                'label' => $server->name,
                'cpu_now' => $server->last_cpu_pct,
                'conntrack_now' => $server->last_conntrack,
                'conntrack_max' => $server->last_conntrack_max,
                'points' => $points,
            ];
        })->values();

        return response()->json(['series' => $series]);
    }
}
