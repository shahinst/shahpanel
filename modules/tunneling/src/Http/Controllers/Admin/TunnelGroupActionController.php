<?php

namespace Modules\Tunneling\Http\Controllers\Admin;

use AppHttpControllersController;
use App\Http\Controllers\Controller;
use App\Jobs\Tunneling\InstallRouterScriptJob;
use App\Jobs\Tunneling\ProbeMtuJob;
use App\Jobs\Tunneling\ReconcileServerJob;
use App\Jobs\Tunneling\RunTrafficTestJob;
use App\Enums\TunnelGroupStatus;
use App\Models\ConfigVersion;
use App\Models\Location;
use App\Models\ManagedInterface;
use App\Models\Server;
use App\Models\TunnelAgent;
use App\Models\TunnelGroup;
use App\Enums\TunnelKind;
use App\Services\Tunneling\ManagedInterfaceService;
use App\Services\Tunneling\TunnelGroupOrchestrator;
use App\Services\Tunneling\TunnelKindFailoverService;
use App\Services\Tunneling\TunnelSwitchService;
use App\Support\TunnelConfigureBackground;
use App\Support\TunnelJobDispatcher;
use App\Support\TunnelWipeBackground;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class TunnelGroupActionController extends Controller
{
    public function __construct(protected TunnelGroupOrchestrator $orchestrator)
    {
    }

    /** «کانفیگ و تست»: فوری در پس‌زمینه — مرورگر منتظر روتر نمی‌ماند. */
    public function configureAndTest(TunnelGroup $group): RedirectResponse
    {
        $group->loadMissing('exits');

        if ($group->exits->isEmpty()) {
            return back()->with('error', __('tunneling.group_needs_exit'));
        }

        if (TunnelConfigureBackground::isRunning($group->id)) {
            return back()->with('warning', __('tunneling.configure_already_running'));
        }

        if (TunnelWipeBackground::isRunning($group->id)) {
            return back()->with('warning', __('tunneling.configure_wipe_running'));
        }

        if (! TunnelConfigureBackground::dispatch($group->id)) {
            return back()->with('warning', __('tunneling.configure_already_running'));
        }

        $group->forceFill([
            'status' => TunnelGroupStatus::Applying,
            'status_message' => null,
        ])->save();

        return back()->with('success', __('tunneling.configure_test_started'));
    }

    /** پاک‌سازی کامل vpnl روی روترها (پس‌زمینه — بدون timeout مرورگر). */
    public function wipeRouters(TunnelGroup $group): RedirectResponse
    {
        if (! TunnelWipeBackground::dispatch($group->id, apply: false)) {
            return back()->with('warning', __('tunneling.wipe_already_running'));
        }

        return back()->with('success', __('tunneling.wipe_routers_started'));
    }

    /** پاک‌سازی کامل + اعمال مجدد (پس‌زمینه — بدون timeout مرورگر). */
    public function wipeAndApply(TunnelGroup $group): RedirectResponse
    {
        if (! TunnelWipeBackground::dispatch($group->id, apply: true)) {
            return back()->with('warning', __('tunneling.wipe_already_running'));
        }

        return back()->with('success', __('tunneling.wipe_and_apply_started'));
    }

    public function reverse(TunnelGroup $group): RedirectResponse
    {
        try {
            $this->orchestrator->reverse($group);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('tunneling.reverse_queued'));
    }

    /**
     * Manual kind failover/restore (active/passive priority mode) — e.g. an
     * admin restoring the primary tunnel kind after the network recovers.
     */
    public function promoteKind(Request $request, TunnelGroup $group, TunnelKindFailoverService $failover): RedirectResponse
    {
        $validated = $request->validate([
            'kind' => ['required', 'string'],
        ]);

        try {
            $kind = TunnelKind::from($validated['kind']);
        } catch (\ValueError) {
            return back()->with('error', __('tunneling.kind_failover_invalid'));
        }

        if (! $failover->promote($group, $kind)) {
            return back()->with('warning', __('tunneling.kind_failover_no_change'));
        }

        return back()->with('success', __('tunneling.kind_failover_switched', ['kind' => $kind->label()]));
    }

    public function reconcile(TunnelGroup $group): RedirectResponse
    {
        $group->loadMissing('exits');

        collect([$group->iran_server_id])
            ->merge($group->exits->pluck('server_id'))
            ->filter()
            ->unique()
            ->each(fn (int $id) => TunnelJobDispatcher::dispatch(new ReconcileServerJob($id)));

        return back()->with('success', __('tunneling.reconcile_queued'));
    }

    public function probeMtu(TunnelGroup $group): RedirectResponse
    {
        TunnelJobDispatcher::dispatch(new ProbeMtuJob($group->id));

        return back()->with('success', __('tunneling.mtu_probe_queued'));
    }

    public function trafficTest(TunnelGroup $group): RedirectResponse
    {
        TunnelJobDispatcher::dispatch(new RunTrafficTestJob($group->id));

        return back()->with('success', __('tunneling.traffic_test_queued'));
    }

    public function rollback(TunnelGroup $group, ConfigVersion $version): RedirectResponse
    {
        try {
            $this->orchestrator->rollback($group, $version);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('tunneling.rollback_queued', ['version' => $version->version]));
    }

    public function toggleAgent(TunnelAgent $agent): RedirectResponse
    {
        $group = $agent->group;

        if ($group === null) {
            return back()->with('error', __('tunneling.agent_without_group'));
        }

        $agent->update(['is_enabled' => ! $agent->is_enabled]);
        $this->orchestrator->reapply($group, 'agent-toggle');

        return back()->with('success', __('tunneling.agent_toggled'));
    }

    public function setAgentWeight(Request $request, TunnelAgent $agent): RedirectResponse
    {
        $group = $agent->group;

        if ($group === null) {
            return back()->with('error', __('tunneling.agent_without_group'));
        }

        $validated = $request->validate([
            'weight' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        $agent->update(['weight' => (int) $validated['weight']]);
        $this->orchestrator->reapply($group, 'agent-weight');

        return back()->with('success', __('tunneling.agent_weight_saved'));
    }

    public function switchAgent(TunnelAgent $agent, TunnelSwitchService $switcher): RedirectResponse
    {
        $group = $agent->group;

        if ($group === null) {
            return back()->with('error', __('tunneling.agent_without_group'));
        }

        $description = $switcher->switchAgent($group, $agent, 'manual');

        if ($description === null) {
            return back()->with('warning', __('tunneling.agent_switch_blocked'));
        }

        $this->orchestrator->reapply($group, 'agent-switch');

        return back()->with('success', __('tunneling.agent_switched', ['description' => $description]));
    }

    public function installScript(Server $server): RedirectResponse
    {
        TunnelJobDispatcher::dispatch(new InstallRouterScriptJob($server->id, true));

        return back()->with('success', __('tunneling.script_install_queued', ['server' => $server->name]));
    }

    public function storeInterface(Request $request, ManagedInterfaceService $interfaces): RedirectResponse
    {
        $validated = $request->validate([
            'server_id' => ['required', 'integer', 'exists:servers,id'],
            'tunnel_group_id' => ['nullable', 'integer', 'exists:tunnel_groups,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'type' => ['required', 'in:wireguard,ppp'],
            'name' => ['nullable', 'string', 'max:32'],
        ]);

        $server = Server::query()->findOrFail((int) $validated['server_id']);
        $location = isset($validated['location_id'])
            ? Location::query()->find((int) $validated['location_id'])
            : null;

        try {
            $interfaces->create(
                $server,
                $validated['type'],
                $location,
                isset($validated['tunnel_group_id']) ? (int) $validated['tunnel_group_id'] : null,
                $validated['name'] ?? null,
            );
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('tunneling.interface_queued'));
    }

    public function destroyInterface(ManagedInterface $interface, ManagedInterfaceService $interfaces): RedirectResponse
    {
        $interfaces->remove($interface);

        return back()->with('success', __('tunneling.interface_removal_queued'));
    }
}
