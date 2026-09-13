<?php

namespace App\Services\Tunneling;

use App\Enums\TunnelKind;
use App\Models\TunnelAgent;
use App\Models\TunnelGroup;
use App\Models\TunnelGroupEvent;

/**
 * DPI evasion switches:
 *
 *   1. L2TP ladder      l2tpv3_udp → l2tpv3_ip → l2tpv2 (and wraps around)
 *   2. Kind rotation    eoip → vxlan → gre → ipip (when auto_switch_kind)
 *   3. Port hopping     next UDP port from the candidate list for UDP kinds
 *
 * The switch only mutates the agent row (kind/port + sticky markers); the
 * caller re-provisions the group so desired objects follow.
 */
class TunnelSwitchService
{
    /** Non-L2TP rotation ladder for persistent-throttle evasion. */
    private const KIND_LADDER = [
        TunnelKind::Eoip,
        TunnelKind::Vxlan,
        TunnelKind::Gre,
        TunnelKind::Ipip,
    ];

    /**
     * Pick and apply the next evasion step for one agent. Returns a
     * human-readable description, or null when no switch is allowed.
     */
    public function switchAgent(TunnelGroup $group, TunnelAgent $agent, string $reason): ?string
    {
        $cooldown = (int) config('tunneling.scoring.switch_cooldown_secs', 300);

        if ($agent->last_switched_at !== null && $agent->last_switched_at->gt(now()->subSeconds($cooldown))) {
            return null;
        }

        $description = null;

        if ($agent->kind->isL2tp() && $group->auto_switch_l2tp) {
            $description = $this->switchL2tp($agent);
        } elseif ($group->auto_switch_kind) {
            $description = $this->switchKind($agent);
        } elseif ($agent->kind->usesUdp()) {
            $description = $this->hopPort($group, $agent);
        }

        if ($description === null) {
            return null;
        }

        $agent->forceFill([
            'last_switched_at' => now(),
            'fail_count' => 0,
            'meta' => array_merge($agent->meta ?? [], ['kind_switched' => true]),
        ])->save();

        TunnelGroupEvent::record('agent_switched', "سوییچ agent #{$agent->id}: {$description} (علت: {$reason})", [
            'tunnel_group_id' => $group->id,
            'tunnel_agent_id' => $agent->id,
        ], 'warning');

        return $description;
    }

    public function switchL2tp(TunnelAgent $agent): string
    {
        $ladder = TunnelKind::l2tpLadder();
        $index = array_search($agent->kind, $ladder, true);
        $next = $ladder[($index === false ? 0 : $index + 1) % count($ladder)];

        // l2tpv3-ip is NAT-hostile; honor the config gate.
        if ($next === TunnelKind::L2tpV3Ip && ! (bool) config('tunneling.dpi.l2tpv3_ip_enabled', false)) {
            $next = $ladder[($index === false ? 1 : $index + 2) % count($ladder)];
        }

        $from = $agent->kind->label();
        $agent->kind = $next;
        $agent->save();

        return "{$from} → {$next->label()}";
    }

    public function switchKind(TunnelAgent $agent): string
    {
        $index = array_search($agent->kind, self::KIND_LADDER, true);
        $next = self::KIND_LADDER[($index === false ? 0 : $index + 1) % count(self::KIND_LADDER)];

        $from = $agent->kind->label();
        $agent->kind = $next;
        $agent->save();

        return "{$from} → {$next->label()}";
    }

    public function hopPort(TunnelGroup $group, TunnelAgent $agent): ?string
    {
        $candidates = $group->portHopPorts();

        if ($candidates === []) {
            $candidates = array_map('intval', (array) config('tunneling.dpi.port_candidates', []));
        }

        if (count($candidates) < 2) {
            return null;
        }

        $index = array_search((int) $agent->udp_port, $candidates, true);
        $next = $candidates[($index === false ? 0 : $index + 1) % count($candidates)];

        $from = $agent->udp_port;
        $agent->udp_port = $next;
        $agent->save();

        return "پورت {$from} → {$next}";
    }
}
