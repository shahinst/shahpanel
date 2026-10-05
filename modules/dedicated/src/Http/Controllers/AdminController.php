<?php

namespace Modules\Dedicated\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Dedicated\Models\DedicatedServer;
use Modules\Dedicated\Services\AgentFactory;
use Modules\Dedicated\Services\UsageMeter;
use Throwable;

/**
 * Dedicated agents, as the admin sees them: a list like the regular agents
 * page, a page to create one together with their first server, and a
 * settings page per agent for the servers they hold and what is metered.
 */
class AdminController extends Controller
{
    protected const METER_RULE = ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\-<>]+$/'];

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));

        $agents = User::query()
            ->where('role', UserRole::Agent)
            ->whereIn('id', DedicatedServer::query()->select('agent_user_id'))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('username', 'like', "%{$search}%")
                ->orWhere('full_name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->with('wallets')
            ->orderBy('username')
            ->paginate(15)
            ->withQueryString();

        $ids = $agents->pluck('id');
        $servers = DedicatedServer::query()->with('server')->whereIn('agent_user_id', $ids)->get()->groupBy('agent_user_id');

        return view('dedicated::admin.index', [
            'agents' => $agents,
            'servers' => $servers,
            'today' => DB::table('dedicated_usage_days')->where('day', today()->toDateString())->pluck('rx_bytes', 'server_id'),
            // Accounts the agent and their sellers sell on their own servers.
            'accounts' => Account::query()
                ->whereIn('server_id', $servers->flatten()->pluck('server_id'))
                ->selectRaw('server_id, COUNT(*) as c')->groupBy('server_id')->pluck('c', 'server_id'),
        ]);
    }

    public function create(): View
    {
        return view('dedicated::admin.create', ['freeServers' => $this->freeServers()]);
    }

    public function store(Request $request, AgentFactory $factory): RedirectResponse
    {
        $data = $request->validate([
            'server_id' => ['required', 'integer', 'exists:servers,id', Rule::unique('dedicated_servers', 'server_id')],
            'meter_interface' => self::METER_RULE,
        ] + AgentFactory::rules());

        $agent = DB::transaction(function () use ($data, $factory, $request): User {
            $agent = $factory->create($data, $request->user());

            DedicatedServer::query()->create([
                'agent_user_id' => $agent->id,
                'server_id' => (int) $data['server_id'],
                'meter_interface' => $data['meter_interface'] ?? null,
            ]);

            return $agent;
        });

        return redirect()->route('admin.dedicated.edit', $agent)->with('success', __('dedicated::admin.agent_created'));
    }

    public function edit(User $agent): View
    {
        $this->assertDedicated($agent);

        return view('dedicated::admin.edit', [
            'agent' => $agent,
            'rows' => DedicatedServer::query()->with('server')->where('agent_user_id', $agent->id)->get(),
            'freeServers' => $this->freeServers(),
            'today' => DB::table('dedicated_usage_days')->where('day', today()->toDateString())->pluck('rx_bytes', 'server_id'),
        ]);
    }

    /** One more server for an agent who already has one. */
    public function addServer(Request $request, User $agent): RedirectResponse
    {
        $this->assertDedicated($agent);

        $data = $request->validate([
            'server_id' => ['required', 'integer', 'exists:servers,id', Rule::unique('dedicated_servers', 'server_id')],
            'meter_interface' => self::METER_RULE,
        ]);

        DedicatedServer::query()->create([
            'agent_user_id' => $agent->id,
            'server_id' => (int) $data['server_id'],
            'meter_interface' => $data['meter_interface'] ?? null,
        ]);

        return back()->with('success', __('dedicated::admin.assigned'));
    }

    public function update(Request $request, DedicatedServer $dedicated): RedirectResponse
    {
        $data = $request->validate(['meter_interface' => self::METER_RULE]);

        // A different interface counts from a different number: start over
        // from its next reading instead of booking the gap as usage.
        if (($data['meter_interface'] ?? null) !== $dedicated->meter_interface) {
            $data['last_counter'] = null;
        }

        $dedicated->update($data);

        return back()->with('success', __('app.saved'));
    }

    public function interfaces(DedicatedServer $dedicated, UsageMeter $meter): RedirectResponse
    {
        try {
            $list = $meter->interfaces($dedicated->load('server'));
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('dedicated_interfaces.'.$dedicated->id, array_keys($list));
    }

    public function destroy(DedicatedServer $dedicated): RedirectResponse
    {
        // The last server cannot go from this page: an agent without one is
        // no longer a dedicated agent and would silently drop off the list.
        // Taking the last one away is deleting the agent, which the list does.
        if (DedicatedServer::query()->where('agent_user_id', $dedicated->agent_user_id)->count() <= 1) {
            return back()->with('error', __('dedicated::admin.last_server'));
        }

        // The agent's packages and accounts stay; only the ownership goes.
        $dedicated->delete();

        return back()->with('success', __('dedicated::admin.unassigned'));
    }

    protected function assertDedicated(User $agent): void
    {
        abort_unless(
            $agent->role === UserRole::Agent
                && DedicatedServer::query()->where('agent_user_id', $agent->id)->exists(),
            404,
        );
    }

    /** A server already given to an agent cannot be given twice. */
    protected function freeServers()
    {
        return Server::query()
            ->whereNotIn('id', DedicatedServer::query()->select('server_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'type']);
    }
}
