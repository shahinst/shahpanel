<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AccountUsageLog;
use App\Services\AccountService;
use App\Services\PortalPanelTrafficService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Purges corrupted AccountUsageLog history for Pasarguard/Remnawave accounts and
 * rebaselines data_used_bytes / data_limit_bytes straight from the live panel.
 *
 * Use when the daily usage report shows inflated/phantom traffic that disagrees
 * with the panel's own used/limit (caused by historical mixed-counter deltas).
 */
class RebaselineAccountUsageCommand extends Command
{
    protected $signature = 'accounts:rebaseline-usage
                            {--account= : فقط یک اکانت (ID)}
                            {--server= : همه اکانت‌های یک سرور (ID)}
                            {--apply : اعمال روی دیتابیس (پیش‌فرض dry-run)}';

    protected $description = 'پاک‌سازی لاگ مصرف خراب و بازخوانی مصرف/سقف مستقیم از پنل پاسارگارد/Remnawave';

    public function handle(
        AccountService $accountService,
        PortalPanelTrafficService $panelTraffic,
    ): int {
        $apply = (bool) $this->option('apply');
        $accountId = $this->option('account');
        $serverId = $this->option('server');

        $query = Account::query()->with(['server', 'package', 'packageDuration']);

        if ($accountId !== null && $accountId !== '') {
            $query->whereKey((int) $accountId);
        } elseif ($serverId !== null && $serverId !== '') {
            $query->where('server_id', (int) $serverId);
        } else {
            $this->error('یکی از --account یا --server را مشخص کنید.');

            return self::FAILURE;
        }

        $accounts = $query->get()->filter(
            fn (Account $account): bool => $accountService->readsUsageFromRemotePanel($account)
        );

        if ($accounts->isEmpty()) {
            $this->warn('هیچ اکانت پاسارگارد/Remnawave‌ای پیدا نشد.');

            return self::SUCCESS;
        }

        $fixed = 0;
        $failed = 0;

        foreach ($accounts as $account) {
            try {
                $result = $this->rebaselineAccount($account, $panelTraffic, $apply);

                if ($result === null) {
                    $this->warn(sprintf('#%d %s — پنل پاسخی نداد، رد شد.', $account->id, $account->remote_username));

                    continue;
                }

                $this->line($result);
                $fixed++;
            } catch (Throwable $exception) {
                $failed++;
                $this->error(sprintf('#%d %s — %s', $account->id, $account->remote_username, $exception->getMessage()));
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'اکانت‌ها: %d | بازخوانی: %d | خطا: %d%s',
            $accounts->count(),
            $fixed,
            $failed,
            $apply ? '' : ' (dry-run)',
        ));

        if (! $apply) {
            $this->comment('برای اعمال: همان دستور را با --apply اجرا کنید.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function rebaselineAccount(Account $account, PortalPanelTrafficService $panelTraffic, bool $apply): ?string
    {
        $meta = $panelTraffic->fetchLiveTraffic($account);

        if ($meta === null || ! is_array($meta['raw'] ?? null)) {
            return null;
        }

        $normalized = $meta['normalized'] ?? [];
        $panelUsed = max(0, (int) ($normalized['used_bytes'] ?? 0));
        $panelLifetime = max(0, (int) ($normalized['lifetime_used_bytes'] ?? $panelUsed));
        $panelLimitRaw = $normalized['limit_bytes'] ?? null;
        $panelLimit = ($panelLimitRaw !== null && (int) $panelLimitRaw > 0) ? (int) $panelLimitRaw : null;

        $loggedSum = (int) AccountUsageLog::query()
            ->where('account_id', $account->id)
            ->get()
            ->sum(fn (AccountUsageLog $log): int => max(0, (int) $log->rx_delta_bytes) + max(0, (int) $log->tx_delta_bytes));

        $line = sprintf(
            '#%d %s | لاگ خراب: %s ← مصرف پنل (دوره): %s | مصرف کل پنل: %s | سقف: %s | باقیمانده: %s%s',
            $account->id,
            $account->remote_username,
            format_data_size($loggedSum),
            format_data_size($panelUsed),
            format_data_size($panelLifetime),
            $panelLimit !== null ? format_data_size($panelLimit) : '∞',
            $panelLimit !== null ? format_data_size(max(0, $panelLimit - $panelUsed)) : '∞',
            $apply ? ' ✓' : ' (dry-run)',
        );

        if (! $apply) {
            return $line;
        }

        AccountUsageLog::query()->where('account_id', $account->id)->delete();

        AccountUsageLog::query()->create([
            'account_id' => $account->id,
            'rx_delta_bytes' => 0,
            'tx_delta_bytes' => 0,
            'rx_snapshot' => $panelUsed,
            'tx_snapshot' => 0,
            'recorded_at' => now(),
        ]);

        $account->data_used_bytes = $panelUsed;
        $account->data_limit_bytes = $panelLimit;
        $account->lifetime_used_bytes = max($panelLifetime, $panelUsed);
        $account->last_sync_at = now();
        $account->save();

        return $line;
    }
}
