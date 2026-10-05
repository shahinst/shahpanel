<?php

namespace Modules\Dedicated\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Package;
use App\Models\User;
use App\Models\UserPackageDurationPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The commission terms an agent gives each of their sellers on the agent's
 * own packages.
 *
 * It is the core's commission model: every seller has their own wholesale
 * price per tier, and the agent earns whatever the seller pays. The price is
 * kept at or below the package's list price -- a commission makes a seller's
 * price lower, it never turns a seller into someone paying more than retail.
 */
class CommissionController extends Controller
{
    public function index(Request $request): View
    {
        $agent = $this->agent($request);
        $sellers = $this->sellersOf($agent);
        $seller = $sellers->firstWhere('id', (int) $request->query('seller')) ?? $sellers->first();
        $packages = $this->ownPackages($agent);

        $prices = $seller === null ? collect() : UserPackageDurationPrice::query()
            ->where('user_id', $seller->id)
            ->whereIn('package_duration_id', $packages->flatMap->durations->pluck('id'))
            ->pluck('wholesale_price', 'package_duration_id');

        return view('dedicated::agent.commissions', compact('sellers', 'seller', 'packages', 'prices'));
    }

    public function update(Request $request, User $seller): RedirectResponse
    {
        $agent = $this->agent($request);
        abort_unless($seller->role === UserRole::Seller && (int) $seller->parent_id === (int) $agent->id, 404);

        $data = $request->validate([
            'percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'prices' => ['nullable', 'array'],
            'prices.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $durations = $this->ownPackages($agent)->flatMap->durations->keyBy('id');
        $percent = isset($data['percent']) && $data['percent'] !== '' ? (float) $data['percent'] : null;

        DB::transaction(function () use ($durations, $data, $percent, $seller): void {
            foreach ($durations as $id => $duration) {
                $list = (float) $duration->price;

                // A percentage fills every tier at once; a typed price wins
                // over it for the tier it was typed in.
                $raw = $data['prices'][$id] ?? null;
                $price = $raw !== null && $raw !== ''
                    ? (float) $raw
                    : ($percent !== null ? $list * (1 - $percent / 100) : null);

                if ($price === null) {
                    UserPackageDurationPrice::query()
                        ->where('user_id', $seller->id)->where('package_duration_id', $id)->delete();

                    continue;
                }

                UserPackageDurationPrice::query()->updateOrCreate(
                    ['user_id' => $seller->id, 'package_duration_id' => $id],
                    ['wholesale_price' => number_format(min($list, max(0, $price)), 2, '.', '')],
                );
            }
        });

        return redirect()
            ->route('agent.dedicated.commissions', ['seller' => $seller->id])
            ->with('success', __('dedicated::admin.commission_saved'));
    }

    protected function agent(Request $request): User
    {
        $agent = $request->user();
        abort_unless($agent?->role === UserRole::Agent
            && Package::query()->where('owner_agent_id', $agent->id)->exists(), 403);

        return $agent;
    }

    /** @return Collection<int, User> */
    protected function sellersOf(User $agent): Collection
    {
        return User::query()->where('parent_id', $agent->id)
            ->where('role', UserRole::Seller)->orderBy('full_name')->get();
    }

    /** @return Collection<int, Package> */
    protected function ownPackages(User $agent): Collection
    {
        return Package::query()->where('owner_agent_id', $agent->id)
            ->with(['durations' => fn ($q) => $q->where('is_enabled', true)->orderBy('sort_order')])
            ->orderBy('name')->get();
    }
}
