<?php

namespace App\Services;

use App\Enums\MoneyCurrency;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\Package;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WalletService
{
    public function getOrCreateWallet(User $user, MoneyCurrency|string|null $currency = null): Wallet
    {
        $currencyCode = MoneyCurrency::normalize(
            $currency instanceof MoneyCurrency ? $currency->value : $currency
        )->value;

        return Wallet::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'currency' => $currencyCode,
            ],
            [
                'balance' => '0.00',
                'locked_balance' => '0.00',
            ]
        );
    }

    public function resolveBuyerCharge(User $buyer, Package $package, string $price): string
    {
        return $this->formatMoney($price);
    }

    public function assertSufficientBalance(User $user, string $amount, MoneyCurrency|string|null $currency = null): void
    {
        if (bccomp($amount, '0', 2) <= 0 || $this->hasInfiniteWallet($user)) {
            return;
        }

        $wallet = $this->getOrCreateWallet($user, $currency);

        if (bccomp($this->formatMoney((string) $wallet->balance), $amount, 2) < 0) {
            throw new InsufficientWalletBalanceException();
        }
    }

    public function credit(
        User $user,
        string $amount,
        TransactionType $type,
        array $context = []
    ): Transaction {
        return $this->applyMovement($user, $amount, $type, false, $context);
    }

    /**
     * Debit a wallet. Pass $allowNegative = true for accounting clawbacks
     * (e.g. reversing an agent commission on refund) that must always settle
     * even if the user already spent the balance — the wallet may go negative.
     */
    public function debit(
        User $user,
        string $amount,
        TransactionType $type,
        array $context = [],
        bool $allowNegative = false
    ): Transaction {
        return $this->applyMovement($user, $amount, $type, true, $context, $allowNegative);
    }

    /**
     * @param  array{
     *     buyer: User,
     *     buyer_charge: string,
     *     agent: ?User,
     *     agent_margin: string,
     *     admin: User,
     *     admin_revenue: string
     * }  $economics
     * @return array{
     *     buyer_charge: string,
     *     buyer_transaction: ?Transaction,
     *     agent_margin: string,
     *     agent_transaction: ?Transaction,
     *     admin_revenue: string,
     *     admin_transaction: ?Transaction,
     *     transactions: list<Transaction>
     * }
     */
    public function processTieredPurchase(array $economics, array $context = []): array
    {
        return DB::transaction(function () use ($economics, $context) {
            $purchaseType = $context['type'] ?? TransactionType::Purchase;
            if (! $purchaseType instanceof TransactionType) {
                $purchaseType = TransactionType::from((string) $purchaseType);
            }

            $movementContext = array_merge($context, [
                'source_user_id' => $economics['buyer']->id,
            ]);

            $buyerCharge = $this->formatMoney($economics['buyer_charge']);
            $agentMargin = $this->formatMoney($economics['agent_margin']);
            $adminRevenue = $this->formatMoney($economics['admin_revenue']);

            $buyerTransaction = $this->debitIfPositive(
                $economics['buyer'],
                $buyerCharge,
                $purchaseType,
                array_merge($movementContext, [
                    'description' => $context['description'] ?? 'Package purchase debit',
                ])
            );

            $agentTransaction = null;
            if ($economics['agent'] !== null) {
                $agentTransaction = $this->creditIfPositive(
                    $economics['agent'],
                    $agentMargin,
                    TransactionType::Margin,
                    array_merge($movementContext, [
                        'description' => $context['agent_description'] ?? 'Agent margin credit',
                    ])
                );
            }

            $adminTransaction = $this->creditIfPositive(
                $economics['admin'],
                $adminRevenue,
                TransactionType::Revenue,
                array_merge($movementContext, [
                    'description' => $context['admin_description'] ?? 'Admin revenue credit',
                ])
            );

            $transactions = array_values(array_filter([
                $buyerTransaction,
                $agentTransaction,
                $adminTransaction,
            ]));

            return [
                'buyer_charge' => $buyerCharge,
                'buyer_transaction' => $buyerTransaction,
                'agent_margin' => $agentMargin,
                'agent_transaction' => $agentTransaction,
                'admin_revenue' => $adminRevenue,
                'admin_transaction' => $adminTransaction,
                'transactions' => $transactions,
            ];
        });
    }

    /**
     * @return array{amount: string, transaction: ?Transaction}
     */
    public function processPurchase(User $buyer, string $price, array $context = []): array
    {
        return DB::transaction(function () use ($buyer, $price, $context) {
            $purchaseType = $context['type'] ?? TransactionType::Purchase;
            if (! $purchaseType instanceof TransactionType) {
                $purchaseType = TransactionType::from((string) $purchaseType);
            }

            $amount = $this->formatMoney($price);
            $movementContext = array_merge($context, [
                'source_user_id' => $buyer->id,
            ]);

            $transaction = $this->debitIfPositive(
                $buyer,
                $amount,
                $purchaseType,
                array_merge($movementContext, [
                    'description' => $context['description'] ?? 'Package purchase debit',
                ])
            );

            return [
                'amount' => $amount,
                'transaction' => $transaction,
            ];
        });
    }

    protected function debitIfPositive(
        User $user,
        string $amount,
        TransactionType $type,
        array $context = []
    ): ?Transaction {
        if (bccomp($amount, '0', 2) <= 0) {
            return null;
        }

        return $this->debit($user, $amount, $type, $context);
    }

    protected function creditIfPositive(
        User $user,
        string $amount,
        TransactionType $type,
        array $context = []
    ): ?Transaction {
        if (bccomp($amount, '0', 2) <= 0) {
            return null;
        }

        return $this->credit($user, $amount, $type, $context);
    }

    protected function applyMovement(
        User $user,
        string $amount,
        TransactionType $type,
        bool $isDebit,
        array $context = [],
        bool $allowNegative = false
    ): Transaction {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new InsufficientWalletBalanceException('Movement amount must be greater than zero.');
        }

        $currency = MoneyCurrency::normalize(
            $context['currency'] ?? null
        )->value;

        return DB::transaction(function () use ($user, $amount, $type, $isDebit, $context, $allowNegative, $currency) {
            $wallet = Wallet::query()
                ->where('user_id', $user->id)
                ->where('currency', $currency)
                ->lockForUpdate()
                ->first();

            if ($wallet === null) {
                $wallet = $this->getOrCreateWallet($user, $currency);
                $wallet = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            }

            if ($wallet->currency !== $currency) {
                throw new InvalidArgumentException('Wallet currency mismatch.');
            }

            $balanceBefore = $this->formatMoney((string) $wallet->balance);
            $infiniteAdminWallet = $this->hasInfiniteWallet($user);

            if ($isDebit && ! $infiniteAdminWallet) {
                if (! $allowNegative && bccomp($balanceBefore, $amount, 2) < 0) {
                    throw new InsufficientWalletBalanceException('Insufficient wallet balance.');
                }

                $balanceAfter = bcsub($balanceBefore, $amount, 2);
            } elseif ($isDebit && $infiniteAdminWallet) {
                $balanceAfter = $balanceBefore;
            } else {
                $balanceAfter = $infiniteAdminWallet
                    ? $balanceBefore
                    : bcadd($balanceBefore, $amount, 2);
            }

            if (! $infiniteAdminWallet) {
                $wallet->balance = $balanceAfter;
                $wallet->updated_at = now();
                $wallet->save();
            } else {
                $wallet->updated_at = now();
                $wallet->save();
            }

            $payload = [
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'type' => $type,
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'source_user_id' => $context['source_user_id'] ?? null,
                'related_account_id' => $context['related_account_id'] ?? null,
                'related_invoice_id' => $context['related_invoice_id'] ?? null,
                'related_payment_request_id' => $context['related_payment_request_id'] ?? null,
                'related_gateway_payment_id' => $context['related_gateway_payment_id'] ?? null,
                'description' => $context['description'] ?? null,
                'created_at' => now(),
            ];

            if (\Illuminate\Support\Facades\Schema::hasColumn('transactions', 'currency')) {
                $payload['currency'] = $currency;
            }

            return Transaction::query()->create($payload);
        });
    }

    protected function hasInfiniteWallet(User $user): bool
    {
        return $user->role === UserRole::Admin
            && (bool) config('shahpanel.admin_wallet_infinite', true);
    }

    protected function formatMoney(string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
