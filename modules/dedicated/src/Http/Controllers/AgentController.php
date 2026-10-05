<?php

namespace Modules\Dedicated\Http\Controllers;

use App\Enums\PackageDurationTier;
use App\Models\Package;
use App\Models\Server;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use InvalidArgumentException;
use Modules\Dedicated\Models\DedicatedServer;
use Modules\Dedicated\Services\DedicatedPackageService;

/**
 * A dedicated agent's own corner: their servers, what those servers have
 * downloaded, and the packages they sell on them at their own prices.
 * Nothing here reaches a server or a package that is not theirs.
 */
class AgentController extends Controller
{
    public function index(Request $request): View
    {
        $agent = $this->agent($request);
        $rows = DedicatedServer::query()->with('server')->where('agent_user_id', $agent->id)->get();
        $ids = $rows->pluck('server_id');
        $days = DB::table('dedicated_usage_days')->whereIn('server_id', $ids);

        return view('dedicated::agent.index', [
            'rows' => $rows,
            'total' => (int) $rows->sum('total_rx_bytes'),
            'today' => (int) (clone $days)->where('day', today()->toDateString())->sum('rx_bytes'),
            'month' => (int) (clone $days)->where('day', '>=', today()->subDays(29)->toDateString())->sum('rx_bytes'),
            'chart' => (clone $days)->where('day', '>=', today()->subDays(13)->toDateString())
                ->selectRaw('day, SUM(rx_bytes) as rx')->groupBy('day')->orderBy('day')->pluck('rx', 'day'),
            'packages' => Package::query()->with('durations')->where('owner_agent_id', $agent->id)
                ->whereNull('inbound_allocation_id')->orderBy('name')->get(),
        ]);
    }

    public function createPackage(Request $request): View
    {
        return $this->form($request, null);
    }

    public function editPackage(Request $request, Package $package): View
    {
        $this->ownPackage($request, $package);

        return $this->form($request, $package->load('durations'));
    }

    public function storePackage(Request $request, DedicatedPackageService $service): RedirectResponse
    {
        return $this->persist($request, $service, null);
    }

    public function updatePackage(Request $request, Package $package, DedicatedPackageService $service): RedirectResponse
    {
        $this->ownPackage($request, $package);

        return $this->persist($request, $service, $package);
    }

    public function destroyPackage(Request $request, Package $package, DedicatedPackageService $service): RedirectResponse
    {
        $this->ownPackage($request, $package);
        $service->delete($request->user(), $package);

        return redirect()->route('agent.dedicated.index')->with('success', __('dedicated::admin.package_deleted'));
    }

    protected function form(Request $request, ?Package $package): View
    {
        $agent = $this->agent($request);
        $servers = Server::query()->whereIn('id', DedicatedServer::serverIdsOf($agent))->orderBy('name')->get();

        return view('dedicated::agent.package-form', [
            'package' => $package,
            'servers' => $servers,
            'types' => $servers->mapWithKeys(fn (Server $s) => [$s->id => DedicatedPackageService::serviceTypesFor($s)]),
            'targets' => $servers->mapWithKeys(fn (Server $s) => [$s->id => DedicatedPackageService::targetsFor($s)]),
            'tiers' => PackageDurationTier::cases(),
        ]);
    }

    protected function persist(Request $request, DedicatedPackageService $service, ?Package $package): RedirectResponse
    {
        $this->agent($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'server_id' => ['required', 'integer'],
            'service_type' => ['required', 'string', 'max:32'],
            'targets' => ['nullable', 'array'],
            'targets.*' => ['string', 'max:128'],
            'data_limit_gb' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'is_active' => ['required', 'boolean'],
            'durations' => ['required', 'array'],
        ]);

        try {
            $service->save($request->user(), $data, $package);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('agent.dedicated.index')->with('success', __('app.saved'));
    }

    protected function agent(Request $request)
    {
        $agent = $request->user();
        abort_unless(DedicatedServer::isDedicatedAgent($agent), 403);

        return $agent;
    }

    protected function ownPackage(Request $request, Package $package): void
    {
        abort_unless(
            (int) $package->owner_agent_id === (int) $this->agent($request)->id && $package->isDedicatedPackage(),
            404
        );
    }
}
