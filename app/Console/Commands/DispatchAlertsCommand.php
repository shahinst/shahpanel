<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Enums\NotificationType;
use App\Models\Account;
use App\Models\User;
use App\Enums\UserRole;
use App\Enums\SyncLogStatus;
use App\Models\ServerSyncLog;
use App\Services\PanelAlertService;
use Illuminate\Console\Command;

class DispatchAlertsCommand extends Command
{
    protected $signature = 'alerts:dispatch';

    protected $description = 'Dispatch panel alerts for expiring accounts, quota warnings, and sync failures';

    public function handle(PanelAlertService $alerts): int
    {
        $this->alertExpiringAccounts($alerts);
        $this->alertQuotaWarnings($alerts);
        $this->alertRecentSyncFailures($alerts);

        return self::SUCCESS;
    }

    protected function alertExpiringAccounts(PanelAlertService $alerts): void
    {
        $threshold = now()->addDays(3);

        $accounts = Account::query()
            ->where('status', AccountStatus::Active)
            ->whereNotNull('expiry_at')
            ->whereBetween('expiry_at', [now(), $threshold])
            ->with('ownerSeller')
            ->get();

        $sent = 0;

        foreach ($accounts as $account) {
            $recipient = $account->ownerSeller;

            if ($recipient === null) {
                continue;
            }

            $created = $alerts->notifyAccountAlert(
                $recipient,
                NotificationType::AccountExpiry,
                'یادآوری انقضا',
                "اکانت {$account->remote_username} تا ".jalali_date($account->expiry_at, 'Y/m/d').' منقضی می‌شود.',
                $account,
                'expiry:'.$account->id,
            );

            if ($created !== null) {
                $sent++;
            }
        }

        $this->line("Expiring alerts: {$sent}/{$accounts->count()}");
    }

    protected function alertQuotaWarnings(PanelAlertService $alerts): void
    {
        $accounts = Account::query()
            ->where('status', AccountStatus::Active)
            ->whereNotNull('data_limit_bytes')
            ->where('data_limit_bytes', '>', 0)
            ->with('ownerSeller')
            ->get()
            ->filter(function (Account $account) {
                $usedRatio = $account->data_used_bytes / $account->data_limit_bytes;

                return $usedRatio >= 0.9 && $usedRatio < 1;
            });

        $sent = 0;

        foreach ($accounts as $account) {
            $recipient = $account->ownerSeller;

            if ($recipient === null) {
                continue;
            }

            $created = $alerts->notifyAccountAlert(
                $recipient,
                NotificationType::QuotaExhausted,
                'هشدار حجم',
                "اکانت {$account->remote_username} بیش از ۹۰٪ حجم مصرف شده است.",
                $account,
                'quota90:'.$account->id,
            );

            if ($created !== null) {
                $sent++;
            }
        }

        $this->line("Quota warnings: {$sent}/{$accounts->count()}");
    }

    protected function alertRecentSyncFailures(PanelAlertService $alerts): void
    {
        $logs = ServerSyncLog::query()
            ->where('started_at', '>=', now()->subHour())
            ->whereIn('status', [SyncLogStatus::Failed, SyncLogStatus::Partial])
            ->where('errors_count', '>', 0)
            ->with('server')
            ->orderByDesc('id')
            ->get();

        $admins = User::query()->where('role', UserRole::Admin)->get();
        $sent = 0;
        $seenServers = [];

        foreach ($logs as $log) {
            if ($log->server_id === null || isset($seenServers[$log->server_id])) {
                continue;
            }

            $seenServers[$log->server_id] = true;

            // Skip if the server already recovered on a newer sync run.
            $latest = ServerSyncLog::query()
                ->where('server_id', $log->server_id)
                ->whereIn('status', [
                    SyncLogStatus::Success,
                    SyncLogStatus::Partial,
                    SyncLogStatus::Failed,
                ])
                ->orderByDesc('id')
                ->first();

            if ($latest === null || $latest->id !== $log->id) {
                continue;
            }

            if (! in_array($latest->status, [SyncLogStatus::Failed, SyncLogStatus::Partial], true)) {
                continue;
            }

            foreach ($admins as $admin) {
                $link = \Illuminate\Support\Facades\Route::has('admin.servers.index')
                    ? route('admin.servers.index')
                    : null;

                $created = $alerts->notifyOnce(
                    $admin,
                    NotificationType::ServerSync,
                    'خطا در همگام‌سازی سرور',
                    "سرور {$log->server?->name} — {$log->errors_count} خطا",
                    'sync:'.$log->id.':user:'.$admin->id,
                    $link,
                    6,
                );

                if ($created !== null) {
                    $sent++;
                }
            }
        }

        $this->line("Sync failure alerts: {$sent}");
    }
}
