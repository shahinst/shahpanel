<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\PackageDuration;
use App\Models\User;
use InvalidArgumentException;

/**
 * Renewal pricing for staff panels (agent / seller / admin).
 *
 * Rules:
 * - Look up the buyer's wholesale price for the selected duration.
 * - Elastic (per-GB) package: wholesale × account volume (GB).
 * - Fixed (time-based) package: wholesale as-is (one price for the period).
 */
class AccountRenewalPricingService
{
    public function __construct(
        protected UserPackagePricingService $userPackagePricingService,
        protected GlobalDiscountService $globalDiscountService,
        protected AccountBillingPackageService $billingPackageService,
        protected AgentFinancialPlanService $financialPlanService,
    ) {}

    /**
     * Who pays and whose wholesale row is used on staff-panel renewal.
     *
     * The account owner (seller) is always charged using their wholesale row
     * (the price their agent assigned in package assignment).
     */
    public function resolveRenewalBuyer(User $actor, Account $account): User
    {
        $account->loadMissing('ownerSeller');

        if ($account->ownerSeller !== null) {
            return $account->ownerSeller;
        }

        if (in_array($actor->role, [UserRole::Agent, UserRole::Seller], true)) {
            return $actor;
        }

        throw new InvalidArgumentException(__('accounts.refund_owner_missing'));
    }

    /**
     * GB billed on renewal for elastic packages; null for fixed/time packages.
     */
    public function billableDataGb(Account $account): ?float
    {
        $account->loadMissing('package');
        $package = $this->billingPackageService->resolveBillingPackage($account);

        if (! $package->isElastic()) {
            return null;
        }

        if ($account->purchased_data_gb !== null && (float) $account->purchased_data_gb > 0) {
            return round((float) $account->purchased_data_gb, 2);
        }

        if ($account->data_limit_bytes !== null && (int) $account->data_limit_bytes > 0) {
            return round((int) $account->data_limit_bytes / (1024 ** 3), 2);
        }

        return round((float) ($package->min_data_gb ?? 1), 2);
    }

    /**
     * @return array{
     *     is_per_gb: bool,
     *     is_elastic: bool,
     *     data_gb: ?string,
     *     unit_price: string,
     *     wholesale_total: string,
     *     charged_total: string,
     *     discount_active: bool,
     *     discount_percent: float
     * }
     */
    public function quote(
        User $buyer,
        Account $account,
        PackageDuration $duration,
        ?float $dataGbOverride = null,
        string $renewalMode = 'same',
    ): array {
        $account->loadMissing('package');
        $package = $this->billingPackageService->resolveBillingPackage($account);

        $this->billingPackageService->assertDurationBelongsToBillingPackage($account, $duration);

        $duration->loadMissing('package');
        $duration->setRelation('package', $package);

        $isPerGb = $package->isElastic();
        $gb = $isPerGb
            ? $this->billableGbForRenewal($account, $renewalMode, $dataGbOverride)
            : null;
        $unitPrice = $this->userPackagePricingService->requireWholesalePrice($buyer, $duration);
        $wholesaleTotal = $this->userPackagePricingService->lineTotal($buyer, $duration, $gb, forRenewal: true);
        $chargeBreakdown = $this->financialPlanService->resolveCharge($buyer, $wholesaleTotal);

        // Admin-set fixed renewal price: charge exactly this (0 = free) and scale
        // the agent commissions to it, instead of the computed wholesale figure.
        $override = $account->renewalChargeOverride();
        $chargedTotal = $override ?? $chargeBreakdown['buyer_charge'];

        $economics = $this->userPackagePricingService->resolvePurchaseEconomics(
            $buyer,
            $duration,
            $chargedTotal,
            $gb,
            forRenewal: true,
            scaleCommissionsToCharge: $override !== null,
        );

        return [
            'is_per_gb' => $isPerGb,
            'is_elastic' => $isPerGb,
            'data_gb' => $gb !== null ? number_format($gb, 2, '.', '') : null,
            'units' => $isPerGb && $gb !== null
                ? $this->userPackagePricingService->renewalUnitsFor($package, $gb)
                : '1',
            'unit_price' => $unitPrice,
            'wholesale_total' => $wholesaleTotal,
            'charged_total' => $chargedTotal,
            'discount_active' => $this->globalDiscountService->isActive() || $chargeBreakdown['plan_applied'],
            'discount_percent' => $this->globalDiscountService->percent(),
            'plan_applied' => $chargeBreakdown['plan_applied'],
            'plan_discount' => $chargeBreakdown['plan_discount'],
            'plan_wholesale' => $chargeBreakdown['plan_wholesale'],
            'plan_slices' => $chargeBreakdown['slices'],
            'agent_margin' => $economics['agent_margin'],
            'agent_wholesale' => $economics['agent_wholesale'],
        ];
    }

