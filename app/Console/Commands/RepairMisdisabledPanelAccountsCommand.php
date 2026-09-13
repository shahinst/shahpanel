<?php

namespace App\Console\Commands;

use App\Services\AccountMisdisabledRepairService;
use Illuminate\Console\Command;

class RepairMisdisabledPanelAccountsCommand extends Command
{
    protected $signature = 'accounts:repair-misdisabled-status
                            {--apply : فعال‌سازی واقعی (پیش‌فرض فقط گزارش)}
                            {--account= : فقط یک اکانت (شناسه)}';

    protected $description = 'اکانت‌های Pasarguard/Remnawave که اشتباه غیرفعال/اتمام‌حجم شده‌اند ولی روی پنل هنوز حجم دارند را فعال می‌کند (بدون تغییر سقف حجم)';

    public function handle(AccountMisdisabledRepairService $repairService): int
    {
        $apply = (bool) $this->option('apply');
        $accountId = $this->option('account');
        $accountFilter = ($accountId !== null && $accountId !== '') ? (int) $accountId : null;

        $accounts = $repairService->findCandidates($accountFilter);

        if ($accountFilter !== null && $accounts->isEmpty()) {
            $this->warn('اکانت #'.$accountFilter.' در لیست کاندیدها یافت نشد (باید Pasarguard/Remnawave، غیرفعال/اتمام‌حجم و منقضی‌نشده باشد).');

            return self::FAILURE;
        }

        if ($accounts->isEmpty()) {
            $this->info('موردی یافت نشد.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'بررسی %d اکانت%s',
            $accounts->count(),
            $apply ? ' — حالت اعمال' : ' — dry-run (بدون تغییر)'
        ));

        $eligibleCount = 0;
        $fixedCount = 0;
        $failedCount = 0;

        foreach ($accounts as $account) {
            $inspection = $repairService->inspect($account);

            $line = sprintf(
                '#%d %s | وضعیت: %s',
                $account->id,
                $account->remote_username,
                $account->status->value,
            );

            if (! ($inspection['ok'] ?? false)) {
                $this->warn($line.' | '.$inspection['detail']);

                continue;
            }

            if (! ($inspection['eligible'] ?? false)) {
                $this->line($line.' | ✓ '.$inspection['detail']);

                continue;
            }

            $eligibleCount++;
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
        $this->info("نیاز به فعال‌سازی: {$eligibleCount} | انجام‌شده: {$fixedCount} | خطا: {$failedCount}");

        if ($eligibleCount > 0 && ! $apply) {
            $this->comment('برای اعمال: php artisan accounts:repair-misdisabled-status --apply');
        }

        return $failedCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
