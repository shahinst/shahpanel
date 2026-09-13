<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\AccountBillingPackageService;
use Illuminate\Console\Command;
use Throwable;

class SyncAccountBillingPackagesCommand extends Command
{
    protected $signature = 'accounts:sync-billing-packages
                            {--dry-run : فقط گزارش، بدون ذخیره}';

    protected $description = 'هم‌تراز کردن package_id اکانت‌ها با پکیج Remnawave/سرور فعلی (بعد از انتقال Sanaei → Remnawave)';

    public function handle(AccountBillingPackageService $billingPackageService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        Account::query()
            ->whereNull('refunded_at')
            ->whereNotNull('server_id')
            ->with(['package', 'packageDuration', 'server', 'ownerSeller'])
            ->orderBy('id')
            ->chunkById(200, function ($accounts) use ($billingPackageService, $dryRun, &$updated, &$skipped, &$failed): void {
                foreach ($accounts as $account) {
                    if ($billingPackageService->isBillingCompatible($account)) {
                        $skipped++;

                        continue;
                    }

                    try {
                        $oldPackage = $account->package?->name ?? '—';
                        $billingPackage = $billingPackageService->resolveBillingPackage($account);
                        $duration = $billingPackageService->resolveBillingDuration($account, $billingPackage);

                        if ($dryRun) {
                            $this->line("#{$account->id} {$account->remote_username}: {$oldPackage} → {$billingPackage->name}");
                            $updated++;

                            continue;
                        }

                        $account->update([
                            'package_id' => $billingPackage->id,
                            'package_duration_id' => $duration->id,
                        ]);

                        $this->line("#{$account->id} {$account->remote_username}: {$oldPackage} → {$billingPackage->name}");
                        $updated++;
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->warn("#{$account->id} {$account->remote_username}: ".$exception->getMessage());
                    }
                }
            });

        $this->info("به‌روز: {$updated} | بدون تغییر: {$skipped} | خطا: {$failed}".($dryRun ? ' (dry-run)' : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
