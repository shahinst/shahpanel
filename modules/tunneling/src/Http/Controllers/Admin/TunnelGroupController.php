<?php

namespace Modules\Tunneling\Http\Controllers\Admin;

use AppHttpControllersController;
use App\Enums\DesiredObjectStatus;
use App\Enums\BalancingMode;
use App\Enums\ServerType;
use App\Enums\TunnelGroupStatus;
use App\Enums\TunnelKind;
use App\Http\Controllers\Controller;
use App\Models\DesiredNetworkObject;
use App\Models\Location;
use App\Models\Server;
use App\Models\TunnelGroup;
use App\Models\TunnelGroupEvent;
use App\Support\TunnelingSchema;
use App\Support\TunnelConfigureBackground;
use App\Support\TunnelQueueHealth;
use App\Support\TunnelTeardownBackground;
use App\Support\TunnelWipeBackground;
use App\Services\Tunneling\TunnelGroupOrchestrator;
use App\Services\Tunneling\TunnelTeardownService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class TunnelGroupController extends Controller
{
    public function index(): View
    {
        try {
            if ($setup = $this->setupRequiredView()) {
                return $setup;
            }

            $groups = TunnelGroup::query()
                ->with('iranServer', 'location', 'exits.server', 'exits.agents')
                ->orderByDesc('id')
                ->paginate(20);

            $servers = Server::query()
                ->active()
                ->where('type', ServerType::Mikrotik)
                ->orderBy('name')
                ->get();

            return view('tunneling::index', [
                'groups' => $groups,
                'servers' => $servers,
                'queueHealth' => TunnelQueueHealth::snapshot(),
            ]);
        } catch (Throwable $e) {
            Log::error('tunneling.index failed', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return $this->setupRequiredView($e->getMessage());
        }
    }

    public function create(): View
    {
        try {
            if ($setup = $this->setupRequiredView()) {
                return $setup;
            }

            return view('tunneling::form', [
                'group' => null,
                'servers' => $this->mikrotikServers(),
                'locations' => Location::query()->orderBy('name')->get(),
            ]);
        } catch (Throwable $e) {
            Log::error('tunneling.create failed', ['error' => $e->getMessage()]);

            return $this->setupRequiredView($e->getMessage());
        }
    }

    public function store(Request $request, TunnelGroupOrchestrator $orchestrator): RedirectResponse
    {
        $data = $this->validated($request);

        $group = TunnelGroup::create([
            ...$data['attributes'],
            'status' => TunnelGroupStatus::Draft,
        ]);

        $orchestrator->syncExits($group, $data['exit_ids']);

        return redirect()
            ->route('admin.tunneling.groups.show', $group)
            ->with('success', __('tunneling.group_created'));
    }

    public function show(TunnelGroup $group): View
    {
        $group->load('iranServer', 'location', 'exits.server', 'exits.agents', 'configVersions');

        return view('tunneling::show', [
            'group' => $group,
            'events' => $group->events()->with('agent', 'server')->limit(50)->get(),
            'failedObjects' => DesiredNetworkObject::query()
                ->where('tunnel_group_id', $group->id)
                ->where('status', DesiredObjectStatus::Error)
                ->with('server')
                ->orderBy('server_id')
                ->orderBy('menu')
                ->get(),
            'scripts' => \App\Models\RouterScript::query()
                ->whereIn('server_id', collect([$group->iran_server_id])
                    ->merge($group->exits->pluck('server_id'))
                    ->all())
                ->get()
                ->keyBy('server_id'),
            'interfaces' => \App\Models\ManagedInterface::query()
                ->where('server_id', $group->iran_server_id)
                ->where(function ($query) use ($group): void {
                    $query->where('tunnel_group_id', $group->id);

                    if ($group->location_id !== null) {
                        $query->orWhere('location_id', $group->location_id);
                    }
                })
                ->get(),
            'queueHealth' => TunnelQueueHealth::snapshot(),
            'wipeState' => TunnelWipeBackground::state($group->id),
            'configureState' => TunnelConfigureBackground::state($group->id),
        ]);
    }

    public function edit(TunnelGroup $group): View
    {
        $group->load('exits');

        return view('tunneling::form', [
            'group' => $group,
            'servers' => $this->mikrotikServers(),
            'locations' => Location::query()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, TunnelGroup $group, TunnelGroupOrchestrator $orchestrator): RedirectResponse
    {
        $data = $this->validated($request, $group);

        $group->update($data['attributes']);
        $orchestrator->syncExits($group, $data['exit_ids']);

        if (! in_array($group->status, [TunnelGroupStatus::Draft, TunnelGroupStatus::Removing], true)) {
            $orchestrator->provision($group, 'update');
        }

        return redirect()
            ->route('admin.tunneling.groups.show', $group)
            ->with('success', __('tunneling.group_updated'));
    }

    public function destroy(TunnelGroup $group, TunnelTeardownService $teardown): RedirectResponse
    {
        Cache::forget(TunnelTeardownBackground::cacheKey($group->id));

        $name = $group->name;
        $groupId = $group->id;
        $serverIds = collect([$group->iran_server_id])
            ->merge($group->exits()->pluck('server_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        try {
            $teardown->purgePanelOnly($group);
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('admin.tunneling.index')
                ->with('error', __('tunneling.group_teardown_failed', [
                    'message' => mb_substr($e->getMessage(), 0, 200),
                ]));
        }

        if ($serverIds !== [] && filter_var(config('tunneling.teardown.wipe_routers_after_panel_delete', false), FILTER_VALIDATE_BOOL)) {
            \App\Support\TunnelBackgroundRunner::defer(static function () use ($teardown, $groupId, $serverIds): void {
                $teardown->wipeRoutersByGroupId($groupId, $serverIds);
            });
        }

        TunnelGroupEvent::record(
            'teardown_done',
            "گروه «{$name}» از پنل حذف شد.",
            ['tunnel_group_id' => null, 'detail' => ['former_group_id' => $groupId]],
            'ok',
        );

        return redirect()
            ->route('admin.tunneling.index')
            ->with('success', __('tunneling.group_teardown_done', ['name' => $name]));
    }

    /**
     * @return array{attributes: array<string, mixed>, exit_ids: list<int>}
     */
    protected function validated(Request $request, ?TunnelGroup $group = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::enum(TunnelKind::class)],
            'kind_mix' => ['nullable', 'array', 'min:1'],
            'kind_mix.*' => [Rule::enum(TunnelKind::class)],
            'iran_server_id' => ['required', 'integer', Rule::exists('servers', 'id')],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')],
            'exit_ids' => ['required', 'array', 'min:1'],
            'exit_ids.*' => ['integer', 'distinct', Rule::exists('servers', 'id'), 'different:iran_server_id'],
            'agents_per_exit' => ['required', 'integer', 'min:1', 'max:8'],
            'balancing_mode' => ['required', Rule::enum(BalancingMode::class)],
            'circuit_id' => ['nullable', 'string', 'max:64'],
            'port_hop_list' => ['nullable', 'string', 'max:255'],
            'ipsec_enabled' => ['nullable', 'boolean'],
            'ipsec_secret' => ['nullable', 'string', 'max:128'],
            'mss_clamp' => ['nullable', 'boolean'],
            'auto_switch_l2tp' => ['nullable', 'boolean'],
            'auto_switch_kind' => ['nullable', 'boolean'],
        ]);

        $attributes = [
            'name' => $data['name'],
            'kind' => $data['kind'],
            'iran_server_id' => (int) $data['iran_server_id'],
            'location_id' => isset($data['location_id']) ? (int) $data['location_id'] : null,
            'agents_per_exit' => (int) $data['agents_per_exit'],
            'balancing_mode' => $data['balancing_mode'],
            'circuit_id' => $data['circuit_id'] ?? null,
            'port_hop_list' => $data['port_hop_list'] ?? null,
            'ipsec_enabled' => $request->boolean('ipsec_enabled'),
            'mss_clamp' => $request->boolean('mss_clamp', true),
            'auto_switch_l2tp' => $request->boolean('auto_switch_l2tp'),
            'auto_switch_kind' => $request->boolean('auto_switch_kind'),
        ];

        if (($data['ipsec_secret'] ?? '') !== '') {
            $attributes['ipsec_secret_enc'] = $data['ipsec_secret'];
        }

        $kindMix = array_values(array_unique($data['kind_mix'] ?? [$data['kind']]));
        $meta = $group?->meta ?? [];
        $meta['kind_mix'] = $kindMix;
        $attributes['meta'] = $meta;
        $attributes['kind'] = $kindMix[0];

        return [
            'attributes' => $attributes,
            'exit_ids' => array_map('intval', $data['exit_ids']),
        ];
    }

    protected function mikrotikServers()
    {
        return Server::query()
            ->active()
            ->where('type', ServerType::Mikrotik)
            ->orderBy('name')
            ->get();
    }

    protected function setupRequiredView(?string $error = null): ?View
    {
        $missing = $this->missingTunnelingTables();

        if ($missing === [] && $error === null) {
            return null;
        }

        try {
            return view('tunneling::setup-required', [
                'missingTables' => $missing,
                'error' => $error,
            ]);
        } catch (Throwable $e) {
            Log::error('tunneling.setup-required view failed', ['error' => $e->getMessage()]);

            return view('tunneling::setup-required-minimal', [
                'missingTables' => $missing,
                'error' => $error ?? $e->getMessage(),
            ]);
        }
    }

    /** @return list<string> */
    protected function missingTunnelingTables(): array
    {
        if (! class_exists(TunnelingSchema::class)) {
            return ['(کلاس TunnelingSchema آپلود نشده — app/Support/TunnelingSchema.php)'];
        }

        return TunnelingSchema::missingTables();
    }
}
