<?php

namespace App\Services;

use App\Enums\PackageDurationTier;
use App\Models\Account;
use App\Models\Package;
use App\Models\PackageDuration;
use App\Models\Server;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Resolves which package/duration should drive billing (renewal, wholesale lookup)
 * for an account on its current server — e.g. after Sanaei → Remnawave migration.
 */
class AccountBillingPackageService
{
    public function __construct(
        protected UserPackageAssignmentService $userPackageAssignmentService,
        protected UserPackagePricingService $userPackagePricingService,
    ) {}

    public function isBillingCompatible(Account $account, ?Package $package = null): bool
    {
        $account->loadMissing(['package', 'server']);
        $package ??= $account->package;

        if ($package === null || $account->server_id === null) {
            return false;
        }

        if ($package->service_type !== $account->service_type) {
            return false;
        }

        $package->loadMissing('servers');

        return $package->servers->contains('id', (int) $account->server_id);
    }

    /**
     * Package used for renewal pricing and wholesale rows.
     */
    public function resolveBillingPackage(Account $account): Package
    {
        $account->loadMissing(['package', 'packageDuration', 'server', 'ownerSeller']);

        if ($this->isBillingCompatible($account)) {
            return $account->package;
        }

        $candidates = $this->candidatePackages($account);

        if ($candidates->isEmpty()) {
            // The account's server was removed from its package (admin stopped
            // selling that package on that server) and no other package covers
            // the server. Removing a server means "no NEW accounts here", not
            // "strand the accounts already living here" — so an existing account
            // keeps billing on the package it was sold on and renews normally.
            // Same decoupling as isPackageAvailableForRenewal(); see the renewal
            // notes in PackageCategoryService.
            if ($account->package !== null
                && $account->package->service_type === $account->service_type) {
                return $account->package;
            }

            throw new InvalidArgumentException(__('accounts.billing_package_not_found', [
                'server' => $account->server?->name ?? $account->server_id,
                'service' => $account->service_type->label(),
            ]));
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        return $this->rankCandidates($account, $candidates)->first();
    }

    public function resolveBillingDuration(
        Account $account,
        Package $billingPackage,
        ?PackageDuration $preferred = null,
    ): PackageDuration {
        $billingPackage->loadMissing([
            'durations' => fn ($q) => $q->where('is_enabled', true)->orderBy('sort_order'),
        ]);

        $preferred ??= $account->packageDuration;
        $tier = $preferred?->tier ?? PackageDurationTier::OneMonth;

        $duration = $billingPackage->durations
            ->first(fn (PackageDuration $row): bool => $row->tier === $tier);

        if ($duration !== null) {
            return $duration;
        }

        $fallback = $billingPackage->durations->first();

        if ($fallback === null) {
            throw new InvalidArgumentException(__('packages.duration_not_available'));
        }

        return $fallback;
    }

    /**
     * Align account.package_id with the server-backed billing package when needed.
     */
    public function syncBillingPackage(Account $account, ?PackageDuration $selectedDuration = null): Account
    {
        $billingPackage = $this->resolveBillingPackage($account);
        $billingDuration = $selectedDuration !== null
            && (int) $selectedDuration->package_id === (int) $billingPackage->id
            && $selectedDuration->is_enabled
            ? $selectedDuration
            : $this->resolveBillingDuration($account, $billingPackage);

        $needsUpdate = (int) $account->package_id !== (int) $billingPackage->id
            || (int) $account->package_duration_id !== (int) $billingDuration->id;

        if ($needsUpdate) {
            $oldPackage = $account->package;

            if ($oldPackage !== null
                && (int) $oldPackage->id !== (int) $billingPackage->id
                && $account->ownerSeller !== null) {
                $this->userPackagePricingService->mirrorWholesalePricesBetweenPackages(
                    $account->ownerSeller,
                    $oldPackage,
                    $billingPackage,
                );
            }

            $account->update([
                'package_id' => $billingPackage->id,
                'package_duration_id' => $billingDuration->id,
            ]);
            $account->load(['package', 'packageDuration']);
        }

        return $account;
    }

    public function assertDurationBelongsToBillingPackage(Account $account, PackageDuration $duration): PackageDuration
    {
        $billingPackage = $this->resolveBillingPackage($account);

        if ((int) $duration->package_id !== (int) $billingPackage->id || ! $duration->is_enabled) {
            throw new InvalidArgumentException(__('packages.duration_not_available'));
        }

        return $duration;
    }

    /**
     * @return Collection<int, Package>
     */
    protected function candidatePackages(Account $account): Collection
    {
        if ($account->server_id === null) {
            return collect();
        }

        $query = Package::query()
            ->active()
            ->where('service_type', $account->service_type)
            ->whereHas('servers', fn ($q) => $q->where('servers.id', (int) $account->server_id))
            ->with(['servers', 'durations']);

        $owner = $account->ownerSeller;

        if ($owner !== null) {
            $assignedIds = $this->userPackageAssignmentService->assignedPackageIds($owner);

            if ($assignedIds !== []) {
                $query->whereIn('id', $assignedIds);
            }
        }

        return $query->orderBy('sort_order')->get();
    }

    /**
     * @param  Collection<int, Package>  $candidates
     * @return Collection<int, Package>
     */
    protected function rankCandidates(Account $account, Collection $candidates): Collection
    {
        $stored = $account->package;
        $serverId = (int) $account->server_id;

        return $candidates->sort(function (Package $a, Package $b) use ($stored, $serverId): int {
            $scoreA = $this->candidateScore($a, $stored, $serverId);
            $scoreB = $this->candidateScore($b, $stored, $serverId);

            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }

            return ((int) $a->sort_order) <=> ((int) $b->sort_order) ?: ((int) $b->id) <=> ((int) $a->id);
        })->values();
    }

    protected function candidateScore(Package $candidate, ?Package $stored, int $serverId): int
    {
        $score = 0;

        if ($stored !== null && (int) $candidate->package_category_id === (int) $stored->package_category_id) {
            $score += 40;
        }

        if ($stored !== null && $candidate->isElastic() === $stored->isElastic()) {
            $score += 30;
        }

        if ((int) $candidate->default_server_id === $serverId) {
            $score += 20;
        }

        if ($candidate->usesRemnawaveServer() && ! $this->packageHasSanaeiServer($candidate)) {
            $score += 15;
        }

        if ($stored !== null && (int) $candidate->id === (int) $stored->id) {
            $score += 5;
        }

        return $score;
    }

    protected function packageHasSanaeiServer(Package $package): bool
    {
        $package->loadMissing('servers');

        return $package->servers->contains(fn (Server $server): bool => $server->isSanaei());
    }
}
