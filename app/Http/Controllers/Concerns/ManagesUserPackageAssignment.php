<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Services\AgentSellerMarkupService;
use App\Services\PackageCategoryService;
use App\Services\UserPackageAssignmentService;
use App\Services\UserPackagePricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

trait ManagesUserPackageAssignment
{
    /**
     * @return array<string, mixed>
     */
    protected function packageAssignmentFormData(User $assigner, ?User $target = null, ?User $contextParent = null): array
    {
        $assignmentService = app(UserPackageAssignmentService::class);
        $pricingService = app(UserPackagePricingService::class);

        if ($target === null && $contextParent !== null) {
            if ($assigner->role === \App\Enums\UserRole::Admin) {
                $assignablePackages = $assignmentService->assignablePackagesFor($assigner);
            } else {
                $assignablePackages = $assignmentService->assignedPackages($contextParent);
            }
            $parentWholesalePrices = $pricingService->pricesForUser($contextParent);
        } else {
            $assignablePackages = $assignmentService->assignablePackagesFor($assigner, $target);
            $parentWholesalePrices = [];

            if ($target !== null && $target->role === \App\Enums\UserRole::Seller && $target->parent_id) {
                $parent = User::query()->find($target->parent_id);
                $parentWholesalePrices = $parent ? $pricingService->pricesForUser($parent) : [];
            } elseif ($assigner->role === \App\Enums\UserRole::Agent && $target === null) {
                $parentWholesalePrices = $pricingService->pricesForUser($assigner);
            }
        }

        $assignablePackages = $pricingService->assignablePackagesWithDurations($assignablePackages);
        $packageGroups = app(PackageCategoryService::class)->groupPackages($assignablePackages);
        $markupService = app(AgentSellerMarkupService::class);
        $assignerIsAgent = $assigner->role === \App\Enums\UserRole::Agent;
        $sellerMarkupApplies = $assignerIsAgent
            && ($target?->role === \App\Enums\UserRole::Seller || ($target === null && ! request()->routeIs('admin.users.*')))
            && $markupService->isEnabled();

        // Model 3: when an agent edits a seller and the admin granted the agent a
        // seller markup range, the agent may set this seller's per-package price
        // (bounded by that range). Build the per-package floor/ceiling/current rows.
        $discount = app(\App\Services\Pricing\ResellerDiscountService::class);
        $sellerPriceRange = null;
        $sellerPriceRows = collect();
        $isSellerContext = $target?->role === \App\Enums\UserRole::Seller
            || ($target === null && $assignerIsAgent && ! request()->routeIs('admin.users.*'));
        if ($discount->isDiscountMode() && $assignerIsAgent && $isSellerContext
            && $assigner->seller_markup_range_percent !== null) {
            $sellerPriceRange = (float) $assigner->seller_markup_range_percent;
            foreach ($assignablePackages as $pkg) {
                $duration = $pkg->durations->first();
                if ($duration === null) {
                    continue;
                }
                $retail = (float) $duration->price;
                $agentUnit = (float) $discount->applyDiscount((string) $retail, $discount->agentDiscountPercent($assigner, $pkg));
                if ($agentUnit <= 0) {
                    continue;
                }
                $ceil = $discount->sellerCeilingUnit($agentUnit, $retail, $sellerPriceRange);
                $override = $target !== null ? $discount->sellerPackagePriceOverride($target->id, $pkg->id) : null;
                $default = (float) $discount->applyDiscount((string) $retail, $discount->sellerDiscountPercent($assigner, $pkg));
                $current = $override !== null ? (float) $override : $default;
                $current = max($agentUnit, min($ceil, $current));
                $sellerPriceRows[$pkg->id] = [
                    'retail' => $retail,
                    'floor' => $agentUnit,
                    'ceil' => $ceil,
                    'current' => $current,
                ];
            }
        }

        return [
            'assignablePackages' => $assignablePackages,
            'packageGroups' => $packageGroups,
            'selectedPackageIds' => collect(
                $target !== null
                    ? $assignmentService->assignedPackageIds($target)
                    : old('package_ids', [])
            )
                ->map(fn ($id): int => (int) $id)
                ->intersect($assignablePackages->pluck('id')->map(fn ($id): int => (int) $id))
                ->values()
                ->all(),
            'wholesalePrices' => $target !== null ? $pricingService->pricesForUser($target) : [],
            'parentWholesalePrices' => $parentWholesalePrices,
            'pricingTargetRole' => $target?->role?->value
                ?? ($assigner->role === \App\Enums\UserRole::Admin && request()->routeIs('admin.users.*') ? 'agent' : 'seller'),
            'sellerMarkupApplies' => $sellerMarkupApplies,
            'sellerMarkupPercent' => $markupService->percent(),
            'markupService' => $markupService,
            // Model 3: per-package discount overrides for the target agent + defaults.
            'packageDiscounts' => ($target !== null && $target->role === \App\Enums\UserRole::Agent)
                ? DB::table('reseller_package_discounts')->where('agent_user_id', $target->id)->get()->keyBy('package_id')
                : collect(),
            'defaultAgentDiscount' => $target?->reseller_discount_percent,
            'defaultSellerDiscount' => $target?->seller_discount_percent,
            // Model 3: agent-set per-seller package prices (bounded by markup range).
            'sellerPriceRange' => $sellerPriceRange,
            'sellerPriceRows' => $sellerPriceRows,
        ];
    }

