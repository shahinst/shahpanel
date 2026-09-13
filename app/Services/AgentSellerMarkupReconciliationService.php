<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Package;
use App\Models\PackageDuration;
use App\Models\User;
use App\Models\UserPackageDurationPrice;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps seller wholesale rows within [agent floor, markup max] after catalog or policy changes.
 */
class AgentSellerMarkupReconciliationService
{
    public function __construct(
        protected UserPackagePricingService $pricingService,
        protected AgentSellerMarkupService $markupService,
    ) {}

    public function reconcileAll(): int
    {
        if (! Schema::hasTable('user_package_duration_prices')) {
            return 0;
        }

        $updated = 0;

        User::query()
            ->where('role', UserRole::Seller)
            ->whereNotNull('parent_id')
            ->with('parent')
            ->chunkById(100, function ($sellers) use (&$updated): void {
                foreach ($sellers as $seller) {
                    $updated += $this->reconcileSeller($seller);
                }
            });

        return $updated;
    }

    public function reconcileForPackage(Package $package): int
    {
        if (! Schema::hasTable('user_package_duration_prices')) {
            return 0;
        }

        $package->loadMissing([
            'durations' => fn ($q) => $q->where('is_enabled', true),
            'assignedUsers',
        ]);

        $durationIds = $package->durations->pluck('id')->all();

        if ($durationIds === []) {
            return 0;
        }

        $updated = 0;

        foreach ($package->assignedUsers as $user) {
            if ($user->role !== UserRole::Seller || $user->parent_id === null) {
                continue;
            }

            $updated += $this->reconcileSeller($user, $durationIds);
        }

        return $updated;
    }

    public function reconcileForAgent(User $agent, ?array $durationIds = null): int
    {
        if ($agent->role !== UserRole::Agent || ! Schema::hasTable('user_package_duration_prices')) {
            return 0;
        }

        $updated = 0;

        User::query()
            ->where('role', UserRole::Seller)
            ->where('parent_id', $agent->id)
            ->chunkById(100, function ($sellers) use (&$updated, $durationIds): void {
                foreach ($sellers as $seller) {
                    $updated += $this->reconcileSeller($seller, $durationIds);
                }
            });

        return $updated;
    }

    /**
     * @param  list<int>|null  $onlyDurationIds
     */
    public function reconcileSeller(User $seller, ?array $onlyDurationIds = null): int
    {
        if ($seller->role !== UserRole::Seller || $seller->parent_id === null) {
            return 0;
        }

        $agent = $seller->relationLoaded('parent')
            ? $seller->parent
            : User::query()->find($seller->parent_id);

        if ($agent === null || $agent->role !== UserRole::Agent) {
            return 0;
        }

        $query = UserPackageDurationPrice::query()->where('user_id', $seller->id);

        if ($onlyDurationIds !== null) {
            $query->whereIn('package_duration_id', $onlyDurationIds);
        }

        $updated = 0;

        foreach ($query->cursor() as $row) {
            $duration = PackageDuration::query()->with('package')->find($row->package_duration_id);

            if ($duration === null) {
                continue;
            }

            $bounds = $this->sellerPriceBounds($agent, $duration);

            if ($bounds === null) {
                continue;
            }

            [$floor, $ceiling] = $bounds;
            $price = number_format((float) $row->wholesale_price, 2, '.', '');
            $clamped = $price;

            if (bccomp($clamped, $floor, 2) < 0) {
                $clamped = $floor;
            }

            if (bccomp($ceiling, '0', 2) > 0 && bccomp($clamped, $ceiling, 2) > 0) {
                $clamped = $ceiling;
            }

            if ($clamped !== $price) {
                $row->update(['wholesale_price' => $clamped]);
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * @return array{0: string, 1: string}|null  [floor, ceiling]
     */
    public function sellerPriceBounds(User $agent, PackageDuration $duration): ?array
    {
        try {
            $floor = $this->pricingService->requireWholesalePrice($agent, $duration);
        } catch (\Throwable) {
            return null;
        }

        if ($this->markupService->isEnabled()) {
            $ceiling = $this->markupService->maxSellerUnitPrice($floor);
        } else {
            $ceiling = $this->pricingService->catalogWholesalePrice($duration) ?? $floor;
        }

        return [
            number_format((float) $floor, 2, '.', ''),
            number_format((float) $ceiling, 2, '.', ''),
        ];
    }
}