    /**
     * GB used for wholesale billing on renewal (elastic only).
     *
     * - same: current account volume × assigned wholesale unit
     * - add_volume: added GB × assigned wholesale unit
     * - upgrade_volume: new total GB × assigned wholesale unit
     */
    public function billableGbForRenewal(Account $account, string $mode, ?float $resolvedGb = null): ?float
    {
        $account->loadMissing('package');
        $package = $this->billingPackageService->resolveBillingPackage($account);

        if (! $package->isElastic()) {
            return null;
        }

        if ($mode === 'same') {
            return $this->billableDataGb($account);
        }

        if ($resolvedGb === null || $resolvedGb <= 0) {
            throw new InvalidArgumentException(
                $mode === 'add_volume'
                    ? __('accounts.renew_add_gb_required')
                    : __('accounts.renew_upgrade_gb_required')
            );
        }

        return round($resolvedGb, 2);
    }

    /**
     * Staff-panel renewal billing: wholesale from assignee rows, agent margin on the same units.
     *
     * @return array{
     *     quote: array<string, mixed>,
     *     economics: array<string, mixed>,
     *     charge_breakdown: array<string, mixed>
     * }
     */
    public function billingForRenewal(
        User $buyer,
        Account $account,
        PackageDuration $duration,
        string $renewalMode = 'same',
        ?float $resolvedGb = null,
    ): array {
        $quote = $this->quote($buyer, $account, $duration, $resolvedGb, $renewalMode);
        $gb = $quote['data_gb'] !== null ? (float) $quote['data_gb'] : null;
        $wholesaleTotal = $quote['wholesale_total'];
        $chargeBreakdown = $this->financialPlanService->resolveCharge($buyer, $wholesaleTotal);

        // Honour the account's fixed renewal price: quote() already forced
        // charged_total to it, so charge that and scale the commissions to match.
        $override = $account->renewalChargeOverride();
        $buyerCharge = $override ?? $chargeBreakdown['buyer_charge'];
        $chargeBreakdown['buyer_charge'] = $buyerCharge;

        $economics = $this->userPackagePricingService->resolvePurchaseEconomics(
            $buyer,
            $duration,
            $buyerCharge,
            $gb,
            forRenewal: true,
            scaleCommissionsToCharge: $override !== null,
        );

        return [
            'quote' => $quote,
            'economics' => $economics,
            'charge_breakdown' => $chargeBreakdown,
        ];
    }

    /**
     * Validate and resolve GB for elastic renewal (same volume, top-up add, or upgrade total).
     */
    public function resolveRenewalDataGb(Account $account, string $mode, mixed $requestedGb): ?float
    {
        $account->loadMissing('package');
        $package = $this->billingPackageService->resolveBillingPackage($account);

        if (! $package->isElastic()) {
            return null;
        }

        $current = $this->billableDataGb($account);

        if ($mode === 'same') {
            return $current;
        }

        if ($requestedGb === null || $requestedGb === '' || (float) $requestedGb <= 0) {
            throw new InvalidArgumentException(
                $mode === 'add_volume'
                    ? __('accounts.renew_add_gb_required')
                    : __('accounts.renew_upgrade_gb_required')
            );
        }

        $gb = round((float) $requestedGb, 2);
        $min = $package->min_data_gb !== null ? (float) $package->min_data_gb : 1.0;
        $max = $package->max_data_gb !== null ? (float) $package->max_data_gb : null;

        if ($mode === 'add_volume') {
            if ($gb < $min) {
                throw new InvalidArgumentException(__('packages.elastic_gb_out_of_range', [
                    'min' => rtrim(rtrim(number_format($min, 2, '.', ''), '0'), '.'),
                    'max' => $max !== null ? rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.') : '∞',
                ]));
            }

            $newTotal = round($current + $gb, 2);
            if ($max !== null && $newTotal > $max) {
                throw new InvalidArgumentException(__('accounts.renew_add_gb_exceeds_max', [
                    'max' => rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.'),
                    'current' => rtrim(rtrim(number_format($current, 2, '.', ''), '0'), '.'),
                ]));
            }

            return $gb;
        }

        if ($gb <= $current) {
            throw new InvalidArgumentException(__('accounts.renew_upgrade_gb_must_exceed', [
                'current' => rtrim(rtrim(number_format($current, 2, '.', ''), '0'), '.'),
            ]));
        }

        if ($gb < $min || ($max !== null && $gb > $max)) {
            throw new InvalidArgumentException(__('packages.elastic_gb_out_of_range', [
                'min' => rtrim(rtrim(number_format($min, 2, '.', ''), '0'), '.'),
                'max' => $max !== null ? rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.') : '∞',
            ]));
        }

        return $gb;
    }
}
