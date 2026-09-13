<?php

namespace App\Services;

use App\Enums\MoneyCurrency;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletAdjustment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class WalletAdjustmentService
{
    public function __construct(
        protected WalletService $walletService,
        protected ActivityLogService $activityLogService,
    ) {}

    public function adjust(
        User $target,
        string $amount,
        string $direction,
        User $admin,
        ?string $note = null,
        string $scope = 'single',
        MoneyCurrency|string|null $currency = null
    ): WalletAdjustment {
        if (! in_array($admin->role, [UserRole::Admin], true)) {
            throw new InvalidArgumentException('فقط ادمین می‌تواند موجودی را تغییر دهد.');
        }

        if (! in_array($target->role, [UserRole::Agent, UserRole::Seller], true)) {
            throw new InvalidArgumentException('فقط نماینده یا فروشنده قابل شارژ است.');
        }

        if (! in_array($direction, ['credit', 'debit'], true)) {
            throw new InvalidArgumentException('جهت شارژ نامعتبر است.');
        }

        if (bccomp($amount, '0', 2) <= 0) {
            throw new InvalidArgumentException('مبلغ باید بزرگ‌تر از صفر باشد.');
        }

        $currencyCode = MoneyCurrency::normalize(
            $currency instanceof MoneyCurrency ? $currency->value : $currency
        )->value;

        return DB::transaction(function () use ($target, $amount, $direction, $admin, $note, $scope, $currencyCode): WalletAdjustment {
            $context = [
                'description' => $note ?: ($direction === 'credit' ? 'شارژ دستی ادمین' : 'کسر دستی ادمین'),
                'source_user_id' => $admin->id,
                'currency' => $currencyCode,
            ];

            $transaction = $direction === 'credit'
                ? $this->walletService->credit($target, $amount, TransactionType::Adjustment, $context)
                : $this->walletService->debit($target, $amount, TransactionType::Adjustment, $context);

            $adjustment = WalletAdjustment::query()->create([
                'user_id' => $target->id,
                'admin_user_id' => $admin->id,
                'amount' => $amount,
                'direction' => $direction,
                'scope' => $scope,
                'note' => $note,
                'transaction_id' => $transaction->id,
            ]);

            $this->activityLogService->log($admin, 'wallet.adjustment', $adjustment, [
                'target_user_id' => $target->id,
                'target_username' => $target->username,
                'target_full_name' => $target->full_name,
                'direction' => $direction,
                'amount' => $amount,
                'currency' => $currencyCode,
                'balance_after' => (string) $transaction->balance_after,
                'transaction_id' => $transaction->id,
            ]);

            return $adjustment;
        });
    }

    /**
     * @return array{credited: int, failed: list<string>}
     */
    public function bulkCredit(User $admin, string $amount, ?string $note = null): array
    {
        $users = User::query()
            ->whereIn('role', [UserRole::Agent, UserRole::Seller])
            ->where('status', UserStatus::Active)
            ->get();

        return $this->bulkApply($users, $amount, 'credit', $admin, $note);
    }

    /**
     * Credit a specific set of agents/sellers (e.g. هدایا).
     *
     * @param  Collection<int, User>  $users
     * @return array{credited: int, failed: list<string>}
     */
    public function creditUsers(User $admin, Collection $users, string $amount, ?string $note = null): array
    {
        return $this->bulkApply($users, $amount, 'credit', $admin, $note);
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array{credited: int, failed: list<string>}
     */
    protected function bulkApply(Collection $users, string $amount, string $direction, User $admin, ?string $note): array
    {
        $success = 0;
        $failed = [];

        foreach ($users as $user) {
            try {
                $this->adjust($user, $amount, $direction, $admin, $note, 'bulk');
                $success++;
            } catch (InsufficientWalletBalanceException $exception) {
                $failed[] = "{$user->full_name}: {$exception->getMessage()}";
            } catch (\Throwable $exception) {
                $failed[] = "{$user->full_name}: {$exception->getMessage()}";
            }
        }

        return ['credited' => $success, 'failed' => $failed];
    }
}
