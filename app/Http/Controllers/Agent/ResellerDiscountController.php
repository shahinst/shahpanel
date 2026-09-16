<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Services\Pricing\ResellerDiscountService;
use App\Services\UserPackageAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Agent-facing page (Model 3): when the admin has granted this agent a "seller
 * markup range" (seller_markup_range_percent), the agent may set — per package —
 * how much above their OWN price they sell to their sellers (0 … range %). That
 * markup is the agent's profit. Without a range the page is read-only.
 */
class ResellerDiscountController extends Controller
{
    public function edit(Request $request): View
    {
        abort_unless(app(ResellerDiscountService::class)->isDiscountMode(), 404);

        $agent = $request->user();
        $discounts = app(ResellerDiscountService::class);
        $range = $agent->seller_markup_range_percent !== null ? (float) $agent->seller_markup_range_percent : null;

        $packages = app(UserPackageAssignmentService::class)
            ->assignedPackages($agent)
            ->load(['durations' => fn ($q) => $q->where('is_enabled', true)->orderBy('sort_order')]);

        $rows = [];
        foreach ($packages as $package) {
            $duration = $package->durations->first();
            if ($duration === null) {
                continue;
            }
            $retail = (float) $duration->price;
            $da = (float) $discounts->agentDiscountPercent($agent, $package);
            $ds = (float) $discounts->sellerDiscountPercent($agent, $package);
            $agentPrice = (float) $discounts->applyDiscount((string) $retail, (string) $da);
            $sellerPrice = (float) $discounts->applyDiscount((string) $retail, (string) $ds);
            $markup = $agentPrice > 0 ? round(($sellerPrice / $agentPrice - 1) * 100, 2) : 0.0;

            $rows[] = [
                'package' => $package,
                'retail' => $retail,
                'agent_price' => $agentPrice,
                'seller_price' => $sellerPrice,
                'markup' => max(0.0, $markup),
                'profit' => $sellerPrice - $agentPrice,
            ];
        }

        return view('agent.reseller-discount', ['agent' => $agent, 'rows' => $rows, 'range' => $range]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless(app(ResellerDiscountService::class)->isDiscountMode(), 404);

        $agent = $request->user();
        $range = $agent->seller_markup_range_percent !== null ? (float) $agent->seller_markup_range_percent : null;

        if ($range === null) {
            return back()->with('error', __('backend.reseller_pricing_not_allowed'));
        }

        $discounts = app(ResellerDiscountService::class);
        $validated = $request->validate([
            'markups' => ['nullable', 'array'],
            'markups.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $assignedIds = app(UserPackageAssignmentService::class)->assignedPackageIds($agent);
        $packages = \App\Models\Package::whereIn('id', $assignedIds)
            ->with(['durations' => fn ($q) => $q->where('is_enabled', true)->orderBy('sort_order')])
            ->get()->keyBy('id');

        foreach ((array) ($validated['markups'] ?? []) as $pid => $val) {
            $pid = (int) $pid;
            if (! isset($packages[$pid]) || $val === null || $val === '') {
                continue;
            }
            $package = $packages[$pid];
            $duration = $package->durations->first();
            if ($duration === null) {
                continue;
            }
            $retail = (float) $duration->price;
            $da = (float) $discounts->agentDiscountPercent($agent, $package);
            $agentPrice = (float) $discounts->applyDiscount((string) $retail, (string) $da);

            // clamp markup to the admin-granted range, and never above retail
            $markup = min($range, max(0.0, (float) $val));
            $sellerPrice = $discounts->roundNice($agentPrice * (1 + $markup / 100));
            if ($sellerPrice > $retail) {
                $sellerPrice = $retail;
            }
            $sellerDiscount = $retail > 0 ? max(0.0, min(100.0, (1 - $sellerPrice / $retail) * 100)) : 0.0;

            DB::table('reseller_package_discounts')->updateOrInsert(
                ['agent_user_id' => $agent->id, 'package_id' => $pid],
                ['seller_discount_percent' => number_format($sellerDiscount, 4, '.', ''), 'updated_at' => now()]
            );

            // Query-builder writes don't set timestamps: stamp created_at on insert only.
            DB::table('reseller_package_discounts')
                ->where('agent_user_id', $agent->id)->where('package_id', $pid)
                ->whereNull('created_at')->update(['created_at' => now()]);
        }

        return back()->with('success', __('backend.reseller_pricing_saved'));
    }
}
