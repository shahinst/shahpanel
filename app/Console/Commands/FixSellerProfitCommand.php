<?php

namespace App\Console\Commands;

use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\Transaction;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixSellerProfitCommand extends Command
{
    protected $signature = 'accounting:fix-seller-profit
                            {--apply : اعمال واقعی اصلاح (پیش‌فرض فقط گزارش/dry-run است)}
                            {--seller= : محدود کردن به یک فروشنده مشخص (شناسه کاربر)}';

    protected $description = 'حذف سود اشتباه ثبت‌شده برای فروشنده‌ها و برگرداندن آن به‌صورت کسری تا حسابداری بهم نریزد';

    /** Marker stored in the correction transaction so re-runs stay idempotent. */
    private const MARKER = '[seller-profit-clawback]';

    /** Transaction types that represent sales profit/earnings. */
    private const PROFIT_TYPES = [
        TransactionType::Margin,
        TransactionType::Commission,
        TransactionType::Revenue,
    ];

    public function handle(WalletService $wallet): int
    {
        $apply = (bool) $this->option('apply');
        $sellerOption = $this->option('seller');

        $sellersQuery = User::query()->where('role', UserRole::Seller);

        if ($sellerOption !== null && $sellerOption !== '') {
            $sellersQuery->whereKey((int) $sellerOption);
        }

        // Only sellers that actually have profit transactions are worth scanning.
        $sellerIdsWithProfit = Transaction::query()
            ->whereIn('type', self::PROFIT_TYPES)
            ->whereIn('user_id', (clone $sellersQuery)->select('id'))
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($sellerIdsWithProfit === []) {
            $this->info('هیچ سودی برای هیچ فروشنده‌ای ثبت نشده — نیازی به اصلاح نیست.');

            return self::SUCCESS;
        }

        $sellers = $sellersQuery->whereIn('id', $sellerIdsWithProfit)->orderBy('id')->get();

        $this->line($apply
            ? 'حالت اعمال: اصلاح‌ها روی کیف پول فروشنده‌ها ثبت می‌شوند.'
            : 'حالت گزارش (dry-run): هیچ تغییری ثبت نمی‌شود. برای اعمال از --apply استفاده کنید.');
        $this->newLine();

        $rows = [];
        $grandTotal = '0.00';
        $appliedCount = 0;

        foreach ($sellers as $seller) {
            $remaining = $this->remainingProfitFor((int) $seller->id);

            if (bccomp($remaining, '0', 2) <= 0) {
                continue;
            }

            $grandTotal = bcadd($grandTotal, $remaining, 2);

            if ($apply) {
                DB::transaction(function () use ($wallet, $seller, $remaining): void {
                    $wallet->debit(
                        $seller,
                        $remaining,
                        TransactionType::Adjustment,
                        [
                            'source_user_id' => $seller->id,
                            'description' => 'تصحیح سود اشتباه فروش — '.self::MARKER,
                        ],
                        allowNegative: true,
                    );
                });

                $appliedCount++;
            }

            $rows[] = [
                $seller->id,
                $seller->full_name ?? $seller->username ?? '—',
                format_toman($remaining),
                $apply ? 'اصلاح شد' : 'در انتظار اعمال',
            ];
        }

        if ($rows === []) {
            $this->info('سودی که قبلاً اصلاح نشده باشد پیدا نشد — همه‌چیز مرتب است.');

            return self::SUCCESS;
        }

        $this->table(
            ['شناسه', 'فروشنده', 'سود قابل برگشت (کسری)', 'وضعیت'],
            $rows,
        );

        $this->newLine();
        $this->info('تعداد فروشنده‌ها: '.count($rows));
        $this->info('جمع کل سود قابل برگشت: '.format_toman($grandTotal));

        if ($apply) {
            $this->info("اصلاح روی {$appliedCount} فروشنده اعمال شد.");
        } else {
            $this->warn('برای اعمال واقعی این اصلاح‌ها دستور را با --apply اجرا کنید.');
        }

        return self::SUCCESS;
    }

    /**
     * Net profit still standing in the seller's wallet that has not yet been
     * reversed (by an earlier refund clawback or a previous run of this command).
     */
    private function remainingProfitFor(int $sellerId): string
    {
        $transactions = Transaction::query()
            ->where('user_id', $sellerId)
            ->whereIn('type', array_merge(self::PROFIT_TYPES, [
                TransactionType::Refund,
                TransactionType::Adjustment,
            ]))
            ->get();

        $earned = '0.00';
        $clawedBack = '0.00';

        foreach ($transactions as $transaction) {
            $amount = (string) $transaction->amount;

            if (in_array($transaction->type, self::PROFIT_TYPES, true)) {
                $earned = bcadd($earned, $amount, 2);

                continue;
            }

            if ($transaction->type === TransactionType::Refund && $this->isDebit($transaction)) {
                // A refund that reduced the seller's balance is an earnings reversal.
                $clawedBack = bcadd($clawedBack, $amount, 2);

                continue;
            }

            if (
                $transaction->type === TransactionType::Adjustment
                && $this->isDebit($transaction)
                && str_contains((string) $transaction->description, self::MARKER)
            ) {
                // A correction this command already applied previously.
                $clawedBack = bcadd($clawedBack, $amount, 2);
            }
        }

        $remaining = bcsub($earned, $clawedBack, 2);

        return bccomp($remaining, '0', 2) > 0 ? $remaining : '0.00';
    }

    private function isDebit(Transaction $transaction): bool
    {
        return bccomp((string) $transaction->balance_after, (string) $transaction->balance_before, 2) < 0;
    }
}