    protected function syncUserPackagesFromRequest(Request $request, User $target, User $assigner): void
    {
        $packageIds = $request->input('package_ids', []);

        app(UserPackageAssignmentService::class)->syncForUser(
            $target,
            $packageIds,
            $assigner
        );

        // Discount mode (Model 3): package assignment is still recorded above,
        // but per-duration wholesale prices are NOT used — pricing comes from the
        // reseller discount tiers. Admins set the agent's discount here.
        if (app(\App\Services\Pricing\ResellerDiscountService::class)->isDiscountMode()) {
            if ($assigner->role === \App\Enums\UserRole::Admin
                && $target->role === \App\Enums\UserRole::Agent) {
                // per-agent default discount + seller markup range
                $dirty = false;
                if ($request->has('reseller_discount_percent')) {
                    $raw = $request->input('reseller_discount_percent');
                    $target->reseller_discount_percent = ($raw === null || $raw === '')
                        ? null
                        : number_format(min(100, max(0, (float) $raw)), 4, '.', '');
                    $dirty = true;
                }
                if ($request->has('seller_markup_range_percent')) {
                    $raw = $request->input('seller_markup_range_percent');
                    $target->seller_markup_range_percent = ($raw === null || $raw === '')
                        ? null
                        : number_format(min(1000, max(0, (float) $raw)), 4, '.', '');
                    $dirty = true;
                }
                if ($dirty) {
                    $target->save();
                }

                // per-package overrides (agent + seller discount per package)
                $pkgDiscounts = (array) $request->input('package_discounts', []);
                $ids = array_map('intval', (array) $packageIds);
                foreach ($ids as $pid) {
                    $entry = $pkgDiscounts[$pid] ?? [];
                    $a = $entry['agent'] ?? null;
                    $s = $entry['seller'] ?? null;
                    $aVal = ($a === null || $a === '') ? null : min(100.0, max(0.0, (float) $a));
                    $sVal = ($s === null || $s === '') ? null : min(100.0, max(0.0, (float) $s));
                    if ($aVal !== null && $sVal !== null && $sVal > $aVal) {
                        $sVal = $aVal; // seller discount ≤ agent discount
                    }
                    if ($aVal === null && $sVal === null) {
                        DB::table('reseller_package_discounts')
                            ->where('agent_user_id', $target->id)->where('package_id', $pid)->delete();

                        continue;
                    }
                    DB::table('reseller_package_discounts')->updateOrInsert(
                        ['agent_user_id' => $target->id, 'package_id' => $pid],
                        [
                            'discount_percent' => $aVal === null ? null : number_format($aVal, 4, '.', ''),
                            'seller_discount_percent' => $sVal === null ? null : number_format($sVal, 4, '.', ''),
                            'updated_at' => now(),
                        ]
                    );

                    // Query-builder writes don't set timestamps: stamp created_at on insert only.
                    DB::table('reseller_package_discounts')
                        ->where('agent_user_id', $target->id)->where('package_id', $pid)
                        ->whereNull('created_at')->update(['created_at' => now()]);
                }
                // drop overrides for packages no longer assigned
                DB::table('reseller_package_discounts')
                    ->where('agent_user_id', $target->id)
                    ->whereNotIn('package_id', $ids ?: [0])
                    ->delete();
            }

            // An agent setting this seller's per-package prices, bounded by the
            // markup range the admin granted the agent.
            if ($assigner->role === \App\Enums\UserRole::Agent
                && $target->role === \App\Enums\UserRole::Seller
                && $assigner->seller_markup_range_percent !== null) {
                $this->syncSellerPackagePrices($request, $target, $assigner, array_map('intval', (array) $packageIds));
            }

            return;
        }

        if ($packageIds !== []) {
            app(UserPackagePricingService::class)->syncForAssignedPackages(
                $target,
                $packageIds,
                $request->input('package_prices', []),
                $assigner
            );
        } else {
            app(UserPackagePricingService::class)->syncForAssignedPackages(
                $target,
                [],
                [],
                $assigner
            );
        }
    }

