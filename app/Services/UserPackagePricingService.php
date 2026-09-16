<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Package;
use App\Models\PackageDuration;
use App\Models\User;
use App\Models\UserPackageDurationPrice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class UserPackagePricingService
{
    public function __construct(
        protected UserHierarchyService $userHierarchyService,
        protected \App\Services\Pricing\ResellerDiscountService $resellerDiscount,
    ) {}

    /**
     * @return array<string, string>  package_duration_id => wholesale_price
     */
    public function pricesForUser(User $user): array
    {
        if (! Schema::hasTable('user_package_duration_prices')) {
            return [];
        }

        return UserPackageDurationPrice::query()
            ->where('user_id', $user->id)
            ->pluck('wholesale_price', 'package_duration_id')
            ->map(fn ($price): string => number_format((float) $price, 2, '.', ''))
            ->all();
    }

    public function wholesalePriceFor(User $user, PackageDuration $duration): ?string
    {
        // Model 3 (discount mode): derive the wholesale unit price from the
        // fixed retail price and the reseller's discount tier. In legacy mode the
        // original per-user assigned-price logic below is used unchanged.
        if ($this->resellerDiscount->isDiscountMode()) {
            return $this->discountWholesaleUnit($user, $duration);
        }

        if (! Schema::hasTable('user_package_duration_prices')) {
            return number_format((float) $duration->price, 2, '.', '');
        }

        $row = UserPackageDurationPrice::query()
            ->where('user_id', $user->id)
            ->where('package_duration_id', $duration->id)
            ->first();

        if ($row === null) {
            // Staff tiers must use assigned wholesale rows — not live catalog prices.
            if (in_array($user->role, [UserRole::Agent, UserRole::Seller], true)) {
                return null;
            }

            return $this->catalogWholesalePrice($duration);
        }

        return number_format((float) $row->wholesale_price, 2, '.', '');
    }

    /**
     * Discount-mode wholesale UNIT price. Agent pays retail×(1−agentDiscount);
     * seller pays retail×(1−sellerDiscount). Everything downstream
     * (lineTotal → resolvePurchaseEconomics) then works unchanged, because the
     * commission is still computed as the difference between the two tiers.
     */
    protected function discountWholesaleUnit(User $user, PackageDuration $duration): ?string
    {
        $duration->loadMissing('package');
        $package = $duration->package;
        $retailUnit = number_format((float) $duration->price, 2, '.', '');

        if (bccomp($retailUnit, '0', 2) <= 0) {
            return null;
        }

        if ($user->role === UserRole::Agent) {
            return $this->resellerDiscount->applyDiscount(
                $retailUnit,
                $this->resellerDiscount->agentDiscountPercent($user, $package),
            );
        }

        if ($user->role === UserRole::Seller) {
            $agent = $user->parent;
            if ($agent === null || $agent->role !== UserRole::Agent) {
                return null;
            }

            // Per-seller override (agent set this seller's price from the seller-edit
            // page): honoured only while the agent has a markup range, and always
            // clamped to [agent's own price … agent price + range], capped at retail.
            $range = $agent->seller_markup_range_percent;
            if ($range !== null && $package !== null) {
                $override = $this->resellerDiscount->sellerPackagePriceOverride($user->id, $package->id);
                if ($override !== null) {
                    $agentUnit = (float) $this->resellerDiscount->applyDiscount(
                        $retailUnit,
                        $this->resellerDiscount->agentDiscountPercent($agent, $package),
                    );
                    $ceil = $this->resellerDiscount->sellerCeilingUnit($agentUnit, (float) $retailUnit, (float) $range);
                    $price = max($agentUnit, min($ceil, (float) $override));

                    return number_format($this->resellerDiscount->roundNice($price), 2, '.', '');
                }
            }

            return $this->resellerDiscount->applyDiscount(
                $retailUnit,
                $this->resellerDiscount->sellerDiscountPercent($agent, $package),
            );
        }

        // Admin / other roles: retail (admin uses an infinite wallet anyway).
        return $retailUnit;
    }

    public function requireWholesalePrice(User $user, PackageDuration $duration): string
    {
        $price = $this->wholesalePriceFor($user, $duration);

        if ($price === null || bccomp($price, '0', 2) <= 0) {
            throw new InvalidArgumentException(__('packages.wholesale_price_missing', [
                'package' => $duration->package?->name ?? $duration->package_id,
                'duration' => $duration->displayLabel(),
            ]));
        }

        return $price;
    }

    /**
     * When an account moves to a new billing package, copy wholesale rows by duration tier
     * so agent/seller prices are not lost (and catalog fallback is never used).
     */
    public function mirrorWholesalePricesBetweenPackages(User $seller, Package $fromPackage, Package $toPackage): void
    {
        if (! Schema::hasTable('user_package_duration_prices')) {
            return;
        }

        $fromPackage->loadMissing(['durations']);
        $toPackage->loadMissing(['durations']);

        if ($fromPackage->durations->isEmpty() || $toPackage->durations->isEmpty()) {
            return;
        }

        $users = collect([$seller]);

        if ($seller->parent !== null && $seller->parent->role === UserRole::Agent) {
            $users->push($seller->parent);
        }

        foreach ($users as $user) {
            foreach ($fromPackage->durations as $fromDuration) {
                $toDuration = $toPackage->durations
                    ->first(fn (PackageDuration $row): bool => $row->tier === $fromDuration->tier && $row->is_enabled);

                if ($toDuration === null) {
                    continue;
                }

                $existingTarget = UserPackageDurationPrice::query()
                    ->where('user_id', $user->id)
                    ->where('package_duration_id', $toDuration->id)
                    ->first();

                $source = UserPackageDurationPrice::query()
                    ->where('user_id', $user->id)
                    ->where('package_duration_id', $fromDuration->id)
                    ->first();

                if ($source === null) {
                    continue;
                }

                if ($existingTarget !== null && $existingTarget->assigned_by_user_id !== null) {
                    continue;
                }

                UserPackageDurationPrice::query()->updateOrCreate(
                    [
                        'user_id' => $user->id,
                        'package_duration_id' => $toDuration->id,
                    ],
                    [
                        'wholesale_price' => $source->wholesale_price,
                        'assigned_by_user_id' => $source->assigned_by_user_id,
                    ]
                );
            }
        }
    }

    /**
     * Catalog (admin) price for a duration — used when no per-user wholesale row exists yet.
     */
    public function catalogWholesalePrice(PackageDuration $duration): ?string
    {
        $catalog = number_format((float) $duration->price, 2, '.', '');

        return bccomp($catalog, '0', 2) > 0 ? $catalog : null;
    }

    /**
     * Catalog (admin base) line total: package_duration.price × billable units.
     */
    public function catalogLineTotal(PackageDuration $duration, ?float $gb = null): ?string
    {
        $duration->loadMissing('package');
        $catalog = $this->catalogWholesalePrice($duration);

        if ($catalog === null) {
            return null;
        }

        $units = $this->unitsFor($duration->package, $gb);

        return number_format((float) bcmul($catalog, $units, 4), 2, '.', '');
    }

    /**
     * Catalog line total for renewal (no max_data_gb clamp on volume).
     */
    public function catalogRenewalLineTotal(PackageDuration $duration, ?float $gb = null): ?string
    {
        $duration->loadMissing('package');
        $catalog = $this->catalogWholesalePrice($duration);

        if ($catalog === null) {
            return null;
        }

        $units = $this->renewalUnitsFor($duration->package, $gb);

        return number_format((float) bcmul($catalog, $units, 4), 2, '.', '');
    }

    /**
     * When a package gains new enabled durations, copy catalog prices for assignees missing a row.
     */
    public function seedMissingWholesalePricesForPackage(Package $package): void
    {
        if (! Schema::hasTable('user_package_duration_prices')) {
            return;
        }

        $package->loadMissing([
            'durations' => fn ($q) => $q->where('is_enabled', true),
            'assignedUsers',
        ]);

        if ($package->assignedUsers->isEmpty() || $package->durations->isEmpty()) {
            return;
        }

        foreach ($package->assignedUsers as $user) {
            if (! in_array($user->role, [UserRole::Agent, UserRole::Seller], true)) {
                continue;
            }

            foreach ($package->durations as $duration) {
                $catalog = $this->catalogWholesalePrice($duration);

                if ($catalog === null) {
                    continue;
                }

                $exists = UserPackageDurationPrice::query()
                    ->where('user_id', $user->id)
                    ->where('package_duration_id', $duration->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                UserPackageDurationPrice::query()->create([
                    'user_id' => $user->id,
                    'package_duration_id' => $duration->id,
                    'wholesale_price' => $catalog,
                    'assigned_by_user_id' => null,
                ]);
            }
        }
    }

    /**
     * Number of billable units for a purchase. For elastic (accordion) packages
     * this is the chosen GB; for fixed packages it is always "1".
     */
    public function unitsFor(?Package $package, ?float $gb): string
    {
        if ($package !== null && $package->isElastic()) {
            $clamped = $package->clampDataGb($gb !== null ? $gb : (float) ($package->min_data_gb ?? 1));

            return number_format($clamped, 2, '.', '');
        }

        return '1';
    }

    /**
     * Line total for a buyer: wholesale unit × volume (elastic) or wholesale alone (fixed).
     */
    public function lineTotal(
        User $buyer,
        PackageDuration $duration,
        ?float $dataGb = null,
        bool $forRenewal = false,
    ): string {
        $duration->loadMissing('package');
        $unitPrice = $this->requireWholesalePrice($buyer, $duration);
        $package = $duration->package;

        if ($package === null || ! $package->isElastic()) {
            return $unitPrice;
        }

        $units = $forRenewal
            ? $this->renewalUnitsFor($package, $dataGb)
            : $this->unitsFor($package, $dataGb);

        return number_format((float) bcmul($unitPrice, $units, 4), 2, '.', '');
    }

    /**
     * Total wholesale price a buyer pays on purchase.
     */
    public function buyerWholesaleTotal(User $buyer, PackageDuration $duration, ?float $gb = null): string
    {
        return $this->lineTotal($buyer, $duration, $gb, forRenewal: false);
    }

    /**
     * Total wholesale price a buyer pays on renewal.
     */
    public function buyerRenewalWholesaleTotal(User $buyer, PackageDuration $duration, ?float $gb = null): string
    {
        return $this->lineTotal($buyer, $duration, $gb, forRenewal: true);
    }

    /**
     * Billable GB for renewal (elastic only). Honors min_data_gb, not max_data_gb.
     */
    public function renewalUnitsFor(?Package $package, ?float $gb): string
    {
        if ($package !== null && $package->isElastic()) {
            $min = $package->min_data_gb !== null ? (float) $package->min_data_gb : 1.0;
            $units = $gb !== null && $gb > 0 ? (float) $gb : $min;

            if ($units < $min) {
                $units = $min;
            }

            return number_format($units, 2, '.', '');
        }

        return '1';
    }

    /**
     * @return array{
     *     buyer: User,
     *     buyer_wholesale: string,
     *     buyer_charge: string,
     *     agent: ?User,
     *     agent_wholesale: string,
     *     agent_margin: string,
     *     admin: User,
     *     admin_revenue: string
     * }
     */
    public function resolvePurchaseEconomics(
        User $buyer,
        PackageDuration $duration,
        ?string $discountedBuyerCharge = null,
        ?float $gb = null,
        bool $forRenewal = false,
        bool $scaleCommissionsToCharge = false,
    ): array {
        $buyerWholesale = $forRenewal
            ? $this->buyerRenewalWholesaleTotal($buyer, $duration, $gb)
            : $this->buyerWholesaleTotal($buyer, $duration, $gb);

        $duration->loadMissing('package');
        $units = $duration->package?->isElastic()
            ? ($forRenewal ? $this->renewalUnitsFor($duration->package, $gb) : $this->unitsFor($duration->package, $gb))
            : '1';
        $chain = $this->userHierarchyService->resolveCommissionChain($buyer);
        $admin = $chain['admin'];
        $buyerCharge = $discountedBuyerCharge ?? $buyerWholesale;

        if ($buyer->role === UserRole::Agent) {
            return [
                'buyer' => $buyer,
                'buyer_wholesale' => $buyerWholesale,
                'buyer_charge' => $buyerCharge,
                'agent' => null,
                'agent_wholesale' => '0.00',
                'agent_margin' => '0.00',
                'admin' => $admin,
                'admin_revenue' => $scaleCommissionsToCharge ? $buyerCharge : $buyerWholesale,
            ];
        }

        if ($buyer->role !== UserRole::Seller) {
            throw new InvalidArgumentException(__('packages.invalid_purchase_buyer'));
        }

        $agent = $buyer->parent;

        if ($agent === null || $agent->role !== UserRole::Agent) {
            throw new InvalidArgumentException(__('sellers.parent_hint'));
        }

        $agentWholesale = number_format(
            (float) bcmul($this->requireWholesalePrice($agent, $duration), $units, 4),
            2,
            '.',
            ''
        );

        if (! $scaleCommissionsToCharge && bccomp($buyerWholesale, $agentWholesale, 2) < 0) {
            throw new InvalidArgumentException(__('packages.seller_wholesale_below_agent'));
        }

        $agentMargin = bccomp($buyerWholesale, $agentWholesale, 2) > 0
            ? bcsub($buyerWholesale, $agentWholesale, 2)
            : '0.00';

        $markupService = app(AgentSellerMarkupService::class);

        // In discount mode the agent margin is exactly the discount spread
        // (agentDiscount − sellerDiscount); the legacy markup cap must not clip it.
        if (! $this->resellerDiscount->isDiscountMode() && $markupService->isEnabled()) {
            $agentUnitPrice = $this->requireWholesalePrice($agent, $duration);
            $maxMarginTotal = number_format(
                (float) bcmul($markupService->maxMarginPerUnit($agentUnitPrice), $units, 4),
                2,
                '.',
                ''
            );

            if (bccomp($agentMargin, $maxMarginTotal, 2) > 0) {
                $agentMargin = $maxMarginTotal;
            }
        }

        $adminRevenue = bcsub($buyerWholesale, $agentMargin, 2);

        if ($scaleCommissionsToCharge) {
            [$agentMargin, $adminRevenue] = $this->scaleCommissionSplit(
                $buyerCharge,
                $buyerWholesale,
                $agentMargin,
                $adminRevenue,
            );
        }

        return [
            'buyer' => $buyer,
            'buyer_wholesale' => $buyerWholesale,
            'buyer_charge' => $buyerCharge,
            'agent' => $agent,
            'agent_wholesale' => $agentWholesale,
            'agent_margin' => $agentMargin,
            'admin' => $admin,
            'admin_revenue' => $adminRevenue,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function scaleCommissionSplit(
        string $buyerCharge,
        string $buyerWholesale,
        string $agentMargin,
        string $adminRevenue,
    ): array {
        if (bccomp($buyerWholesale, '0', 2) <= 0) {
            return ['0.00', $buyerCharge];
        }

        $scale = bcdiv($buyerCharge, $buyerWholesale, 6);

        return [
            bcmul($agentMargin, $scale, 2),
            bcmul($adminRevenue, $scale, 2),
        ];
    }

    /**
     * @param  list<int|string>  $packageIds
     * @param  array<int|string, array<int|string, mixed>>  $priceInput
     */
    public function syncForAssignedPackages(User $target, array $packageIds, array $priceInput, User $assigner): void
    {
        if (! Schema::hasTable('user_package_duration_prices')) {
            return;
        }

        if (! in_array($target->role, [UserRole::Agent, UserRole::Seller], true)) {
            return;
        }

        $packageIds = collect($packageIds)->map(fn ($id): int => (int) $id)->unique()->values();

        if ($packageIds->isEmpty()) {
            UserPackageDurationPrice::query()->where('user_id', $target->id)->delete();

            return;
        }

        $packages = Package::query()
            ->whereIn('id', $packageIds)
            ->with(['durations' => fn ($q) => $q->where('is_enabled', true)->orderBy('sort_order')])
            ->get();

        $parentFloor = $target->role === UserRole::Seller
            ? $this->pricesForUser($this->resolveSellerParent($target))
            : [];

        $catalogPrices = PackageDuration::query()
            ->whereIn('package_id', $packageIds)
            ->where('is_enabled', true)
            ->pluck('price', 'id');

        foreach ($packages as $package) {
            foreach ($package->durations as $duration) {
                $raw = $priceInput[$package->id][$duration->id]
                    ?? $priceInput[(string) $package->id][(string) $duration->id]
                    ?? null;

                if ($raw === null || $raw === '') {
                    throw new InvalidArgumentException(__('packages.wholesale_price_required', [
                        'package' => $package->name,
                        'duration' => $duration->displayLabel(),
                    ]));
                }

                $price = number_format((float) $raw, 2, '.', '');

                if (bccomp($price, '0', 2) < 0) {
                    throw new InvalidArgumentException(__('packages.invalid_wholesale_price'));
                }

                if ($target->role === UserRole::Seller) {
                    $floor = $parentFloor[(string) $duration->id] ?? $parentFloor[$duration->id] ?? null;

                    if ($floor === null && $assigner->role === UserRole::Admin) {
                        $floor = number_format((float) ($catalogPrices[$duration->id] ?? 0), 2, '.', '');
                    }

                    if ($floor === null) {
                        throw new InvalidArgumentException(__('packages.agent_wholesale_missing_for_duration'));
                    }

                    if (bccomp($price, $floor, 2) < 0) {
                        throw new InvalidArgumentException(__('packages.seller_wholesale_below_agent'));
                    }

                    $markupService = app(AgentSellerMarkupService::class);
                    $markupActive = $assigner->role === UserRole::Agent && $markupService->isEnabled();

                    if ($markupActive) {
                        $ceiling = $markupService->maxSellerUnitPrice($floor);

                        if (bccomp($price, $ceiling, 2) > 0) {
                            throw new InvalidArgumentException(__('packages.seller_wholesale_above_markup', [
                                'package' => $package->name,
                                'duration' => $duration->displayLabel(),
                                'percent' => persian_digits(number_format($markupService->percent(), 2, '.', '')),
                                'price' => format_money($ceiling, $package->moneyCurrency()),
                                'margin' => format_money($markupService->maxMarginPerUnit($floor), $package->moneyCurrency()),
                            ]));
                        }
                    } else {
                        $catalogCeiling = number_format((float) ($catalogPrices[$duration->id] ?? 0), 2, '.', '');

                        if (bccomp($catalogCeiling, '0', 2) > 0 && bccomp($price, $catalogCeiling, 2) > 0) {
                            throw new InvalidArgumentException(__('packages.seller_wholesale_above_catalog', [
                                'package' => $package->name,
                                'duration' => $duration->displayLabel(),
                                'price' => format_money($catalogCeiling, $package->moneyCurrency()),
                            ]));
                        }
                    }
                }

                if ($assigner->role === UserRole::Admin && $target->role === UserRole::Agent) {
                    $catalog = number_format((float) ($catalogPrices[$duration->id] ?? 0), 2, '.', '');

                    if (bccomp($price, '0', 2) === 0 && bccomp($catalog, '0', 2) > 0) {
                        throw new InvalidArgumentException(__('packages.agent_wholesale_required'));
                    }
                }

                UserPackageDurationPrice::query()->updateOrCreate(
                    [
                        'user_id' => $target->id,
                        'package_duration_id' => $duration->id,
                    ],
                    [
                        'wholesale_price' => $price,
                        'assigned_by_user_id' => $assigner->id,
                    ]
                );
            }
        }

        UserPackageDurationPrice::query()
            ->where('user_id', $target->id)
            ->whereNotIn('package_duration_id', function ($query) use ($packageIds): void {
                $query->select('id')
                    ->from('package_durations')
                    ->whereIn('package_id', $packageIds);
            })
            ->delete();

        if ($target->role === UserRole::Agent) {
            app(AgentSellerMarkupReconciliationService::class)->reconcileForAgent($target);
        }
    }

    /**
     * @return Collection<int, Package>
     */
    public function assignablePackagesWithDurations(Collection $packages): Collection
    {
        $categoryService = app(PackageCategoryService::class);

        return $categoryService->sortPackages($packages->load(array_merge(
            $categoryService->packageWithRelations(),
            [
                'durations' => fn ($q) => $q->where('is_enabled', true)->orderBy('sort_order'),
            ]
        )));
    }

    protected function resolveSellerParent(User $seller): User
    {
        if ($seller->parent_id === null) {
            throw new InvalidArgumentException(__('sellers.parent_hint'));
        }

        $parent = User::query()->findOrFail((int) $seller->parent_id);

        if ($parent->role !== UserRole::Agent) {
            throw new InvalidArgumentException(__('sellers.parent_hint'));
        }

        return $parent;
    }
}
