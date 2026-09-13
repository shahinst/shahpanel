<?php

namespace App\Jobs\Tunneling;

use App\Enums\AgentHealth;
use App\Enums\TunnelGroupStatus;
use App\Models\TunnelAgent;
use App\Models\TunnelGroup;
use App\Models\TunnelGroupEvent;
use App\Models\TunnelMetricSample;
use App\Services\Tunneling\LoadBalancerService;
use App\Services\Tunneling\QualityScoreService;
use App\Services\Tunneling\TelegramAlertService;
use App\Services\Tunneling\TunnelGroupOrchestrator;
use App\Services\Tunneling\TunnelHealthProbeService;
use App\Services\Tunneling\TunnelKindFailoverService;
use App\Services\Tunneling\TunnelSwitchService;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Per-minute self-healing pass for one group, driven by the probe samples the
 * routers POSTed in:
 *
 *   - health: up / degraded (throttled) / down (missed reports)
 *   - quality score + healthy-throughput baseline update
 *   - automatic DPI switches after N consecutive bad cycles
 *   - dynamic re-weighting; re-apply when the LB layout changed
 *   - group status + Telegram alerts on down/degraded transitions
 */
class EvaluateTunnelGroupJob extends BaseTunnelingJob implements ShouldBeUnique
{
    public int $uniqueFor = 120;

    public function __construct(public int $groupId)
    {
        parent::__construct();
        $this->onQueue((string) config('tunneling.queue.low_name', 'tunneling-low'));
    }

    public function uniqueId(): string
    {
        return 'evaluate:'.$this->groupId;
    }

    public function handle(
        QualityScoreService $scores,
        TunnelSwitchService $switcher,
        LoadBalancerService $loadBalancer,
        TelegramAlertService $telegram,
        TunnelGroupOrchestrator $orchestrator,
        TunnelKindFailoverService $kindFailover,
    ): void {
        $group = TunnelGroup::query()->with('exits.agents', 'iranServer')->find($this->groupId);

        if ($group === null) {
            return;
        }

        $healthChanged = false;
        $switched = false;

        foreach ($group->exits as $exit) {
            foreach ($exit->agents as $agent) {
                $healthChanged = $this->evaluateAgent($group, $agent, $scores, $switcher, $switched) || $healthChanged;
            }
        }

        // Active/passive failover between selected tunnel kinds (priority mode)
        // — re-syncs and re-applies internally when it flips the active kind,
        // so skip the generic reapply below in that case.
        if ($kindFailover->evaluate($group)) {
            $this->updateGroupStatus($group, $telegram);

            return;
        }

        $reweighed = $loadBalancer->reweigh($group);

        if ($switched || $healthChanged || $reweighed) {
            $orchestrator->reapply($group, $switched ? 'dpi-switch' : 'rebalance');
        }

        $this->updateGroupStatus($group, $telegram);
    }

