<?php

namespace App\Console\Commands;

use App\Services\AccountRenewalRepairService;
use Illuminate\Console\Command;

class RepairInactivePackageRenewalsCommand extends Command
{
    protected $signature = 'accounts:repair-inactive-renewals
                            {--days= : فقط تمدیدهای N روز اخیر (خالی = همه)}
                            {--apply : اعمال اصلاح روی پنل (پیش‌فرض فقط گزارش)}
                            {--account= : فقط یک اکانت (شناسه)}';

    protected $description = 'اکانت‌های تمدیدشده روی پکیج غیرفعال که حجم پنل با shahpanel ناسازگار است را پیدا و اصلاح می‌کند';

    public function handle(AccountRenewalRepairService $repairService): int
    {
        $apply = (bool) $this->option('apply');
        $daysOption = $this->option('days');
        $withinDays = ($daysOption === null || $daysOption === '') ? null : max(1, (int) $daysOption);
        $accountId = $this->option('account');

        if ($accountId !== null && $accountId !== '') {
            $accounts = $repairService->findRenewalsOnInactivePackages($withinDays)
                ->where('id', (int) $accountId)
                ->values();

            if ($accounts->isEmpty()) {
                $this->warn('اکانت #'.$accountId.' در لیست تمدید روی پکیج غیرفعال یافت نشد.');

                return self::FAILURE;
            }
        } else {
            $accounts = $repairService->findRenewalsOnInactivePackages($withinDays);
        }

        if ($accounts->isEmpty()) {
            $this->info('موردی یافت نشد.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'یافت شد: %d اکانت با فاکتور تمدید روی پکیج غیرفعال%s',
            $accounts->count(),
            $apply ? ' — حالت اعمال' : ' — dry-run'
        ));

        $mismatchCount = 0;
        $fixedCount = 0;
        $failedCount = 0;

        foreach ($accounts as $account) {
            $packageName = $account->package?->name ?? '—';
            $inspection = $repairService->inspectPanelQuota($account);
            $lastRenewal = $account->invoices->first()?->issued_at?->format('Y-m-d H:i') ?? '—';

            $line = sprintf(
                '#%d %s | پکیج: %s (غیرفعال) | آخرین تمدید: %s',
                $account->id,
                $account->remote_username,
                $packageName,
                $lastRenewal,
            );

            if (! ($inspection['ok'] ?? false)) {
                $this->warn($line.' | '.$inspection['detail']);

                continue;
            }

            if (! ($inspection['mismatch'] ?? false)) {
                $this->line($line.' | ✓ '.$inspection['detail']);

                continue;
            }

            $mismatchCount++;
            $this->warn($line.' | ⚠ '.$inspection['detail']);

            if (! $apply) {
                continue;
            }

            $result = $repairService->repair($account);

            if ($result['ok']) {
                $fixedCount++;
                $this->info('  ↳ '.$result['message']);
            } else {
                $failedCount++;
                $this->error('  ↳ '.$result['message']);
            }
        }

        $this->newLine();
        $this->info("ناسازگار: {$mismatchCount} | اصلاح‌شده: {$fixedCount} | خطا: {$failedCount}");

        if ($mismatchCount > 0 && ! $apply) {
            $this->comment('برای اعمال: php artisan accounts:repair-inactive-renewals --apply');
        }

        return $failedCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
