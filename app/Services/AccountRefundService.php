<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\InvoiceType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AccountRefundService
{
    protected const DESC_BUYER_RETURNED = 'Account refund — buyer charge returned';

    protected const DESC_COMMISSION_REVERSED = 'Account refund — agent commission reversed';

    protected const DESC_REVENUE_REVERSED = 'Account refund — admin revenue reversed';

    protected const DESC_BUYER_RECHARGED = 'Account reactivation — buyer refund clawed back';

    protected const DESC_COMMISSION_RESTORED = 'Account reactivation — agent commission restored';

    protected const DESC_REVENUE_RESTORED = 'Account reactivation — admin revenue restored';

    public function __construct(
        protected WalletService $walletService,
        protected AccountService $accountService,
        protected ActivityLogService $activityLogService,
        protected AgentFinancialPlanService $financialPlanService,
    ) {}

    /**
     * @return array{
     *     refund_amount: string,
     *     owner_refund_amount: string,
     *     used_amount: string,
     *     days_used: float,
     *     total_days: float,
     *     owner: User
     * }
     */
    public function refund(Account $account, User $performedBy): array
    {
        if ($account->refunded_at !== null) {
            throw new \InvalidArgumentException(__('accounts.already_refunded'));
        }

        $account->loadMissing(['ownerSeller.parent', 'package', 'packageDuration', 'server']);

        $owner = $account->ownerSeller;

        if ($owner === null) {
            throw new \InvalidArgumentException(__('accounts.refund_owner_missing'));
        }

        $invoice = Invoice::query()
            ->where('account_id', $account->id)
            ->whereIn('type', [InvoiceType::NewAccount, InvoiceType::Renewal])
            ->orderByDesc('issued_at')
            ->first();

        if ($invoice === null) {
            throw new \InvalidArgumentException(__('accounts.no_invoice_for_refund'));
        }

        $price = money_string($invoice->total);

        if (bccomp($price, '0', 2) <= 0) {
            throw new \InvalidArgumentException(__('accounts.nothing_to_refund'));
        }

        $duration = $account->packageDuration;

        if ($duration === null) {
            throw new \InvalidArgumentException(__('packages.duration_not_available'));
        }

        $totalHours = $duration->tier->durationHours();
        $hoursUsed = 0.0;

        if ($totalHours === null) {
            if ($account->isUnlimited() || $account->data_limit_bytes === null || $account->data_limit_bytes <= 0) {
                throw new \InvalidArgumentException(__('accounts.nothing_to_refund'));
            }

            $usedRatio = bcdiv((string) max(0, $account->data_used_bytes), (string) $account->data_limit_bytes, 4);
            $usedAmount = bcmul($price, $usedRatio, 2);
            $refundAmount = bcsub($price, $usedAmount, 2);
            $totalHours = 0;
        } else {
            $totalHours = max(1, $totalHours);
            // Refund the UNUSED portion of the CURRENT paid period. Remaining time is
            // derived from expiry_at (which already reflects renewals), capped at the
            // period length. Measuring elapsed time from created_at is wrong for a
            // renewed account: time since the original purchase can exceed the renewed
            // duration, so the account looks "fully used" even with days remaining.
            if ($account->expiry_at !== null) {
                $hoursRemaining = (float) min($totalHours, max(0.0, now()->diffInMinutes($account->expiry_at, false) / 60));
            } else {
                $startedAt = $account->created_at ?? now();
                $hoursRemaining = (float) max(0.0, $totalHours - min($totalHours, max(0, $startedAt->diffInMinutes(now()) / 60)));
            }
            $hoursUsed = (float) max(0.0, $totalHours - $hoursRemaining);
            $usedRatio = bcdiv(number_format($hoursUsed, 4, '.', ''), (string) $totalHours, 4);
            $usedAmount = bcmul($price, $usedRatio, 2);
            $refundAmount = bcsub($price, $usedAmount, 2);
        }

        if (bccomp($refundAmount, '0', 2) <= 0) {
            throw new \InvalidArgumentException(__('accounts.nothing_to_refund'));
        }

        $refundRatio = bcdiv($refundAmount, $price, 4);
        // Only the invoice being refunded (the current period) is clawed back. Applying
        // the ratio to the whole account history would also refund fully-consumed past
        // periods (e.g. the original purchase of a renewed account) — an over-refund.
        $ledger = $this->purchaseLedgerForAccount((int) $account->id, (int) $invoice->id);
        $ownerRefundAmount = $this->ownerRefundPortion($owner, $ledger, $refundRatio);

        try {
            DB::transaction(function () use (
                $account,
                $performedBy,
                $owner,
                $refundAmount,
                $ownerRefundAmount,
                $refundRatio,
                $usedAmount,
                $hoursUsed,
                $totalHours,
                $ledger
            ): void {
                $this->financialPlanService->restoreForAccount((int) $account->id, $refundRatio);

                $this->accountService->deactivateRemoteForRefund($account);

                $context = [
                    'related_account_id' => $account->id,
                    'description' => 'Account refund (pro-rated)',
                    'source_user_id' => $owner->id,
                ];

                foreach ($ledger as $transaction) {
                    $portion = bcmul(money_string($transaction->amount), $refundRatio, 2);

                    if (bccomp($portion, '0', 2) <= 0) {
                        continue;
                    }

                    $user = User::query()->find($transaction->user_id);

                    if ($user === null) {
                        continue;
                    }

                    $type = $this->resolveTransactionType($transaction);

                    if ($type === null) {
                        continue;
                    }

                    if (in_array($type, [TransactionType::Purchase, TransactionType::Renewal], true)) {
                        // Money the buyer (seller/agent) paid is returned to their wallet.
                        $this->walletService->credit($user, $portion, TransactionType::Refund, array_merge($context, [
                            'description' => self::DESC_BUYER_RETURNED,
                        ]));
                    } elseif (in_array($type, [TransactionType::Margin, TransactionType::Revenue, TransactionType::Commission], true)) {
                        // Commission/revenue the upline (agent) and admin earned at purchase
                        // is clawed back so a seller refund cannot leave the system out of pocket.
                        // allowNegative keeps the ledger balanced even if the earner already spent it.
                        $this->walletService->debit($user, $portion, TransactionType::Refund, array_merge($context, [
                            'description' => $type === TransactionType::Margin || $type === TransactionType::Commission
                                ? self::DESC_COMMISSION_REVERSED
                                : self::DESC_REVENUE_REVERSED,
                        ]), allowNegative: true);

                        $balanceAfter = $this->walletService->getOrCreateWallet($user)->fresh()->balance;

                        if (bccomp((string) $balanceAfter, '0', 2) < 0) {
                            Log::warning('Refund commission clawback drove wallet negative', [
                                'account_id' => $account->id,
                                'user_id' => $user->id,
                                'role' => $user->role->value,
                                'clawback' => $portion,
                                'balance_after' => $balanceAfter,
                            ]);
                        }
                    }
                }

                $account->update([
                    'status' => AccountStatus::Disabled,
                    'refunded_at' => now(),
                ]);

                $this->activityLogService->log($performedBy, 'account.refunded', $account, [
                    'refund_amount' => $refundAmount,
                    'owner_refund_amount' => $ownerRefundAmount,
                    'refund_ratio' => $refundRatio,
                    'owner_id' => (int) $owner->id,
                    'owner_role' => $owner->role->value,
                    'performed_by_id' => (int) $performedBy->id,
                    'used_amount' => $usedAmount,
                    'hours_used' => round($hoursUsed, 4),
                    'total_hours' => (int) $totalHours,
                ]);
            });
        } catch (Throwable $exception) {
            throw $exception;
        }

        return [
            'refund_amount' => $refundAmount,
            'owner_refund_amount' => $ownerRefundAmount,
            'used_amount' => $usedAmount,
            'days_used' => round($hoursUsed / 24, 2),
            'total_days' => round($totalHours / 24, 2),
            'owner' => $owner,
        ];
    }

    /**
     * Reverse the latest refund: charge the owner the same returned amount,
     * restore clawed-back commission/revenue, and re-enable the account.
     *
     * @return array{owner_refund_amount: string, owner: User}
     */
    public function reactivate(Account $account, User $performedBy): array
    {
        if ($account->refunded_at === null) {
            throw new \InvalidArgumentException(__('accounts.not_refunded'));
        }

        $account->loadMissing(['ownerSeller.parent', 'package', 'packageDuration', 'server']);

        $owner = $account->ownerSeller;

        if ($owner === null) {
            throw new \InvalidArgumentException(__('accounts.refund_owner_missing'));
        }

        $ledger = $this->lastRefundTransactions($account);

        if ($ledger->isEmpty()) {
            throw new \InvalidArgumentException(__('accounts.no_refund_ledger'));
        }

        $buyerCharges = [];
        $ownerRefundAmount = '0.00';

        foreach ($ledger as $transaction) {
            if ((string) $transaction->description !== self::DESC_BUYER_RETURNED) {
                continue;
            }

            $amount = money_string($transaction->amount);
            $userId = (int) $transaction->user_id;
            $buyerCharges[$userId] = bcadd($buyerCharges[$userId] ?? '0.00', $amount, 2);

            if ($userId === (int) $owner->id) {
                $ownerRefundAmount = bcadd($ownerRefundAmount, $amount, 2);
            }
        }

        foreach ($buyerCharges as $userId => $amount) {
            $user = User::query()->find($userId);

            if ($user === null) {
                continue;
            }

            $this->walletService->assertSufficientBalance($user, $amount);
        }

        if (bccomp($ownerRefundAmount, '0', 2) <= 0) {
            $ownerRefundAmount = $this->buyerRefundTotal($ledger);
        }

        $refundRatio = $this->lastRefundRatio($account);

        DB::transaction(function () use (
            $account,
            $performedBy,
            $owner,
            $ledger,
            $ownerRefundAmount,
            $refundRatio
        ): void {
            $this->financialPlanService->reapplyForAccount((int) $account->id, $refundRatio);

            $context = [
                'related_account_id' => $account->id,
                'description' => 'Account reactivation after refund',
                'source_user_id' => $owner->id,
            ];

            foreach ($ledger as $transaction) {
                $amount = money_string($transaction->amount);

                if (bccomp($amount, '0', 2) <= 0) {
                    continue;
                }

                $user = User::query()->find($transaction->user_id);

                if ($user === null) {
                    continue;
                }

                $description = (string) $transaction->description;

                if ($description === self::DESC_BUYER_RETURNED) {
                    $this->walletService->debit($user, $amount, TransactionType::Reactivation, array_merge($context, [
                        'description' => self::DESC_BUYER_RECHARGED,
                    ]));
                } elseif ($description === self::DESC_COMMISSION_REVERSED) {
                    $this->walletService->credit($user, $amount, TransactionType::Reactivation, array_merge($context, [
                        'description' => self::DESC_COMMISSION_RESTORED,
                    ]));
                } elseif ($description === self::DESC_REVENUE_REVERSED) {
                    $this->walletService->credit($user, $amount, TransactionType::Reactivation, array_merge($context, [
                        'description' => self::DESC_REVENUE_RESTORED,
                    ]));
                }
            }

            if ($account->isExpired()) {
                $status = AccountStatus::Expired;
            } elseif ($account->isQuotaExhausted()) {
                $status = AccountStatus::Exhausted;
            } else {
                $this->accountService->activateRemoteAfterRefund($account);
                $status = AccountStatus::Active;
            }

            $account->update([
                'status' => $status,
                'refunded_at' => null,
            ]);

            $this->activityLogService->log($performedBy, 'account.reactivated', $account, [
                'owner_refund_amount' => $ownerRefundAmount,
                'refund_ratio' => $refundRatio,
                'owner_id' => (int) $owner->id,
                'owner_role' => $owner->role->value,
                'performed_by_id' => (int) $performedBy->id,
                'status' => $status->value,
            ]);
        });

        return [
            'owner_refund_amount' => $ownerRefundAmount,
            'owner' => $owner,
        ];
    }

    protected function resolveTransactionType(Transaction $transaction): ?TransactionType
    {
        $type = $transaction->type;

        if ($type instanceof TransactionType) {
            return $type;
        }

        return TransactionType::tryFrom((string) $type);
    }

    /**
     * @param  Collection<int, Transaction>  $ledger
     */
    protected function ownerRefundPortion(User $owner, Collection $ledger, string $refundRatio): string
    {
        $total = '0';

        foreach ($ledger as $transaction) {
            if ((int) $transaction->user_id !== (int) $owner->id) {
                continue;
            }

            $type = $this->resolveTransactionType($transaction);

            if ($type === null || ! in_array($type, [TransactionType::Purchase, TransactionType::Renewal], true)) {
                continue;
            }

            $total = bcadd($total, bcmul(money_string($transaction->amount), $refundRatio, 2), 2);
        }

        return $total;
    }

    /**
     * @return Collection<int, Transaction>
     */
    protected function purchaseLedgerForAccount(int $accountId, ?int $invoiceId = null): Collection
    {
        return Transaction::query()
            ->where('related_account_id', $accountId)
            ->when($invoiceId !== null, fn ($query) => $query->where('related_invoice_id', $invoiceId))
            ->whereIn('type', [
                TransactionType::Purchase,
                TransactionType::Renewal,
                TransactionType::Margin,
                TransactionType::Revenue,
                TransactionType::Commission,
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, Transaction>
     */
    protected function lastRefundTransactions(Account $account): Collection
    {
        $since = $account->refunded_at?->copy()->subSeconds(15) ?? now()->subDay();

        return Transaction::query()
            ->where('related_account_id', $account->id)
            ->where('type', TransactionType::Refund)
            ->whereIn('description', [
                self::DESC_BUYER_RETURNED,
                self::DESC_COMMISSION_REVERSED,
                self::DESC_REVENUE_REVERSED,
            ])
            ->where('created_at', '>=', $since)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Transaction>  $ledger
     */
    protected function buyerRefundTotal(Collection $ledger): string
    {
        $total = '0.00';

        foreach ($ledger as $transaction) {
            if ((string) $transaction->description !== self::DESC_BUYER_RETURNED) {
                continue;
            }

            $total = bcadd($total, money_string($transaction->amount), 2);
        }

        return $total;
    }

    protected function lastRefundRatio(Account $account): string
    {
        $log = ActivityLog::query()
            ->where('action', 'account.refunded')
            ->where('entity_id', $account->id)
            ->orderByDesc('id')
            ->first();

        $payload = is_array($log?->payload) ? $log->payload : [];

        if (isset($payload['refund_ratio']) && bccomp((string) $payload['refund_ratio'], '0', 4) > 0) {
            return number_format((float) $payload['refund_ratio'], 4, '.', '');
        }

        $refundAmount = money_string($payload['refund_amount'] ?? '0');
        $usedAmount = money_string($payload['used_amount'] ?? '0');
        $price = bcadd($refundAmount, $usedAmount, 2);

        if (bccomp($price, '0', 2) <= 0) {
            return '1.0000';
        }

        return bcdiv($refundAmount, $price, 4);
    }
}
