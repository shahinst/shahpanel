<?php

namespace App\Services;

use App\Enums\PackageDurationTier;
use App\Enums\ServiceType;
use App\Models\Package;
use App\Models\PackageDuration;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PackageService
{
    /**
     * @param  array<string, array{price?: mixed, is_enabled?: mixed}>  $durationInput
     */
    public function syncDurations(Package $package, array $durationInput): void
    {
        $sort = 0;

        foreach (PackageDurationTier::cases() as $tier) {
            $row = $durationInput[$tier->value] ?? [];
            $enabled = filter_var($row['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $price = $enabled ? (float) ($row['price'] ?? 0) : (float) ($row['price'] ?? 0);

            if ($enabled && $price < 0) {
                throw new InvalidArgumentException(__('packages.invalid_duration_price', ['tier' => $tier->label()]));
            }

            PackageDuration::query()->updateOrCreate(
                ['package_id' => $package->id, 'tier' => $tier->value],
                [
                    'price' => $price,
                    'is_enabled' => $enabled,
                    'sort_order' => $sort++,
                ]
            );
        }

        $package = $package->fresh(['durations']);
        $this->syncLegacyPriceFields($package);
        app(UserPackagePricingService::class)->seedMissingWholesalePricesForPackage($package);
        app(AgentSellerMarkupReconciliationService::class)->reconcileForPackage($package);
    }

    /**
     * @param  list<int|string>  $serverIds
     */
    public function syncServers(Package $package, array $serverIds, ServiceType $serviceType): void
    {
        $ids = collect($serverIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw new InvalidArgumentException(__('packages.servers_required'));
        }

        $compatible = Server::query()
            ->compatibleWithServiceType($serviceType)
            ->whereIn('id', $ids)
            ->pluck('id');

        if ($compatible->count() !== $ids->count()) {
            throw new InvalidArgumentException(__('packages.incompatible_server'));
        }

        $package->servers()->sync($ids->all());
        $package->update(['default_server_id' => $ids->first()]);
    }

    public function syncLegacyPriceFields(Package $package): void
    {
        $enabled = $package->durations->where('is_enabled', true);

        $primary = $enabled->first(fn (PackageDuration $d): bool => $d->tier === PackageDurationTier::OneMonth)
            ?? $enabled->first(fn (PackageDuration $d): bool => ! $d->tier->hasNoTimeLimit())
            ?? $enabled->sortBy('sort_order')->first();

        $package->update([
            'base_price' => $primary?->price ?? 0,
            'duration_days' => $primary && ! $primary->tier->hasNoTimeLimit()
                ? (int) ceil($primary->tier->durationHours() / 24)
                : 30,
        ]);
    }

    /**
     * @return Collection<int, PackageDuration>
     */
    public function enabledDurationsFor(Package $package): Collection
    {
        return $package->durations()
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->get();
    }

    public function resolveDuration(Package $package, int $durationId): PackageDuration
    {
        $duration = PackageDuration::query()
            ->where('package_id', $package->id)
            ->where('id', $durationId)
            ->where('is_enabled', true)
            ->first();

        if ($duration === null) {
            throw new InvalidArgumentException(__('packages.duration_not_available'));
        }

        return $duration;
    }

    public function assertServerAllowed(Package $package, Server $server): void
    {
        $package->loadMissing('servers');

        if (! $package->servers->contains('id', $server->id)) {
            throw new InvalidArgumentException(__('packages.server_not_allowed'));
        }

        if (! $server->isCompatibleWithServiceType($package->service_type)) {
            throw new InvalidArgumentException(__('packages.incompatible_server'));
        }

        if (! $server->is_active) {
            throw new InvalidArgumentException(__('packages.server_inactive'));
        }
    }

    /**
     * JSON-ready options for account create forms.
     *
     * @return array<int, array{
     *     id: int,
     *     name: string,
     *     service_type: string,
     *     durations: list<array{id: int, label: string, price: string, is_test: bool}>,
     *     server_ids: list<int>
     * }>
     */
    public function accountFormOptions(?User $seller = null): array
    {
        $pricing = app(UserPackagePricingService::class);
        $categoryService = app(PackageCategoryService::class);
        $query = $seller !== null
            ? app(UserPackageAssignmentService::class)->assignedPackagesQuery($seller)
            : tap(Package::query(), fn ($query) => app(PackageCategoryService::class)->applyAvailableForNewAccounts($query));

        return $query
            ->with(array_merge(
                $categoryService->packageWithRelations(),
                [
                    'durations' => fn ($q) => $q->where('is_enabled', true)->orderBy('sort_order'),
                    'servers',
                ]
            ))
            ->orderBy('sort_order')
            ->get()
            ->pipe(fn ($packages) => $categoryService->sortPackages($packages))
            ->map(function (Package $package) use ($seller, $pricing): array {
                // The create-account UI formats every price client-side, so it needs the
                // package currency here — otherwise it falls back to Toman and a TRY
                // package is shown in the wrong currency.
                $currency = $package->moneyCurrency();

                return [
                    'id' => $package->id,
                    'name' => $package->name,
                    'currency' => $currency->value,
                    'currency_symbol' => $currency->symbol(),
                    'currency_label' => $currency->label(),
                    'currency_decimals' => $currency->displayDecimals(),
                    'category_id' => $package->package_category_id,
                    'category_name' => $package->category?->name,
                    'service_type' => $package->service_type->value,
                    'is_elastic' => $package->isElastic(),
                    'min_gb' => $package->isElastic() ? (float) ($package->min_data_gb ?? 1) : null,
                    'max_gb' => $package->isElastic() && $package->max_data_gb !== null ? (float) $package->max_data_gb : null,
                    'durations' => $package->durations->map(function (PackageDuration $d) use ($seller, $pricing): array {
                        $price = $seller !== null
                            ? ($pricing->wholesalePriceFor($seller, $d) ?? (string) $d->price)
                            : (string) $d->price;

                        return [
                            'id' => $d->id,
                            'label' => $d->displayLabel(),
                            'price' => $price,
                            'is_test' => $d->tier->isTest(),
                        ];
                    })->values()->all(),
                    'server_ids' => $package->servers->where('is_active', true)->pluck('id')->all(),
                    'kyc_required' => (bool) $package->kyc_required,
                ];
            })
            ->filter(fn (array $row): bool => $row['durations'] !== [] && $row['server_ids'] !== [])
            ->values()
            ->all();
    }

    /**
     * Delete a package while keeping linked VPN accounts active on their servers.
     *
     * @return array{active: int, detached: int} active = non-deleted accounts preserved; detached = all rows unlinked (incl. trashed)
     */
    public function deletePreservingAccounts(Package $package): array
    {
        return DB::transaction(function () use ($package): array {
            $packageId = (int) $package->id;

            $activeCount = (int) DB::table('accounts')
                ->where('package_id', $packageId)
                ->whereNull('deleted_at')
                ->count();

            $detachedCount = (int) DB::table('accounts')
                ->where('package_id', $packageId)
                ->count();

            if ($detachedCount > 0) {
                DB::table('accounts')
                    ->where('package_id', $packageId)
                    ->update([
                        'package_id' => null,
                        'updated_at' => now(),
                    ]);
            }

            $durationIds = DB::table('package_durations')
                ->where('package_id', $packageId)
                ->pluck('id');

            if ($durationIds->isNotEmpty()) {
                DB::table('accounts')
                    ->whereIn('package_duration_id', $durationIds)
                    ->update([
                        'package_duration_id' => null,
                        'updated_at' => now(),
                    ]);
            }

            $package->delete();

            return [
                'active' => $activeCount,
                'detached' => $detachedCount,
            ];
        });
    }
}
