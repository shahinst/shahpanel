<?php

namespace App\Services\Tunneling;

use App\Enums\AgentHealth;
use App\Enums\TunnelKind;
use App\Models\TunnelAgent;
use App\Models\TunnelGroup;
use App\Models\TunnelGroupEvent;

/**
 * Active/passive failover between several selected tunnel kinds
 * (kindSelectionMode() === 'priority'). Only one kind is ever active at a
 * time; the others are provisioned as disabled standby agents by
 * TunnelGroupOrchestrator::syncAgents(). When every agent of the active
 * kind is Down for switch_after_failures cycles, the next kind in
 * priorityKindOrder() is promoted automatically. Falling back to a
 * higher-priority kind is manual only (promote()), to avoid flapping.
 */
class TunnelKindFailoverService
{
    public function __construct(protected TunnelGroupOrchestrator $orchestrator)
    {
    }

    /**
     * Called after per-agent health evaluation. Returns true when a
     * kind switch happened (caller should already know to re-apply, but
     * this method re-applies itself since agent enable/disable changed).
     */
    public function evaluate(TunnelGroup $group): bool
    {
        if ($group->kindSelectionMode() !== 'priority') {
            return false;
        }

        $order = $group->priorityKindOrder();

        if (count($order) < 2) {
            return false;
        }

        $active = $group->activeKind();
        $activeIndex = $this->indexOf($order, $active);

        if ($activeIndex === null || $activeIndex >= count($order) - 1) {
            return false; // unknown or already on the last fallback — nothing further to switch to.
        }

        $group->loadMissing('exits.agents');

        $activeAgents = $group->exits
            ->flatMap(fn ($exit) => $exit->agents)
            ->filter(fn (TunnelAgent $a): bool => $a->kind === $active);

        if ($activeAgents->isEmpty()) {
            return false;
        }

        $threshold = (int) config('tunneling.scoring.switch_after_failures', 3);

        $allDown = $activeAgents->every(
            fn (TunnelAgent $a): bool => $a->health === AgentHealth::Down && $a->fail_count >= $threshold,
        );

        if (! $allDown) {
            return false;
        }

        $next = $order[$activeIndex + 1];

        $this->switchTo($group, $next, "قطعی کامل تانل‌های {$active->label()} (تلاش مجدد ناموفق).");

        return true;
    }

    /**
     * Manual promotion — e.g. an admin button to restore the primary kind
     * (or jump to any other configured kind) after the network recovers.
     */
    public function promote(TunnelGroup $group, TunnelKind $kind): bool
    {
        $order = $group->priorityKindOrder();

        if (! in_array($kind, $order, true) || $kind === $group->activeKind()) {
            return false;
        }

        $this->switchTo($group, $kind, 'سوییچ دستی توسط مدیر.');

        return true;
    }

    protected function switchTo(TunnelGroup $group, TunnelKind $next, string $reasonText): void
    {
        $previous = $group->activeKind();

        $group->forceFill([
            'meta' => array_merge($group->meta ?? [], ['active_kind' => $next->value]),
        ])->save();

        TunnelGroupEvent::record(
            'kind_failover',
            "فیل‌اور گروه «{$group->name}»: از {$previous->label()} به {$next->label()} تغییر یافت. {$reasonText}",
            ['tunnel_group_id' => $group->id],
            'warning',
        );

        $this->orchestrator->syncAgents($group);
        $this->orchestrator->reapply($group, 'kind-failover');
    }

    /**
     * @param  list<TunnelKind>  $order
     */
    protected function indexOf(array $order, TunnelKind $kind): ?int
    {
        foreach ($order as $i => $k) {
            if ($k === $kind) {
                return $i;
            }
        }

        return null;
    }
}
