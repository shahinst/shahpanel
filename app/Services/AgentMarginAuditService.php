<?php

namespace App\Services;

use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AgentMarginCorrection;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AgentMarginAuditService
{
    public const CLAWBACK_MARKER = '[agent-margin-audit-clawback]';

    public function __construct(
        protected UserPackagePricingService $userPackagePricingService,
        protected WalletService $walletService,
    ) {}

    /**
     * @return Collection<int, array{
     *     margin_transaction: Transaction,
     *     account: Account,
     *     agent: User,
     *     expected_margin: string,
     *     actual_margin: string,
     *     clawback_amount: string,
     *     currency: string,
     *     reason: string
     * }>
     */
    public function findOverpaidMargins(?int $agentId = null): Collection
    {
        $query = Transaction::query()
            ->where('type', TransactionType::Margin)
            ->whereNotNull('related_account_id')
            ->whereIn('user_id', User::query()->where('role', UserRole::Agent)->select('id'))
            ->orderBy('id');

        if ($agentId !== null) {
            $query->where('user_id', $agentId);
        }

        $alreadyFixed = AgentMarginCorrection::query()
            ->whereNotNull('margin_transaction_id')
            ->pluck('margin_transaction_id')
            ->all();

        $results = collect();

        foreach ($query->cursor() as $marginTx) {
            if (in_array((int) $marginTx->id, $alreadyFixed, true)) {
                continue;
            }

            $audit = $this->auditMarginTransaction($marginTx);

            if ($audit !== null) {
                $results->push($audit);
            }
        }

        return $results;
    }

    /**
     * @param  array{
     *     margin_transaction: Transaction,
     *     account: Account,
     *     agent: User,
     *     expected_margin: string,
     *     actual_margin: string,
     *     clawback_amount: string,
     *     currency: string,
     *     reason: string
     * }  $row
     */
    public function applyClawback(array $row): AgentMarginCorrection
    {
        $marginTx = $row['margin_transaction'];
        $agent = $row['agent'];
        $account = $row['account'];
        $amount = $row['clawback_amount'];

        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('Clawback amount must be positive.');
        }

        return DB::transaction(function () use ($marginTx, $agent, $account, $amount, $row): AgentMarginCorrection {
            $exists = AgentMarginCorrection::query()
                ->where('margin_transaction_id', $marginTx->id)
                ->exists();

            if ($exists) {
                throw new InvalidArgumentException('Correction already applied for margin #'.$marginTx->id);
            }

            $clawbackTx = $this->walletService->debit(
                $agent,
                $amount,
                TransactionType::Adjustment,
                [
                    'source_user_id' => $agent->id,
                    'related_account_id' => $account->id,
                    'description' => 'بازگشت پورسانت اضافه — '.self::CLAWBACK_MARKER.' #'.$marginTx->id,
                ],
                allowNegative: true,
            );

            return AgentMarginCorrection::query()->create([
                'agent_user_id' => $agent->id,
                'account_id' => $account->id,
                'margin_transaction_id' => $marginTx->id,
                'expected_margin' => $row['expected_margin'],
                'actual_margin' => $row['actual_margin'],
                'clawback_amount' => $amount,
                'clawback_transaction_id' => $clawbackTx->id,
                'reason' => $row['reason'],
                'created_at' => now(),
            ]);
        });
    }

    /**
     * @return array{
     *     margin_transaction: Transaction,
     *     account: Account,
     *     agent: User,
     *     expected_margin: string,
     *     actual_margin: string,
     *     clawback_amount: string,
     *     currency: string,
     *     reason: string
     * }|null
     */
    protected function auditMarginTransaction(Transaction $marginTx): ?array
    {
        $account = Account::query()
            ->withTrashed()
            ->with(['package', 'packageDuration', 'ownerSeller.parent'])
            ->find((int) $marginTx->related_account_id);

        if ($account === null || $account->ownerSeller === null) {
            return null;
        }

        if ($account->refunded_at !== null) {
            return null;
        }

        $seller = $account->ownerSeller;
        $agent = $seller->parent;

        if ($agent === null || $agent->role !== UserRole::Agent) {
            return null;
        }

        if ((int) $marginTx->user_id !== (int) $agent->id) {
            return null;
        }

        $sellerTx = $this->findMatchingSellerCharge($account, $marginTx);

        if ($sellerTx === null) {
            return null;
        }

        $duration = $account->packageDuration;
        $package = $account->package;

        if ($duration === null || $package === null || (int) $duration->package_id !== (int) $package->id) {
            return null;
        }

        $duration->setRelation('package', $package);

        $isRenewal = $sellerTx->type === TransactionType::Renewal;
        $gb = $package->isElastic()
            ? ($isRenewal
                ? $this->renewalGbFor($account)
                : ($account->purchased_data_gb !== null ? (float) $account->purchased_data_gb : null))
            : null;

        try {
            $economics = $this->userPackagePricingService->resolvePurchaseEconomics(
                $seller,
                $duration,
                null,
                $gb,
                forRenewal: $isRenewal,
            );
        } catch (\Throwable) {
            return null;
        }

        $expected = number_format((float) $economics['agent_margin'], 2, '.', '');
        $actual = number_format((float) $marginTx->amount, 2, '.', '');
        $excess = bcsub($actual, $expected, 2);

        if (bccomp($excess, '0', 2) <= 0) {
            return null;
        }

        return [
            'margin_transaction' => $marginTx,
            'account' => $account,
            'agent' => $agent,
            'expected_margin' => $expected,
            'actual_margin' => $actual,
            'clawback_amount' => $excess,
            // ارز از خود تراکنش پورسانت خوانده می‌شود؛ هر سه مبلغ ردیف با همین ارز ثبت شده‌اند.
            'currency' => $marginTx->moneyCurrency()->value,
            'reason' => __('accounting_corrections.reason_overpaid', [
                'account' => $account->remote_username,
                'expected' => format_money($expected, $marginTx->moneyCurrency()),
                'actual' => format_money($actual, $marginTx->moneyCurrency()),
            ]),
        ];
    }

    protected function findMatchingSellerCharge(Account $account, Transaction $marginTx): ?Transaction
    {
        $sellerId = (int) $account->owner_seller_id;
        $marginAt = $marginTx->created_at;

        $candidates = Transaction::query()
            ->where('related_account_id', $account->id)
            ->where('user_id', $sellerId)
            ->whereIn('type', [TransactionType::Purchase, TransactionType::Renewal])
            ->when($marginAt !== null, function ($q) use ($marginAt): void {
                $q->whereBetween('created_at', [
                    $marginAt->copy()->subMinutes(2),
                    $marginAt->copy()->addMinutes(2),
                ]);
            })
            ->get();

        if ($candidates->isEmpty()) {
            return Transaction::query()
                ->where('related_account_id', $account->id)
                ->where('user_id', $sellerId)
                ->whereIn('type', [TransactionType::Purchase, TransactionType::Renewal])
                ->orderByDesc('created_at')
                ->first();
        }

        if ($marginAt === null) {
            return $candidates->sortByDesc('created_at')->first();
        }

        return $candidates->sortBy(function (Transaction $tx) use ($marginAt): int {
            return abs($tx->created_at?->diffInSeconds($marginAt) ?? 999999);
        })->first();
    }

    protected function renewalGbFor(Account $account): ?float
    {
        if ($account->purchased_data_gb !== null && (float) $account->purchased_data_gb > 0) {
            return (float) $account->purchased_data_gb;
        }

        if ($account->data_limit_bytes !== null && (int) $account->data_limit_bytes > 0) {
            return round((int) $account->data_limit_bytes / (1024 ** 3), 2);
        }

        return null;
    }
}