    protected function evaluateAgent(
        TunnelGroup $group,
        TunnelAgent $agent,
        QualityScoreService $scores,
        TunnelSwitchService $switcher,
        bool &$switched,
    ): bool {
        if (! $agent->is_enabled) {
            return false;
        }

        $window = now()->subSeconds(max(60, 6 * (int) config('tunneling.metrics.probe_interval', 10)));

        $samples = TunnelMetricSample::query()
            ->where('tunnel_agent_id', $agent->id)
            ->where('sampled_at', '>=', $window)
            ->orderByDesc('sampled_at')
            ->limit(12)
            ->get();

        $previousHealth = $agent->health;

        if ($samples->isEmpty()) {
            // No probe reports yet — check RouterOS interface state before declaring down.
            $group->loadMissing('iranServer', 'exits.server');
            $iran = $group->iranServer;
            $foreign = $agent->exit?->server;
            $probe = app(TunnelHealthProbeService::class);

            if ($iran !== null
                && $probe->interfaceIsRunning($iran, $agent->iran_interface)
                && ($foreign === null || $probe->interfaceIsRunning($foreign, $agent->foreign_interface))
            ) {
                if ($agent->health === AgentHealth::Down) {
                    $agent->forceFill(['health' => AgentHealth::Degraded])->save();

                    return true;
                }

                return false;
            }

            // No reports: tolerate a startup/installation gap before declaring down.
            $missedLimit = (int) config('tunneling.scoring.down_after_missed_reports', 3);
            $graceSeconds = $missedLimit * max(60, (int) config('tunneling.metrics.probe_interval', 10) * 6);
            $reference = $agent->last_seen_up_at ?? $agent->created_at;

            if ($reference !== null && $reference->lt(now()->subSeconds($graceSeconds))) {
                $agent->forceFill([
                    'health' => AgentHealth::Down,
                    'fail_count' => $agent->fail_count + 1,
                ])->save();
            }

            return $agent->health !== $previousHealth;
        }

        $upRatio = $samples->where('up', true)->count() / $samples->count();
        $avgLoss = (float) $samples->avg('loss_pct');
        $avgScore = (float) $samples->avg('score');
        $maxThroughput = (int) max($samples->max('rx_bps'), $samples->max('tx_bps'));

        // Track healthy baseline (rolling max with mild decay).
        if ($upRatio >= 0.9 && $avgLoss < 5.0 && $maxThroughput > 0) {
            $agent->baseline_rx_bps = max((int) ($agent->baseline_rx_bps ?? 0), (int) $samples->max('rx_bps'));
            $agent->baseline_tx_bps = max((int) ($agent->baseline_tx_bps ?? 0), (int) $samples->max('tx_bps'));
        }

        $baseline = max((int) ($agent->baseline_rx_bps ?? 0), (int) ($agent->baseline_tx_bps ?? 0));
        $ratio = ($baseline > 0 && $maxThroughput > 0) ? $maxThroughput / $baseline : null;
        $throttled = $upRatio >= 0.5 && $scores->isThrottled($ratio);

        $health = match (true) {
            $upRatio < 0.3 => AgentHealth::Down,
            $throttled || $upRatio < 0.9 || $avgLoss >= 10 => AgentHealth::Degraded,
            default => AgentHealth::Up,
        };

        $agent->quality_score = round($avgScore, 2);
        $agent->health = $health;

        if ($health === AgentHealth::Up) {
            $agent->fail_count = 0;
            $agent->last_seen_up_at = now();
        } else {
            $agent->fail_count = $agent->fail_count + 1;
        }

        $agent->save();

        // Automatic evasion after N consecutive bad cycles.
        $threshold = (int) config('tunneling.scoring.switch_after_failures', 3);

        if ($health !== AgentHealth::Up
            && $agent->fail_count >= $threshold
            && ($group->auto_switch_l2tp || $group->auto_switch_kind)) {
            $reason = $health === AgentHealth::Down ? 'down' : ($throttled ? 'throttle' : 'loss');

            if ($switcher->switchAgent($group, $agent, $reason) !== null) {
                $switched = true;
            }
        }

        return $agent->health !== $previousHealth;
    }

    protected function updateGroupStatus(TunnelGroup $group, TelegramAlertService $telegram): void
    {
        $agents = $group->exits->flatMap->agents->filter->is_enabled;

        if ($agents->isEmpty()) {
            return;
        }

        $up = $agents->where('health', AgentHealth::Up)->count();
        $down = $agents->where('health', AgentHealth::Down)->count();

        $newStatus = match (true) {
            $down === $agents->count() => TunnelGroupStatus::Down,
            $up === $agents->count() => TunnelGroupStatus::Active,
            default => TunnelGroupStatus::Degraded,
        };

        if (! in_array($group->status, [TunnelGroupStatus::Active, TunnelGroupStatus::Degraded, TunnelGroupStatus::Down], true)) {
            return; // applying/removing/draft — don't fight lifecycle states.
        }

        if ($group->status === $newStatus) {
            return;
        }

        $group->forceFill(['status' => $newStatus])->save();

        TunnelGroupEvent::record(
            'status_changed',
            "وضعیت گروه «{$group->name}»: {$newStatus->value} ({$up} از {$agents->count()} تانل سالم).",
            ['tunnel_group_id' => $group->id],
            $newStatus === TunnelGroupStatus::Active ? 'ok' : ($newStatus === TunnelGroupStatus::Down ? 'error' : 'warning'),
        );

        if ($newStatus !== TunnelGroupStatus::Active) {
            $telegram->send(
                "⚠️ <b>تانلینگ</b>\nگروه «{$group->name}» {$newStatus->value} شد — {$up}/{$agents->count()} تانل سالم.",
                "group-status:{$group->id}:{$newStatus->value}",
            );
        } else {
            $telegram->send(
                "✅ <b>تانلینگ</b>\nگروه «{$group->name}» به حالت سالم برگشت.",
                "group-status:{$group->id}:active",
            );
        }
    }
}
