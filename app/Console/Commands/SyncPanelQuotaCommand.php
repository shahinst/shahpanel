<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\AccountRenewalRepairService;
use App\Services\AccountService;
use Illuminate\Console\Command;
use Throwable;

class SyncPanelQuotaCommand extends Command
{
    protected $signature = 'accounts:sync-panel-quota
                            {--account= : شناسه اکانت}
                            {--apply : اعمال روی Pasarguard/Remnawave}
                            {--reset-traffic : در صورت نیاز ترافیک پنل هم ریست شود}';

    protected $description = 'هم‌تراز کردن سقف حجم vpnpanel با Pasarguard/Remnawave';

    public function handle(
        AccountRenewalRepairService $repairService,
        AccountService $accountService,
    ): int {
        $accountId = $this->option('account');

        if ($accountId === null || $accountId === '') {
            $this->error('شناسه اکانت را با --account بدهید.');

            return self::FAILURE;
        }

        $account = Account::query()->with(['server', 'package'])->find((int) $accountId);

        if ($account === null) {
            $this->error('اکانت پیدا نشد.');

            return self::FAILURE;
        }

        $inspection = $repairService->inspectPanelQuota($account);

        $this->line(sprintf(
            '#%d %s | %s',
            $account->id,
            $account->remote_username,
            $inspection['detail'] ?? '—',
        ));

        if (! ($inspection['ok'] ?? false)) {
            return self::FAILURE;
        }

        if (! ($inspection['mismatch'] ?? false)) {
            $this->info('هم‌خوان است — نیازی به اصلاح نیست.');

            return self::SUCCESS;
        }

        if (! (bool) $this->option('apply')) {
            $this->comment('برای اعمال: php artisan accounts:sync-panel-quota --account='.$account->id.' --apply');

            return self::SUCCESS;
        }

        try {
            $accountService->ensurePanelQuotaMatchesDatabase(
                $account->fresh(),
                resetTrafficIfNeeded: (bool) $this->option('reset-traffic'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $after = $repairService->inspectPanelQuota($account->fresh());
        $this->info('✓ '.$after['detail']);

        return self::SUCCESS;
    }
}
