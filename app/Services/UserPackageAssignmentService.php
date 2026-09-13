<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Package;
use App\Models\PackageDuration;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class UserPackageAssignmentService
{
    /**
     * @return Builder<Package>
     */
    public function assignedPackagesQuery(User $user): Builder
    {
        $query = Package::query();
        app(PackageCategoryService::class)->applyAvailableForNewAccounts($query);
        $query->orderBy('sort_order');

        if ($user->role === UserRole::Admin) {
            return $query;
        }

        if (! Schema::hasTable('user_packages')) {
            return $query;
        }

        $assignedIds = $this->assignedPackageIds($user);

        if ($assignedIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('id', $assignedIds);
    }

    /**
     * @return Collection<int, Package>
     */
    public function assignedPackages(User $user): Collection
    {
        return $this->assignedPackagesQuery($user)->get();
    }

    /**
     * @return list<int>
     */
    public function assignedPackageIds(User $user): array
    {
        if ($user->role === UserRole::Admin) {
            return Package::query()
                ->where('is_active', true)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        if (! Schema::hasTable('user_packages')) {
            return Package::query()
                ->where('is_active', true)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        return $user->assignedPackages()
            ->pluck('user_packages.package_id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public function userHasPackage(User $user, int $packageId): bool
    {
        return in_array($packageId, $this->assignedPackageIds($user), true);
    }

    public function assertUserHasPackage(User $user, Package $package): void
    {
        if (! $this->userHasPackage($user, (int) $package->id)) {
            throw new InvalidArgumentException(__('packages.not_assigned_to_user'));
        }
    }

    /**
     * Packages the assigner may offer when editing a target user.
     *
     * @return Collection<int, Package>
     */
    public function assignablePackagesFor(User $assigner, ?User $target = null): Collection
    {
        if ($assigner->role === UserRole::Admin) {
            return Package::query()->active()->orderBy('sort_order')->get();
        }

        if ($assigner->role === UserRole::Agent) {
            return $this->assignedPackages($assigner);
        }

        return collect();
    }

    protected function resolveSellerParent(User $seller): ?User
    {
        if ($seller->parent_id === null) {
            return null;
        }

        return User::query()->find((int) $seller->parent_id);
    }

    /**
     * @param  list<int|string>  $packageIds
     */
    public function syncForUser(User $target, array $packageIds, User $assigner): void
    {
        if (! Schema::hasTable('user_packages')) {
            return;
        }

        if (! in_array($target->role, [UserRole::Agent, UserRole::Seller], true)) {
            return;
        }

        $ids = collect($packageIds)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        $allowed = $this->assignablePackagesFor($assigner, $target)->pluck('id')->map(fn ($id): int => (int) $id);

        if ($ids->diff($allowed)->isNotEmpty()) {
            $ids = $ids->intersect($allowed)->values();
        }

        if ($target->role === UserRole::Seller && $assigner->role !== UserRole::Admin) {
            $parent = $this->resolveSellerParent($target);

            if ($parent !== null) {
                $parentIds = $this->assignedPackageIds($parent);
                $ids = $ids->intersect($parentIds)->values();
            }
        }

        if ($target->role === UserRole::Seller && $assigner->role === UserRole::Admin) {
            $parent = $this->resolveSellerParent($target);

            if ($parent !== null && $parent->role === UserRole::Agent) {
                $this->ensureAgentHasPackagesWithCatalogPricing($parent, $ids->all(), $assigner);
            }
        }

        $payload = $ids->mapWithKeys(fn (int $id): array => [
            $id => ['assigned_by_user_id' => $assigner->id],
        ])->all();

        $target->assignedPackages()->sync($payload);
    }

    /**
     * @param  list<int>  $packageIds
     */
    protected function ensureAgentHasPackagesWithCatalogPricing(User $agent, array $packageIds, User $assigner): void
    {
        $current = collect($this->assignedPackageIds($agent));
        $merged = $current->merge($packageIds)->unique()->values();

        if ($merged->diff($current)->isEmpty()) {
            return;
        }

        $payload = $merged->mapWithKeys(fn (int $id): array => [
            $id => ['assigned_by_user_id' => $assigner->id],
        ])->all();

        $agent->assignedPackages()->sync($payload);

        $pricingService = app(UserPackagePricingService::class);
        $packages = Package::query()
            ->whereIn('id', $merged)
            ->with(['durations' => fn ($q) => $q->where('is_enabled', true)])
            ->get();

        $priceInput = [];

        foreach ($packages as $package) {
            foreach ($package->durations as $duration) {
                if ($pricingService->wholesalePriceFor($agent, $duration) === null) {
                    $priceInput[$package->id][$duration->id] = $duration->price;
                }
            }
        }

        if ($priceInput !== []) {
            $pricingService->syncForAssignedPackages($agent, $merged->all(), $priceInput, $assigner);
        }
    }
}
