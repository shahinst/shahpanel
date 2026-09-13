<?php

namespace App\Services\Tunneling;

use App\Enums\BalancingMode;
use App\Enums\TunnelGroupStatus;
use App\Enums\TunnelKind;
use App\Models\Server;
use App\Models\TunnelGroup;
use App\Models\TunnelGroupEvent;
use App\Models\User;
use App\Support\TunnelConfigureBackground;
use App\Support\TunnelWipeBackground;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Orchestrates the 5-step tunneling wizard into a single TunnelGroup
 * creation + provisioning pass. It builds exactly the same kind of
 * TunnelGroup the advanced single-page form does — the wizard is a fast,
 * opinionated front door onto the same engine (TunnelGroupOrchestrator /
 * LoadBalancerService / ManagedInterfaceService); nothing here duplicates
 * the apply/test/report pipeline.
 *
 * The actual router provisioning (apply + MTU probe + traffic test) NEVER
 * runs inline on the wizard's HTTP request — it would take well past any
 * reverse-proxy/PHP-FPM gateway timeout (60s is common) and risks leaving
 * the request killed mid-apply with routers half-configured. Exactly like
 * the advanced form's «کانفیگ و تست» button, we hand off to
 * TunnelConfigureBackground/TunnelWipeBackground, which run the same
 * orchestrator methods *after* the HTTP response has been flushed to the
 * browser. The group's show page already polls configureState/wipeState
 * for live progress.
 */
class TunnelWizardService
{
    public function __construct(
        protected TunnelGroupOrchestrator $orchestrator,
    ) {
    }

    /**
     * @param  array{
     *     client_service_types: list<string>,
     *     iran_server_id: int|string,
     *     exit_ids: list<int|string>,
     *     tunnel_kinds: list<string>,
     *     kind_selection_mode?: string|null,
     *     wipe_previous?: bool,
     *     name?: string|null,
     * }  $data
     */
    public function createGroup(array $data, ?User $actor = null): TunnelGroup
    {
        $iranServer = Server::query()->findOrFail((int) $data['iran_server_id']);

        $exitIds = array_values(array_unique(array_map('intval', $data['exit_ids'] ?? [])));
        $exitIds = array_values(array_filter($exitIds, fn (int $id): bool => $id !== (int) $iranServer->id));

        if ($exitIds === []) {
            throw new RuntimeException('حداقل یک سرور خارج باید انتخاب شود.');
        }

        $kindValues = array_values(array_unique(array_map('strval', $data['tunnel_kinds'] ?? [])));
        $kinds = array_map(static fn (string $k): TunnelKind => TunnelKind::from($k), $kindValues);

        if ($kinds === []) {
            throw new RuntimeException('حداقل یک نوع تانل باید انتخاب شود.');
        }

        $clientServiceTypes = array_values(array_intersect(
            array_unique($data['client_service_types'] ?? ['wireguard']),
            ['wireguard', 'ppp'],
        ));

        if ($clientServiceTypes === []) {
            $clientServiceTypes = ['wireguard'];
        }

        $selectionMode = $data['kind_selection_mode'] ?? null;

        if (! in_array($selectionMode, ['single', 'balanced', 'priority'], true)) {
            $selectionMode = count($kinds) > 1 ? 'balanced' : 'single';
        }

        // Smart default: more than one exit → split client IPs across them
        // by range (FIB per exit); a single exit keeps the classic PCC
        // per-connection balancer across that exit's agents.
        $balancingMode = count($exitIds) > 1 ? BalancingMode::RangeSplit : BalancingMode::Pcc;

        $meta = [
            'kind_mix' => array_map(fn (TunnelKind $k): string => $k->value, $kinds),
            'kind_selection_mode' => $selectionMode,
            'client_service_types' => $clientServiceTypes,
        ];

        if ($selectionMode === 'priority') {
            $meta['kind_priority_order'] = array_map(fn (TunnelKind $k): string => $k->value, $kinds);
            $meta['active_kind'] = $kinds[0]->value;
        }

        $name = trim((string) ($data['name'] ?? '')) ?: $this->autoName($iranServer, $kinds);

        $group = TunnelGroup::create([
            'name' => $name,
            'kind' => $kinds[0]->value,
            'iran_server_id' => $iranServer->id,
            'agents_per_exit' => 2,
            'balancing_mode' => $balancingMode->value,
            'ipsec_enabled' => false,
            'mss_clamp' => true,
            'auto_switch_l2tp' => false,
            'auto_switch_kind' => false,
            'meta' => $meta,
            'status' => TunnelGroupStatus::Draft,
        ]);

        $this->orchestrator->syncExits($group, $exitIds);

        TunnelGroupEvent::record(
            'wizard_created',
            "گروه «{$group->name}» با ویزارد تانلینگ ساخته شد.",
            ['tunnel_group_id' => $group->id, 'detail' => ['created_by' => $actor?->id]],
        );

        $wipePrevious = (bool) ($data['wipe_previous'] ?? false);

        $group->forceFill([
            'status' => TunnelGroupStatus::Applying,
            'status_message' => null,
        ])->save();

        if ($wipePrevious) {
            TunnelWipeBackground::dispatch($group->id, apply: true);
        } else {
            TunnelConfigureBackground::dispatch($group->id);
        }

        return $group->fresh(['iranServer', 'exits.server', 'exits.agents']) ?? $group;
    }

    /**
     * @param  list<TunnelKind>  $kinds
     */
    protected function autoName(Server $iranServer, array $kinds): string
    {
        $kindLabel = $kinds[0]->label();

        if (count($kinds) > 1) {
            $kindLabel .= '+'.(count($kinds) - 1);
        }

        return "تانل {$iranServer->name} ({$kindLabel}) #".Str::upper(Str::random(4));
    }
}
