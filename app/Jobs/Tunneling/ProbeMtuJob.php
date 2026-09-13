<?php

namespace App\Jobs\Tunneling;

use App\Models\TunnelGroup;
use App\Models\TunnelGroupEvent;
use App\Services\Tunneling\MtuCalculator;

/**
 * Real DF-bit MTU probe from the Iran router toward every exit's public IP;
 * the smallest passing underlay PMTU minus encapsulation overhead becomes the
 * group's probed tunnel MTU. When the value changes the group is re-applied so
 * interface MTUs and MSS clamps pick it up.
 */
class ProbeMtuJob extends BaseTunnelingJob
{
    public function __construct(public int $groupId)
    {
        parent::__construct();
    }

    public function handle(MtuCalculator $calculator): void
    {
        $group = TunnelGroup::query()->with('iranServer', 'exits.server')->find($this->groupId);

        if ($group === null || $group->iranServer === null) {
            return;
        }

        $iran = $group->iranServer;
        $results = [];
        $minUnderlay = null;

        $this->withServerLock($iran->id, function () use ($calculator, $group, $iran, &$results, &$minUnderlay): void {
            foreach ($group->exits as $exit) {
                $target = $exit->server?->apiConnectionHost();

                if ($target === null || $target === '') {
                    continue;
                }

                $underlay = $calculator->probeUnderlayMtu($iran, $target);
                $results[] = [
                    'exit' => $exit->server->name,
                    'target' => $target,
                    'underlay_mtu' => $underlay,
                ];

                if ($underlay !== null) {
                    $minUnderlay = $minUnderlay === null ? $underlay : min($minUnderlay, $underlay);
                }
            }
        });

        $group->forceFill([
            'meta' => array_merge($group->meta ?? [], [
                'mtu_probe' => ['probed_at' => now()->toIso8601String(), 'results' => $results],
            ]),
        ]);

        if ($minUnderlay !== null) {
            $overhead = $group->kind->overheadBytes()
                + ($group->ipsec_enabled ? \App\Enums\TunnelKind::ipsecOverheadBytes() : 0);
            $probed = max(576, $minUnderlay - $overhead);
            $changed = $group->mtu_probed !== $probed;
            $group->mtu_probed = $probed;
            $group->save();

            TunnelGroupEvent::record(
                'mtu_probe',
                "پروب MTU گروه «{$group->name}»: مسیر {$minUnderlay} بایت → MTU تانل {$probed}.",
                ['tunnel_group_id' => $group->id],
            );

            if ($changed) {
                app(\App\Services\Tunneling\TunnelGroupOrchestrator::class)->reapply($group, 'mtu-probe');
            }

            return;
        }

        $group->save();

        TunnelGroupEvent::record(
            'mtu_probe',
            "پروب MTU گروه «{$group->name}» ناموفق بود (ICMP فیلتر یا مسیر قطع) — مقدار محاسباتی استفاده می‌شود.",
            ['tunnel_group_id' => $group->id],
            'warning',
        );
    }
}
