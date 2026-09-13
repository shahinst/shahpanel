<?php

namespace App\Services;

use App\Enums\InvoiceType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class AccountStaffBillingAdjustmentService
{
    public function __construct(
        protected WalletService $walletService,
        protected UserPackagePricingService $pricingService,
        protected ActivityLogService $activityLogService,
    ) {}

    public function adjustPurchasePrice(Account $account, string $newCharge, User $admin): void
    {
        if ($account->refunded_at !== null) {
            throw new InvalidArgumentException(__('accounts.cannot_adjust_refunded'));
        }

        $invoice = Invoice::query()
            ->where('account_id', $account->id)
            ->where('type', InvoiceType::NewAccount)
            ->orderByDesc('issued_at')
            ->first();

        if ($invoice === null) {
            throw new InvalidArgumentException(__('accounts.no_invoice_for_adjustment'));
        }

        $oldCharge = money_string($invoice->total);
        $newCharge = money_string($newCharge);

        if (bccomp($oldCharge, $newCharge, 2) === 0) {
            return;
        }

        $account->loadMissing(['ownerSeller.parent', 'packageDuration.package']);
        $buyer = $account->ownerSeller;
        $duration = $account->packageDuration;

        if ($buyer === null || $duration === null) {
            throw new InvalidArgumentException(__('accounts.adjustment_context_missing'));
        }

        $gb = $account->purchased_data_gb !== null ? (float) $account->purchased_data_gb : null;
        $newEconomics = $this->pricingService->resolvePurchaseEconomics(
            $buyer,
            $duration,
            $newCharge,
            $gb,
            scaleCommissionsToCharge: true,
        );

        $ledger = $this->purchaseLedgerForInvoice((int) $account->id, (int) $invoice->id);
        $oldEconomics = $this->economicsFromLedger($ledger);

        if (bccomp($newEconomics['buyer_charge'], $oldEconomics['buyer_charge'], 2) > 0) {
            $buyerDelta = bcsub($newEconomics['buyer_charge'], $oldEconomics['buyer_charge'], 2);
            $this->walletService->assertSufficientBalance($buyer, $buyerDelta);
        }

        try {
            DB::transaction(function () use (
                $account,
                $invoice,
                $admin,
                $buyer,
                $newEconomics,
                $oldEconomics,
                $oldCharge,
                $newCharge,
            ): void {
                $context = [
                    'related_account_id' => $account->id,
                    'related_invoice_id' => $invoice->id,
                    'source_user_id' => $buyer->id,
                ];

                $this->applyParticipantDelta(
                    $buyer,
                    bcsub($newEconomics['buyer_charge'], $oldEconomics['buyer_charge'], 2),
                    array_merge($context, ['description' => 'Admin price adjustment — buyer charge']),
                    debitWhenPositive: true,
                );

                if ($newEconomics['agent'] !== null) {
                    $this->applyParticipantDelta(
                        $newEconomics['agent'],
                        bcsub($newEconomics['agent_margin'], $oldEconomics['agent_margin'], 2),
                        array_merge($context, ['description' => 'Admin price adjustment — agent margin']),
                        debitWhenPositive: false,
                    );
                }

                $this->applyParticipantDelta(
                    $newEconomics['admin'],
                    bcsub($newEconomics['admin_revenue'], $oldEconomics['admin_revenue'], 2),
                    array_merge($context, ['description' => 'Admin price adjustment — admin revenue']),
                    debitWhenPositive: false,
                );

                $invoice->update([
                    'subtotal' => $newCharge,
                    'total' => $newCharge,
                ]);

                InvoiceItem::query()
                    ->where('invoice_id', $invoice->id)
                    ->update([
                        'unit_price' => $newCharge,
                        'total' => $newCharge,
                    ]);

                $this->activityLogService->log($admin, 'account.price_adjusted', $account, [
                    'invoice_id' => $invoice->id,
                    'old_charge' => $oldCharge,
                    'new_charge' => $newCharge,
                ]);
            });
        } catch (Throwable $exception) {
            throw $exception;
        }
    }

    /**
     * @param  Collection<int, Transaction>  $ledger
     * @param  array<string, mixed>  $templateEconomics
     * @return array{
     *     buyer_charge: string,
     *     agent_margin: string,
     *     admin_revenue: string
     * }
     */
    protected function economicsFromLedger(Collection $ledger): array
    {
        $amounts = [
            'buyer_charge' => '0.00',
            'agent_margin' => '0.00',
            'admin_revenue' => '0.00',
        ];

        foreach ($ledger as $transaction) {
            $type = $this->resolveTransactionType($transaction);

            if ($type === null) {
                continue;
            }

            $amount = money_string($transaction->amount);

            if (in_array($type, [TransactionType::Purchase, TransactionType::Renewal], true)) {
                $amounts['buyer_charge'] = bcadd($amounts['buyer_charge'], $amount, 2);
            } elseif ($type === TransactionType::Margin) {
                $amounts['agent_margin'] = bcadd($amounts['agent_margin'], $amount, 2);
            } elseif (in_array($type, [TransactionType::Revenue, TransactionType::Commission], true)) {
                $amounts['admin_revenue'] = bcadd($amounts['admin_revenue'], $amount, 2);
            }
        }

        return $amounts;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function applyParticipantDelta(
        User $user,
        string $delta,
        array $context,
        bool $debitWhenPositive,
    ): void {
        if (bccomp($delta, '0', 2) === 0) {
            return;
        }

        $amount = money_string(ltrim($delta, '-'));

        if (bccomp($delta, '0', 2) > 0) {
            if ($debitWhenPositive) {
                $this->walletService->debit($user, $amount, TransactionType::Adjustment, $context);
            } else {
                $this->walletService->credit($user, $amount, TransactionType::Adjustment, $context);
            }

            return;
        }

        if ($debitWhenPositive) {
            $this->walletService->credit($user, $amount, TransactionType::Adjustment, $context);
        } else {
            $this->walletService->debit($user, $amount, TransactionType::Adjustment, $context, allowNegative: true);
        }
    }

    /**
     * @return Collection<int, Transaction>
     */
    protected function purchaseLedgerForInvoice(int $accountId, int $invoiceId): Collection
    {
        return Transaction::query()
            ->where('related_account_id', $accountId)
            ->where('related_invoice_id', $invoiceId)
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

    protected function resolveTransactionType(Transaction $transaction): ?TransactionType
    {
        $type = $transaction->type;

        if ($type instanceof TransactionType) {
            return $type;
        }

        return TransactionType::tryFrom((string) $type);
    }
}
