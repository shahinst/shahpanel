<?php

namespace App\Http\Controllers\Agent;

use App\Enums\PackageDurationTier;
use App\Http\Controllers\Controller;
use App\Models\InboundAllocation;
use App\Models\Package;
use App\Services\InboundReseller\AgentPackageService;
use App\Services\InboundReseller\InboundAllocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Agent side of the inbound reseller model: their allocations with quota and
 * cost, and the packages they sell on them.
 */
class InboundController extends Controller
{
    public function __construct(
        protected InboundAllocationService $allocations,
        protected AgentPackageService $packages,
    ) {}

    public function index(Request $request): View
    {
        $allocations = InboundAllocation::query()
            ->where('agent_user_id', $request->user()->id)
            ->with(['server', 'packages' => fn ($q) => $q->with('durations')->orderBy('name')])
            ->orderBy('id')
            ->get();

        return view('agent.inbounds.index', [
            'allocations' => $allocations,
            'summaries' => $allocations->mapWithKeys(fn (InboundAllocation $a): array => [$a->id => $this->allocations->summary($a)]),
        ]);
    }

    public function createPackage(Request $request, InboundAllocation $allocation): View
    {
        $this->authorizeAllocation($request, $allocation);

        return view('agent.inbounds.package-form', [
            'allocation' => $allocation,
            'package' => null,
            'tiers' => $this->tiers(),
        ]);
    }

    public function storePackage(Request $request, InboundAllocation $allocation): RedirectResponse
    {
        $this->authorizeAllocation($request, $allocation);

        try {
            $this->packages->save($request->user(), $allocation, $this->validatedPackage($request));
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('agent.inbounds.index')->with('success', __('app.saved'));
    }

    public function editPackage(Request $request, Package $package): View
    {
        $this->authorizePackage($request, $package);

        return view('agent.inbounds.package-form', [
            'allocation' => $package->inboundAllocation,
            'package' => $package->load('durations'),
            'tiers' => $this->tiers(),
        ]);
    }

    public function updatePackage(Request $request, Package $package): RedirectResponse
    {
        $this->authorizePackage($request, $package);

        try {
            $this->packages->save($request->user(), $package->inboundAllocation, $this->validatedPackage($request), $package);
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('agent.inbounds.index')->with('success', __('app.saved'));
    }

    public function destroyPackage(Request $request, Package $package): RedirectResponse
    {
        $this->authorizePackage($request, $package);
        $this->packages->delete($request->user(), $package);

        return redirect()->route('agent.inbounds.index')->with('success', __('app.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedPackage(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'data_limit_gb' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'sanaei_limit_ip' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'durations' => ['required', 'array'],
            'durations.*.is_enabled' => ['nullable'],
            'durations.*.price' => ['nullable', 'numeric', 'min:0'],
        ]);

        return [
            'name' => $validated['name'],
            'data_limit_gb' => isset($validated['data_limit_gb']) ? (float) $validated['data_limit_gb'] : null,
            'sanaei_limit_ip' => isset($validated['sanaei_limit_ip']) ? (int) $validated['sanaei_limit_ip'] : null,
            'is_active' => $request->boolean('is_active'),
            'durations' => $validated['durations'],
        ];
    }

    /**
     * Tiers an agent may sell. Test tiers stay with the admin.
     *
     * @return list<PackageDurationTier>
     */
    protected function tiers(): array
    {
        return array_values(array_filter(PackageDurationTier::cases(), fn (PackageDurationTier $tier): bool => ! $tier->isTest()));
    }

    protected function authorizeAllocation(Request $request, InboundAllocation $allocation): void
    {
        abort_unless((int) $allocation->agent_user_id === (int) $request->user()->id, 404);
    }

    protected function authorizePackage(Request $request, Package $package): void
    {
        abort_unless(
            (int) $package->owner_agent_id === (int) $request->user()->id && $package->inboundAllocation !== null,
            404
        );
    }
}
