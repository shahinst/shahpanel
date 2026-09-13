<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\PackageDuration;
use App\Models\User;
use App\Exceptions\InsufficientWalletBalanceException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * End-user (client portal) purchases are isolated from staff tiered economics
 * (invoices, agent margin on seller wholesale, processTieredPurchase).
 */
class ClientPortalEconomicsService
{
    public function __construct(
        protected UserPackagePricingService $userPackagePricingService,
        protected UserHierarchyService $userHierarchyService,
        protected WalletService $walletService,
        protected ClientDisplayPricingService $displayPricingService,
    ) {}

    /**
     * @return array{
     *     display_total: string,
     *     wholesale_total: string,
     *     retail_profit: string,
     *     upstream_cost: string,
     *     units: string
     * }
     */
    public function quote(
        User $owner,
        PackageDuration $duration,
        ?float $gb = null,
        ?string $displayUnitPrice = null,
        bool $forRenewal = false,
    ): array {
        if (! in_array($owner->role, [UserRole::Agent, UserRole::Seller], true)) {
            throw new InvalidArgumentException(__('clients.purchase_owner_invalid'));
        }

        $duration->loadMissing('package');
        $package = $duration->package;
        $isPerGb = $package !== null && $package->isElastic();

        $units = $isPerGb
            ? ($forRenewal
                ? $this->userPackagePricingService->renewalUnitsFor($package, $gb)
                : $this->userPackagePricingService->unitsFor($package, $gb))
            : '1';

        $wholesaleTotal = $this->userPackagePricingService->lineTotal($owner, $duration, $gb, forRenewal: $forRenewal);

        if ($displayUnitPrice === null) {
            $displayUnitPrice = $this->displayUnitPriceForOwner($owner, $duration);
        }

        $displayTotal = $isPerGb
            ? $this->moneyMul($displayUnitPrice, $units)
            : number_format((float) $displayUnitPrice, 2, '.', '');
        $retailProfit = $this->moneyCompare($displayTotal, $wholesaleTotal) >= 0
            ? $this->moneySub($displayTotal, $wholesaleTotal)
            : '0.00';

        return [
            'display_total' => $displayTotal,
            'wholesale_total' => $wholesaleTotal,
            'retail_profit' => $retailProfit,
            'upstream_cost' => $this->upstreamCostForOwner($owner, $duration, $gb, $forRenewal),
            'units' => $units,
        ];
    }

    /**
     * @param  array{
     *     display_total: string,
     *     wholesale_total: string,
     *     upstream_cost: string
     * }  $quote
     */
    public function assertCanSettle(User $client, User $owner, array $quote): void
    {
        $this->walletService->assertSufficientBalance($client, $quote['display_total']);

        try {
            $this->walletService->assertSufficientBalance($owner, $quote['wholesale_total']);
        } catch (InsufficientWalletBalanceException) {
            throw new InvalidArgumentException(__('clients.owner_insufficient_balance'));
        }
    }

    /**
     * @param  array{
     *     display_total: string,
     *     wholesale_total: string,
     *     retail_profit: string,
     *     upstream_cost: string
     * }  $quote
     */
    public function settlePurchase(
        User $client,
        User $owner,
        Account $account,
        array $quote,
        bool $renewal = false
    ): void {
        $purchaseType = $renewal ? TransactionType::Renewal : TransactionType::Purchase;
        $admin = $this->userHierarchyService->resolveCommissionChain($owner)['admin'];

        $context = [
            'related_account_id' => $account->id,
            'source_user_id' => $client->id,
            'description' => $renewal
                ? 'Client portal renewal (retail)'
                : 'Client portal purchase (retail)',
        ];

        $costContext = array_merge($context, [
            'source_user_id' => $owner->id,
            'description' => $renewal
                ? 'Client portal renewal (wholesale cost)'
                : 'Client portal purchase (wholesale cost)',
        ]);

        DB::transaction(function () use ($client, $owner, $admin, $quote, $purchaseType, $context, $costContext): void {
            $this->walletService->debit($client, $quote['display_total'], $purchaseType, $context);

            $this->walletService->credit(
                $owner,
                $quote['display_total'],
                TransactionType::ClientRetail,
                array_merge($context, [
                    'description' => 'دریافت مبلغ خریدار (فروش خرده)',
                ])
            );

            $this->walletService->debit(
                $owner,
                $quote['wholesale_total'],
                TransactionType::ClientCost,
                $costContext
            );

            if ($this->moneyCompare($quote['upstream_cost'], '0') > 0) {
                $this->walletService->credit(
                    $admin,
                    $quote['upstream_cost'],
                    TransactionType::Revenue,
                    array_merge($costContext, [
                        'description' => 'سهم ادمین — فروش پنل خریدار',
                    ])
                );
            }
        });
    }

    protected function displayUnitPriceForOwner(User $owner, PackageDuration $duration): string
    {
        $row = $this->displayPricingService->catalogForOwner($owner)
            ->first(fn (array $item): bool => (int) $item['duration']->id === (int) $duration->id);

        if ($row === null) {
            throw new InvalidArgumentException(__('packages.duration_not_available'));
        }

        return $row['display_price'];
    }

    protected function upstreamCostForOwner(User $owner, PackageDuration $duration, ?float $gb, bool $forRenewal = false): string
    {
        if ($owner->role === UserRole::Agent) {
            return $this->userPackagePricingService->lineTotal($owner, $duration, $gb, forRenewal: $forRenewal);
        }

        $agent = $owner->parent;

        if ($agent === null || $agent->role !== UserRole::Agent) {
            throw new InvalidArgumentException(__('sellers.parent_hint'));
        }

        return $this->userPackagePricingService->lineTotal($agent, $duration, $gb, forRenewal: $forRenewal);
    }

    protected function moneyMul(string $left, string $right): string
    {
        if (function_exists('bcmul')) {
            return number_format((float) bcmul($left, $right, 4), 2, '.', '');
        }

        return number_format(round((float) $left * (float) $right, 2), 2, '.', '');
    }

    /** @return int -1, 0, or 1 */
    protected function moneyCompare(string $left, string $right): int
    {
        if (function_exists('bccomp')) {
            return bccomp($left, $right, 2);
        }

        return round((float) $left, 2) <=> round((float) $right, 2);
    }

    protected function moneySub(string $left, string $right): string
    {
        if (function_exists('bcsub')) {
            return bcsub($left, $right, 2);
        }

        return number_format(max(0, round((float) $left - (float) $right, 2)), 2, '.', '');
    }
}
