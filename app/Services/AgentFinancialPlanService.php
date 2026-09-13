<?php

namespace App\Services;

use App\Enums\AgentFinancialPlanStatus;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\AgentFinancialPlanPurchase;
use App\Models\AgentFinancialPlanTemplate;
use App\Models\AgentFinancialPlanUsage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class AgentFinancialPlanService
{
    public function __construct(
        protected GlobalDiscountService $globalDiscountService,
        protected WalletService $walletService,
    ) {}

    public function isAvailable(): bool
    {
        return Schema::hasTable('agent_financial_plan_purchases');
    }

    /**
     * Agent whose prepaid lots apply to this buyer (agent or seller under agent).
     */
    public function resolvePlanOwner(User $buyer): ?User
    {
        if ($buyer->role === UserRole::Agent) {
            return $buyer;
        }

        if ($buyer->role === UserRole::Seller) {
            $buyer->loadMissing('parent');

            if ($buyer->parent?->role === UserRole::Agent) {
                return $buyer->parent;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, AgentFinancialPlanPurchase>
     */
    public function activeLotsFor(User $agent): Collection
    {
        if (! $this->isAvailable()) {
            return collect();
        }

        return AgentFinancialPlanPurchase::query()
            ->where('agent_id', $agent->id)
            ->where('status', AgentFinancialPlanStatus::Active)
            ->where('credit_remaining', '>', 0)
            ->orderBy('purchased_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{
     *     buyer_charge: string,
     *     buyer_wholesale: string,
     *     plan_applied: bool,
     *     plan_wholesale: string,
     *     plan_discount: string,
     *     plan_charge: string,
     *     wallet_wholesale: string,
     *     wallet_charge: string,
     *     slices: list<array{
     *         purchase_id: int,
     *         wholesale_portion: string,
     *         discount_percent: float,
     *         discount_amount: string,
     *         charged_portion: string,
     *         plan_name: string
     *     }>,
     *     plan_owner_id: ?int
     * }
     */
    public function resolveCharge(User $buyer, string $buyerWholesale): array
    {
        $buyerWholesale = $this->money($buyerWholesale);
        $owner = $this->resolvePlanOwner($buyer);

        if ($owner === null || ! $this->isAvailable()) {
            return $this->chargeWithoutPlans($buyerWholesale);
        }

        $lots = $this->activeLotsFor($owner);

        if ($lots->isEmpty()) {
            return $this->chargeWithoutPlans($buyerWholesale, $owner->id);
        }

        $remaining = $buyerWholesale;
        $slices = [];
        $planCharge = '0.00';
        $planDiscount = '0.00';
        $planWholesale = '0.00';

        foreach ($lots as $lot) {
            if (bccomp($remaining, '0', 2) <= 0) {
                break;
            }

            $creditLeft = $this->money((string) $lot->credit_remaining);

            if (bccomp($creditLeft, '0', 2) <= 0) {
                continue;
            }

            $portion = bccomp($remaining, $creditLeft, 2) <= 0 ? $remaining : $creditLeft;
            $discountAmount = $this->discountAmount($portion, (string) $lot->discount_percent);
            $chargedPortion = bcsub($portion, $discountAmount, 2);

            $slices[] = [
                'purchase_id' => (int) $lot->id,
                'wholesale_portion' => $portion,
                'discount_percent' => (float) $lot->discount_percent,
                'discount_amount' => $discountAmount,
                'charged_portion' => $chargedPortion,
                'plan_name' => $lot->name,
            ];

            $planCharge = bcadd($planCharge, $chargedPortion, 2);
            $planDiscount = bcadd($planDiscount, $discountAmount, 2);
            $planWholesale = bcadd($planWholesale, $portion, 2);
            $remaining = bcsub($remaining, $portion, 2);
        }

        $walletWholesale = $remaining;
        $walletCharge = bccomp($walletWholesale, '0', 2) > 0
            ? $this->globalDiscountService->apply($walletWholesale)
            : '0.00';

        return [
            'buyer_charge' => bcadd($planCharge, $walletCharge, 2),
            'buyer_wholesale' => $buyerWholesale,
            'plan_applied' => $slices !== [],
            'plan_wholesale' => $planWholesale,
            'plan_discount' => $planDiscount,
            'plan_charge' => $planCharge,
            'wallet_wholesale' => $walletWholesale,
            'wallet_charge' => $walletCharge,
            'slices' => $slices,
            'plan_owner_id' => $owner->id,
        ];
    }

    /**
     * @param  array{slices: list<array<string, mixed>>}  $breakdown
     */
    public function applyUsages(
        array $breakdown,
        User $buyer,
        int $accountId,
        ?int $transactionId,
        TransactionType $usageType,
    ): void {
        if (! $this->isAvailable() || empty($breakdown['slices'])) {
            return;
        }

        $ownerId = $breakdown['plan_owner_id'] ?? null;

        if ($ownerId === null) {
            return;
        }

        foreach ($breakdown['slices'] as $slice) {
            $purchase = AgentFinancialPlanPurchase::query()
                ->whereKey($slice['purchase_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $purchase->agent_id !== (int) $ownerId) {
                throw new InvalidArgumentException(__('financial_plans.invalid_plan_owner'));
            }

            $portion = $this->money($slice['wholesale_portion']);
            $remaining = $this->money((string) $purchase->credit_remaining);

            if (bccomp($remaining, $portion, 2) < 0) {
                throw new InvalidArgumentException(__('financial_plans.insufficient_credit'));
            }

            $newRemaining = bcsub($remaining, $portion, 2);
            $purchase->credit_remaining = bccomp($newRemaining, '0', 2) <= 0 ? '0.00' : $newRemaining;

            if (bccomp($purchase->credit_remaining, '0', 2) <= 0) {
                $purchase->status = AgentFinancialPlanStatus::Depleted;
            }

            $purchase->save();

            AgentFinancialPlanUsage::query()->create([
                'purchase_id' => $purchase->id,
                'agent_id' => $purchase->agent_id,
                'related_account_id' => $accountId,
                'buyer_user_id' => $buyer->id,
                'transaction_id' => $transactionId,
                'wholesale_portion' => $portion,
                'discount_percent' => $slice['discount_percent'],
                'discount_amount' => $this->money($slice['discount_amount']),
                'charged_portion' => $this->money($slice['charged_portion']),
                'usage_type' => $usageType->value,
            ]);
        }
    }

    public function restoreForAccount(int $accountId, string $refundRatio = '1.0000'): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        $ratio = $this->money($refundRatio);

        if (bccomp($ratio, '0', 4) <= 0) {
            return;
        }

        $usages = AgentFinancialPlanUsage::query()
            ->where('related_account_id', $accountId)
            ->orderBy('id')
            ->get();

        foreach ($usages as $usage) {
            $restoreWholesale = $this->money(bcmul($this->money((string) $usage->wholesale_portion), $ratio, 4));

            if (bccomp($restoreWholesale, '0', 2) <= 0) {
                continue;
            }

            $purchase = AgentFinancialPlanPurchase::query()
                ->whereKey($usage->purchase_id)
                ->lockForUpdate()
                ->first();

            if ($purchase === null) {
                continue;
            }

            $purchase->credit_remaining = bcadd(
                $this->money((string) $purchase->credit_remaining),
                $restoreWholesale,
                2
            );

            if (bccomp($purchase->credit_remaining, $this->money((string) $purchase->credit_total), 2) > 0) {
                $purchase->credit_remaining = $this->money((string) $purchase->credit_total);
            }

            if ($purchase->status === AgentFinancialPlanStatus::Depleted
                && bccomp($purchase->credit_remaining, '0', 2) > 0) {
                $purchase->status = AgentFinancialPlanStatus::Active;
            }

            $purchase->save();
        }
    }

    public function reapplyForAccount(int $accountId, string $refundRatio = '1.0000'): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        $ratio = $this->money($refundRatio);

        if (bccomp($ratio, '0', 4) <= 0) {
            return;
        }

        $usages = AgentFinancialPlanUsage::query()
            ->where('related_account_id', $accountId)
            ->orderBy('id')
            ->get();

        foreach ($usages as $usage) {
            $reapplyWholesale = $this->money(bcmul($this->money((string) $usage->wholesale_portion), $ratio, 4));

            if (bccomp($reapplyWholesale, '0', 2) <= 0) {
                continue;
            }

            $purchase = AgentFinancialPlanPurchase::query()
                ->whereKey($usage->purchase_id)
                ->lockForUpdate()
                ->first();

            if ($purchase === null) {
                continue;
            }

            $remaining = $this->money((string) $purchase->credit_remaining);
            $purchase->credit_remaining = bccomp($remaining, $reapplyWholesale, 2) < 0
                ? '0.00'
                : bcsub($remaining, $reapplyWholesale, 2);

            if (bccomp($purchase->credit_remaining, '0', 2) <= 0) {
                $purchase->status = AgentFinancialPlanStatus::Depleted;
                $purchase->credit_remaining = '0.00';
            }

            $purchase->save();
        }
    }

    public function sellTemplateToAgent(
        AgentFinancialPlanTemplate $template,
        User $agent,
        User $soldBy,
    ): AgentFinancialPlanPurchase {
        if ($agent->role !== UserRole::Agent) {
            throw new InvalidArgumentException(__('financial_plans.target_must_be_agent'));
        }

        if (! $template->is_active) {
            throw new InvalidArgumentException(__('financial_plans.template_inactive'));
        }

        $price = $this->money((string) $template->purchase_price);
        $credit = $this->money((string) $template->credit_amount);

        if (bccomp($price, '0', 2) <= 0 || bccomp($credit, '0', 2) <= 0) {
            throw new InvalidArgumentException(__('financial_plans.invalid_template_amounts'));
        }

        $this->walletService->assertSufficientBalance($agent, $price);

        return DB::transaction(function () use ($template, $agent, $soldBy, $price, $credit): AgentFinancialPlanPurchase {
            $transaction = $this->walletService->debit(
                $agent,
                $price,
                TransactionType::FinancialPlanPurchase,
                ['description' => __('financial_plans.plan_purchase_debit', ['name' => $template->name])]
            );

            return AgentFinancialPlanPurchase::query()->create([
                'agent_id' => $agent->id,
                'template_id' => $template->id,
                'name' => $template->name,
                'credit_total' => $credit,
                'credit_remaining' => $credit,
                'purchase_price' => $price,
                'discount_percent' => $template->discount_percent,
                'status' => AgentFinancialPlanStatus::Active,
                'sold_by_user_id' => $soldBy->id,
                'wallet_transaction_id' => $transaction->id,
                'purchased_at' => now(),
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function previewForAgent(User $agent): array
    {
        $lots = $this->activeLotsFor($agent);
        $totalRemaining = '0.00';

        foreach ($lots as $lot) {
            $totalRemaining = bcadd($totalRemaining, $this->money((string) $lot->credit_remaining), 2);
        }

        return [
            'active_count' => $lots->count(),
            'total_remaining' => $totalRemaining,
            'lots' => $lots,
        ];
    }

    protected function chargeWithoutPlans(string $buyerWholesale, ?int $planOwnerId = null): array
    {
        $walletCharge = $this->globalDiscountService->apply($buyerWholesale);

        return [
            'buyer_charge' => $walletCharge,
            'buyer_wholesale' => $buyerWholesale,
            'plan_applied' => false,
            'plan_wholesale' => '0.00',
            'plan_discount' => '0.00',
            'plan_charge' => '0.00',
            'wallet_wholesale' => $buyerWholesale,
            'wallet_charge' => $walletCharge,
            'slices' => [],
            'plan_owner_id' => $planOwnerId,
        ];
    }

    protected function discountAmount(string $wholesale, string $percent): string
    {
        $rate = bcdiv($this->money($percent), '100', 6);

        return $this->money(bcmul($this->money($wholesale), $rate, 4));
    }

    protected function money(string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
