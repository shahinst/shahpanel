<?php

namespace Modules\Dedicated\Http\Controllers;

use App\Enums\PackageDurationTier;
use App\Enums\ServiceType;
use App\Http\Controllers\Admin\PackageController;
use App\Models\InboundAllocation;
use App\Models\Package;
use App\Models\PackageDuration;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Dedicated\Models\DedicatedServer;

/**
 * Package building for dedicated and inbound agents, on the admin's own form.
 *
 * It extends the admin controller rather than copying it so the two can never
 * drift apart: every rule the admin's form enforces -- pricing models, data
 * bounds, durations, MikroTik profiles, Sanaei inbounds, PasarGuard groups --
 * is enforced here by the same code. What this class adds is the fence:
 *
 *  - an agent sees and may pick only their own servers: the ones assigned to
 *    them as a dedicated agent, and the server of each inbound allocation;
 *  - on an allocation's server only the allocation's own inbounds exist, so
 *    an inbound agent cannot sell an inbound the admin kept for someone else;
 *  - the package is stamped with its owner (and allocation), which is what
 *    keeps it out of the admin's catalogue and accounting.
 */
class AgentPackageController extends PackageController
{
    public function createPackage(Request $request): View
    {
        $agent = $this->agent($request);
        $servers = $this->servers($agent);

        return view('dedicated::agent.package-admin-form', $this->formData($agent, $servers, null));
    }

    public function editPackage(Request $request, Package $package): View
    {
        $agent = $this->agent($request);
        $this->ownPackage($agent, $package);
        $package->load(['durations', 'servers']);

        return view('dedicated::agent.package-admin-form', $this->formData($agent, $this->servers($agent), $package));
    }

    public function storePackage(Request $request): RedirectResponse
    {
        $agent = $this->agent($request);
        [$validated, $serviceType] = $this->validatedForAgent($request, $agent);

        DB::transaction(function () use ($request, $validated, $serviceType): void {
            $package = Package::query()->create($validated);
            $this->packageService->syncDurations($package, $request->input('durations', []));
            $this->packageService->syncServers($package, $request->input('server_ids', []), $serviceType);
        });

        return redirect()->route($this->home($agent))->with('success', __('app.saved'));
    }

    public function updatePackage(Request $request, Package $package): RedirectResponse
    {
        $agent = $this->agent($request);
        $this->ownPackage($agent, $package);
        [$validated, $serviceType] = $this->validatedForAgent($request, $agent);
        // Same rule as the admin's: currency is fixed once the package exists.
        $validated['currency'] = $package->moneyCurrency()->value;

        DB::transaction(function () use ($request, $validated, $serviceType, $package): void {
            $package->update($validated);
            $this->packageService->syncDurations($package, $request->input('durations', []));
            $this->packageService->syncServers($package, $request->input('server_ids', []), $serviceType);
        });

        return redirect()->route($this->home($agent))->with('success', __('app.saved'));
    }

    public function destroyPackage(Request $request, Package $package): RedirectResponse
    {
        $agent = $this->agent($request);
        $this->ownPackage($agent, $package);
        $this->packageService->deletePreservingAccounts($package);

        return redirect()->route($this->home($agent))->with('success', __('dedicated::admin.package_deleted'));
    }

    /**
     * @return array{0: array<string, mixed>, 1: ServiceType}
     */
    protected function validatedForAgent(Request $request, User $agent): array
    {
        $servers = $this->servers($agent);
        $chosen = array_values(array_unique(array_map('intval', (array) $request->input('server_ids', []))));

        // Checked before the admin's rules run: those look the servers up and
        // would happily read profiles off a server that is not this agent's.
        if ($chosen === [] || array_diff($chosen, $servers->pluck('id')->all()) !== []) {
            throw ValidationException::withMessages(['server_ids' => [__('dedicated::admin.not_your_server')]]);
        }

        // Agents have no say over the admin's category tree.
        $request->request->remove('package_category_id');

        $validated = $this->validatedCore($request);
        $validated['package_category_id'] = null;
        $validated['owner_agent_id'] = $agent->id;
        $validated['inbound_allocation_id'] = $this->allocationFor($agent, $chosen, $validated);

        return [$validated, ServiceType::from($validated['service_type'])];
    }

