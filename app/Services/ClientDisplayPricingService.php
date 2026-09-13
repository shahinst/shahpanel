<?php

namespace App\Services;

use App\Models\ClientDisplayPrice;
use App\Models\Package;
use App\Models\PackageDuration;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Retail display prices for the end-user client portal only.
 *
 * Does not affect staff wholesale tiers, invoices, processTieredPurchase,
 * or agent margin on seller-to-seller purchases — those use UserPackagePricingService.
 */
class ClientDisplayPricingService
{
    public function __construct(
        protected UserPackagePricingService $userPackagePricingService,
    ) {}
    /**
     * @return Collection<int, array{
     *     duration: PackageDuration,
     *     package: Package,
     *     list_price: string,
     *     display_price: string,
     *     retail_profit: string,
     *     is_visible: bool,
     *     is_custom: bool
     * }>
     */
    public function catalogForClient(User $client): Collection
    {
        $owner = app(EndUserService::class)->resolvePortalOwner($client);

        return $this->catalogForOwner($owner);
    }

    /**
     * @return Collection<int, array{
     *     duration: PackageDuration,
     *     package: Package,
     *     list_price: string,
     *     display_price: string,
     *     retail_profit: string,
     *     is_visible: bool,
     *     is_custom: bool
     * }>
     */
    public function managementCatalogForOwner(User $owner): Collection
    {
        return $this->buildOwnerCatalog($owner);
    }

    public function catalogForOwner(User $owner): Collection
    {
        return $this->buildOwnerCatalog($owner)
            ->filter(fn (array $row): bool => $row['is_visible']);
    }

    /**
     * @return Collection<int, array{
     *     duration: PackageDuration,
     *     package: Package,
     *     list_price: string,
     *     display_price: string,
     *     retail_profit: string,
     *     is_visible: bool,
     *     is_custom: bool
     * }>
     */
    protected function buildOwnerCatalog(User $owner): Collection
    {
        $overrides = collect();

        if (Schema::hasTable('client_display_prices')) {
            $overrides = ClientDisplayPrice::query()
                ->where('user_id', $owner->id)
                ->get()
                ->keyBy('package_duration_id');
        }

        $assignedIds = app(UserPackageAssignmentService::class)->assignedPackageIds($owner);

        if ($assignedIds === []) {
            return collect();
        }

        $categoryService = app(PackageCategoryService::class);

        $durations = PackageDuration::query()
            ->where('is_enabled', true)
            ->whereIn('package_id', $assignedIds)
            ->whereHas('package', fn ($q) => $q->where('is_active', true)->where('kyc_required', false))
            ->with($categoryService->packageDurationWithRelations())
            ->orderBy('package_id')
            ->orderBy('sort_order')
            ->get();

        $rows = $durations
            ->filter(fn (PackageDuration $duration): bool => $duration->package !== null)
            ->map(function (PackageDuration $duration) use ($overrides, $owner): array {
                $override = $overrides->get($duration->id);
                $listPrice = $this->userPackagePricingService->wholesalePriceFor($owner, $duration)
                    ?? number_format((float) $duration->price, 2, '.', '');

                if ($override !== null) {
                    $displayPrice = number_format((float) $override->display_price, 2, '.', '');

                    return [
                        'duration' => $duration,
                        'package' => $duration->package,
                        'list_price' => $listPrice,
                        'display_price' => $displayPrice,
                        'retail_profit' => $this->retailProfit($displayPrice, $listPrice),
                        'is_visible' => (bool) $override->is_visible,
                        'is_custom' => true,
                    ];
                }

                return [
                    'duration' => $duration,
                    'package' => $duration->package,
                    'list_price' => $listPrice,
                    'display_price' => $listPrice,
                    'retail_profit' => '0.00',
                    'is_visible' => true,
                    'is_custom' => false,
                ];
            });

        return $rows->values();
    }

    public function renewalDisplayUnitPrice(User $client, PackageDuration $duration): string
    {
        $row = $this->catalogForClient($client)
            ->first(fn (array $item): bool => (int) $item['duration']->id === (int) $duration->id);

        if ($row === null) {
            throw new InvalidArgumentException(__('packages.duration_not_available'));
        }

        return $row['display_price'];
    }

    protected function retailProfit(string $displayPrice, string $wholesalePrice): string
    {
        if ($this->moneyCompare($displayPrice, $wholesalePrice) <= 0) {
            return '0.00';
        }

        return $this->moneySub($displayPrice, $wholesalePrice);
    }

    /**
     * @param  array<int, array{display_price?: mixed, is_visible?: mixed}>  $rows
     */
    public function syncForOwner(User $owner, array $rows): void
    {
        if (! Schema::hasTable('client_display_prices')) {
            return;
        }

        foreach ($rows as $durationId => $row) {
            $durationId = (int) $durationId;
            $duration = PackageDuration::query()->find($durationId);

            if ($duration === null) {
                continue;
            }

            $visible = filter_var($row['is_visible'] ?? true, FILTER_VALIDATE_BOOLEAN);
            $price = (float) ($row['display_price'] ?? 0);

            if (! $visible) {
                ClientDisplayPrice::query()->updateOrCreate(
                    ['user_id' => $owner->id, 'package_duration_id' => $durationId],
                    ['display_price' => max(0, $price), 'is_visible' => false]
                );

                continue;
            }

            if ($price <= 0) {
                ClientDisplayPrice::query()
                    ->where('user_id', $owner->id)
                    ->where('package_duration_id', $durationId)
                    ->delete();

                continue;
            }

            $wholesale = $this->userPackagePricingService->wholesalePriceFor($owner, $duration);

            if ($wholesale !== null && $this->moneyCompare(number_format($price, 2, '.', ''), $wholesale) < 0) {
                throw new InvalidArgumentException(__('clients.display_price_below_wholesale'));
            }

            ClientDisplayPrice::query()->updateOrCreate(
                ['user_id' => $owner->id, 'package_duration_id' => $durationId],
                ['display_price' => $price, 'is_visible' => true]
            );
        }
    }

    /** @return int -1, 0, or 1 (like bccomp) */
    protected function moneyCompare(string $left, string $right): int
    {
        if (function_exists('bccomp')) {
            return bccomp($left, $right, 2);
        }

        $l = round((float) $left, 2);
        $r = round((float) $right, 2);

        return $l <=> $r;
    }

    protected function moneySub(string $left, string $right): string
    {
        if (function_exists('bcsub')) {
            return bcsub($left, $right, 2);
        }

        return number_format(max(0, round((float) $left - (float) $right, 2)), 2, '.', '');
    }
}