    /**
     * Persist an agent's per-seller, per-package price overrides. Each price is
     * clamped to [agent's own price … agent price + markup range], capped at
     * retail, then rounded. Empty input removes the override (falls back to the
     * agent's uniform seller discount).
     *
     * @param  array<int, int>  $packageIds
     */
    protected function syncSellerPackagePrices(Request $request, User $seller, User $agent, array $packageIds): void
    {
        $discount = app(\App\Services\Pricing\ResellerDiscountService::class);
        $range = (float) $agent->seller_markup_range_percent;
        $input = (array) $request->input('seller_package_prices', []);
        $ids = array_values(array_unique(array_filter($packageIds)));

        $packages = \App\Models\Package::query()
            ->whereIn('id', $ids ?: [0])
            ->with(['durations' => fn ($q) => $q->where('is_enabled', true)->orderBy('sort_order')])
            ->get()->keyBy('id');

        foreach ($ids as $pid) {
            $package = $packages[$pid] ?? null;
            $duration = $package?->durations->first();

            $raw = $input[$pid] ?? null;
            if ($package === null || $duration === null || $raw === null || $raw === '') {
                DB::table('reseller_seller_package_prices')
                    ->where('seller_user_id', $seller->id)->where('package_id', $pid)->delete();

                continue;
            }

            $retail = (float) $duration->price;
            $agentUnit = (float) $discount->applyDiscount((string) $retail, $discount->agentDiscountPercent($agent, $package));
            $ceil = $discount->sellerCeilingUnit($agentUnit, $retail, $range);
            $price = $discount->roundNice(max($agentUnit, min($ceil, (float) $raw)));

            $now = now();
            $exists = DB::table('reseller_seller_package_prices')
                ->where('seller_user_id', $seller->id)->where('package_id', $pid)->exists();
            if ($exists) {
                DB::table('reseller_seller_package_prices')
                    ->where('seller_user_id', $seller->id)->where('package_id', $pid)
                    ->update(['wholesale_price' => number_format($price, 2, '.', ''), 'updated_at' => $now]);
            } else {
                DB::table('reseller_seller_package_prices')->insert([
                    'seller_user_id' => $seller->id,
                    'package_id' => $pid,
                    'wholesale_price' => number_format($price, 2, '.', ''),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Drop overrides for packages this seller is no longer assigned.
        DB::table('reseller_seller_package_prices')
            ->where('seller_user_id', $seller->id)
            ->whereNotIn('package_id', $ids ?: [0])
            ->delete();
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function packageAssignmentValidationRules(): array
    {
        return [
            'package_ids' => ['nullable', 'array'],
            'package_ids.*' => ['integer', 'exists:packages,id'],
            'package_prices' => ['nullable', 'array'],
            'package_prices.*' => ['array'],
            'package_prices.*.*' => ['nullable', 'numeric', 'min:0'],
            'reseller_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'seller_markup_range_percent' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'package_discounts' => ['nullable', 'array'],
            'package_discounts.*' => ['array'],
            'package_discounts.*.agent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'package_discounts.*.seller' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'seller_package_prices' => ['nullable', 'array'],
            'seller_package_prices.*' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