    /**
     * Which allocation a package belongs to, if it sits on an allocation's
     * server. A package spanning an allocation and anything else is refused:
     * its usage could not be billed to one allocation, and the quota is what
     * keeps an inbound agent inside what they paid for.
     */
    protected function allocationFor(User $agent, array $serverIds, array $validated): ?int
    {
        $dedicated = DedicatedServer::serverIdsOf($agent);
        $allocations = $this->allocations($agent)->whereIn('server_id', $serverIds)
            ->reject(fn (InboundAllocation $a): bool => in_array((int) $a->server_id, $dedicated, true));

        if ($allocations->isEmpty()) {
            return null;
        }

        if (count($serverIds) > 1) {
            throw ValidationException::withMessages(['server_ids' => [__('dedicated::admin.one_inbound_server')]]);
        }

        $picked = array_map('intval', (array) ($validated['sanaei_inbound_ids'] ?? []));

        // An empty choice means "every inbound on the server" to the provisioner,
        // which on an allocation's server would reach inbounds that are not theirs.
        if ($picked === []) {
            throw ValidationException::withMessages(['sanaei_inbound_ids' => [__('dedicated::admin.pick_your_inbounds')]]);
        }

        $owner = $allocations->first(fn (InboundAllocation $a): bool => array_diff($picked, array_map('intval', $a->inboundIdList())) === []);

        if ($owner === null) {
            throw ValidationException::withMessages(['sanaei_inbound_ids' => [__('dedicated::admin.not_your_inbound')]]);
        }

        return $owner->id;
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(User $agent, Collection $servers, ?Package $package): array
    {
        return [
            'package' => $package,
            'servers' => $servers,
            'durationTiers' => PackageDurationTier::cases(),
            'durationsByTier' => $package?->durations->keyBy(fn (PackageDuration $d) => $d->tier->value),
            'categories' => collect(),
            'pasarguardGroupOptions' => $package ? $this->pasarguardGroupOptionsForPackage($package) : [],
            'pasarguardGroupsByServer' => $this->pasarguardGroupsByServerMap($servers),
            'mikrotikProfilesByServer' => $this->mikrotikProfilesByServerMap($servers),
            'sanaeiInboundsByServer' => $this->ownInbounds($agent, $this->sanaeiInboundsByServerMap($servers)),
        ];
    }

    /**
     * Narrow the inbound list of each allocation server to that allocation's
     * inbounds. Dedicated servers keep every inbound: the whole server is theirs.
     */
    protected function ownInbounds(User $agent, array $map): array
    {
        $dedicated = DedicatedServer::serverIdsOf($agent);

        foreach ($this->allocations($agent) as $allocation) {
            $key = (string) $allocation->server_id;

            if (! isset($map[$key]) || in_array((int) $allocation->server_id, $dedicated, true)) {
                continue;
            }

            $mine = array_map('intval', $allocation->inboundIdList());
            $map[$key] = array_values(array_filter($map[$key], fn (array $row): bool => in_array((int) $row['id'], $mine, true)));
        }

        return $map;
    }

    protected function servers(User $agent): Collection
    {
        $ids = array_unique(array_merge(
            DedicatedServer::serverIdsOf($agent),
            $this->allocations($agent)->pluck('server_id')->map(fn ($id) => (int) $id)->all(),
        ));

        return Server::query()->whereIn('id', $ids)->orderBy('name')->get();
    }

    protected function allocations(User $agent): Collection
    {
        return InboundAllocation::query()->where('agent_user_id', $agent->id)->get();
    }

    protected function agent(Request $request): User
    {
        $agent = $request->user();
        abort_unless(
            DedicatedServer::isDedicatedAgent($agent)
                || InboundAllocation::query()->where('agent_user_id', $agent->id)->exists(),
            403
        );

        return $agent;
    }

    /** Where an agent's package list lives: dedicated agents and inbound-only agents have different pages. */
    protected function home(User $agent): string
    {
        return DedicatedServer::isDedicatedAgent($agent) ? 'agent.dedicated.index' : 'agent.inbounds.index';
    }

    protected function ownPackage(User $agent, Package $package): void
    {
        abort_unless((int) $package->owner_agent_id === (int) $agent->id, 404);
    }
}
