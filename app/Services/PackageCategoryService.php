<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PackageCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PackageCategoryService
{
    public function isAvailable(): bool
    {
        static $available = null;

        if ($available === null) {
            $available = Schema::hasTable('package_categories')
                && Schema::hasColumn('packages', 'package_category_id');
        }

        return $available;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function packageWithRelations(): array
    {
        return $this->isAvailable() ? ['category'] : [];
    }

    /**
     * Eager loads for PackageDuration queries (category lives on Package, not Duration).
     *
     * @return list<string>
     */
    public function packageDurationWithRelations(): array
    {
        return $this->isAvailable() ? ['package.category'] : ['package'];
    }

    /**
     * Packages eligible for new account creation (active package + active category).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Package>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Package>
     */
    public function applyAvailableForNewAccounts($query)
    {
        $query->where($query->getModel()->getTable().'.is_active', true);

        if ($this->isAvailable()) {
            $query->where(function ($inner): void {
                $inner->whereNull('package_category_id')
                    ->orWhereHas('category', fn ($category) => $category->where('is_active', true));
            });
        }

        return $query;
    }

    public function isPackageAvailableForNewAccounts(Package $package): bool
    {
        if (! $package->is_active) {
            return false;
        }

        if (! $this->isAvailable()) {
            return true;
        }

        $package->loadMissing('category');
        $category = $package->category;

        return $category === null || $category->is_active;
    }

    /**
     * Renewal availability is deliberately DECOUPLED from sales availability.
     *
     * Deactivating a package (or its category) means "stop selling this to new
     * customers" — it must NOT strand the customers who already own an account
     * on it. Tying the two together silently removed the renew button from every
     * existing account as soon as a package was archived.
     *
     * An existing package can therefore always be renewed; the remaining guards
     * (account refunded, package missing, no enabled duration, insufficient
     * balance) are enforced by the renewal flow itself.
     */
    public function isPackageAvailableForRenewal(Package $package): bool
    {
        return true;
    }

    public function assertPackageAvailableForRenewal(Package $package): void
    {
        if (! $this->isPackageAvailableForRenewal($package)) {
            throw new \InvalidArgumentException(__('packages.package_not_available_for_renewal'));
        }
    }

    /**
     * Categories selectable in package create/edit (includes current category even if inactive).
     *
     * @return Collection<int, PackageCategory>
     */
    public function forPackageForm(?PackageCategory $current = null): Collection
    {
        $categories = $this->orderedActive();

        if ($current !== null && ! $categories->contains('id', $current->id)) {
            return $categories
                ->push($current)
                ->sortBy(fn (PackageCategory $category): string => sprintf('%05d-%s', $category->sort_order, $category->name))
                ->values();
        }

        return $categories;
    }

    /**
     * @return Collection<int, PackageCategory>
     */
    public function orderedActive(): Collection
    {
        if (! $this->isAvailable()) {
            return collect();
        }

        return PackageCategory::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  Collection<int, Package>  $packages
     * @return Collection<int, array{label: string, sort_key: string, packages: Collection<int, Package>}>
     */
    public function groupPackages(Collection $packages): Collection
    {
        if (! $this->isAvailable()) {
            return collect([[
                'label' => __('packages.uncategorized'),
                'sort_key' => '00000',
                'packages' => $this->sortPackagesWithoutCategory($packages),
            ]]);
        }

        $packages = $this->sortPackages($packages);

        return $packages
            ->groupBy(fn (Package $package): string => (string) ($package->package_category_id ?? 'uncategorized'))
            ->map(function (Collection $group, string $key): array {
                /** @var Package $first */
                $first = $group->first();
                $category = $first->category;

                return [
                    'label' => $category?->name ?? __('packages.uncategorized'),
                    'sort_key' => $category !== null
                        ? sprintf('%05d-%s', $category->sort_order, $category->name)
                        : '99999-'.__('packages.uncategorized'),
                    'packages' => $group->values(),
                ];
            })
            ->sortBy('sort_key')
            ->values();
    }

    /**
     * @param  Collection<int, array{package: Package, duration?: mixed}>  $rows
     * @return Collection<int, array{label: string, sort_key: string, rows: Collection<int, array<string, mixed>>}>
     */
    public function groupCatalogRows(Collection $rows): Collection
    {
        if (! $this->isAvailable()) {
            return collect([[
                'label' => __('packages.uncategorized'),
                'sort_key' => '00000',
                'rows' => $rows->values(),
            ]]);
        }

        return $rows
            ->groupBy(function (array $row): string {
                $package = $row['package'];

                return (string) ($package->package_category_id ?? 'uncategorized');
            })
            ->map(function (Collection $group, string $key): array {
                /** @var array{package: Package}|null $first */
                $first = $group->first();

                if ($first === null || ! isset($first['package'])) {
                    return [
                        'label' => __('packages.uncategorized'),
                        'sort_key' => '99999-'.__('packages.uncategorized'),
                        'rows' => $group->values(),
                    ];
                }

                $category = $first['package']->category;

                return [
                    'label' => $category?->name ?? __('packages.uncategorized'),
                    'sort_key' => $category !== null
                        ? sprintf('%05d-%s', $category->sort_order, $category->name)
                        : '99999-'.__('packages.uncategorized'),
                    'rows' => $group->values(),
                ];
            })
            ->sortBy('sort_key')
            ->values();
    }

    /**
     * @param  Collection<int, Package>  $packages
     * @return Collection<int, Package>
     */
    public function sortPackages(Collection $packages): Collection
    {
        if (! $this->isAvailable()) {
            return $this->sortPackagesWithoutCategory($packages);
        }

        return $packages->sortBy(function (Package $package): string {
            $category = $package->category;

            return sprintf(
                '%s-%05d-%s',
                $category !== null
                    ? sprintf('%05d-%s', $category->sort_order, $category->name)
                    : '99999-'.__('packages.uncategorized'),
                $package->sort_order,
                $package->name
            );
        })->values();
    }

    /**
     * @param  Collection<int, Package>  $packages
     * @return Collection<int, Package>
     */
    protected function sortPackagesWithoutCategory(Collection $packages): Collection
    {
        return $packages->sortBy(fn (Package $package): string => sprintf('%05d-%s', $package->sort_order, $package->name))->values();
    }
}
