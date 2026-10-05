<?php

namespace Modules\Dedicated\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Dedicated\Models\DedicatedServer;
use Modules\Dedicated\Services\UsageMeter;
use Throwable;

/** The admin hands servers to dedicated agents and picks what is metered. */
class AdminController extends Controller
{
    public function index(): View
    {
        $rows = DedicatedServer::query()->with(['agent', 'server'])->orderBy('agent_user_id')->get();

        return view('dedicated::admin.index', [
            'rows' => $rows,
            'agents' => User::query()->where('role', UserRole::Agent)->orderBy('username')->get(['id', 'username', 'full_name']),
            // A server already given to an agent cannot be given twice.
            'freeServers' => Server::query()->whereNotIn('id', $rows->pluck('server_id'))->orderBy('name')->get(['id', 'name', 'type']),
            'today' => DB::table('dedicated_usage_days')->where('day', today()->toDateString())->pluck('rx_bytes', 'server_id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'agent_user_id' => ['required', Rule::exists('users', 'id')->where('role', UserRole::Agent->value)],
            'server_id' => ['required', 'integer', 'exists:servers,id', Rule::unique('dedicated_servers', 'server_id')],
            'meter_interface' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\-<>]+$/'],
        ]);

        DedicatedServer::query()->create($data);

        return back()->with('success', __('dedicated::admin.assigned'));
    }

    public function update(Request $request, DedicatedServer $dedicated): RedirectResponse
    {
        $data = $request->validate([
            'meter_interface' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._\-<>]+$/'],
        ]);

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
        // The agent's packages and accounts stay; only the ownership goes.
        $dedicated->delete();

        return back()->with('success', __('dedicated::admin.unassigned'));
    }
}
