<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Enums\NotificationType;
use App\Models\Account;
use App\Models\Setting;
use App\Models\User;
use App\Enums\UserRole;
use App\Enums\UserStatus;
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
        $this->alertLowBalances($alerts);

        return self::SUCCESS;
    }

    /**
     * The account's own client, when the admin turned client alerts on.
     */
    protected function clientRecipient(Account $account): ?User
    {
        if (Setting::getValue('alert_clients_enabled', '0') !== '1') {
            return null;
        }

        $client = $account->clientUser;

        return $client !== null && $client->role === UserRole::Client && $client->status === UserStatus::Active
            ? $client
            : null;
    }

    /**
     * Agents and sellers whose wallet fell under the admin's threshold, once a
     * day, so they top up before a renewal or a client purchase fails.
     */
    protected function alertLowBalances(PanelAlertService $alerts): void
    {
        $threshold = (float) Setting::getValue('alert_low_balance_amount', '0');

        if ($threshold <= 0) {
            return;
        }

        $sent = 0;
        $wallets = app(\App\Services\WalletService::class);

        User::query()
            ->whereIn('role', [UserRole::Agent->value, UserRole::Seller->value])
            ->where('status', UserStatus::Active->value)
            ->chunkById(200, function ($users) use ($alerts, $wallets, $threshold, &$sent): void {
                foreach ($users as $user) {
                    $balance = (float) $wallets->getOrCreateWallet($user)->balance;

                    if ($balance >= $threshold) {
                        continue;
                    }

                    $panel = $user->role === UserRole::Agent ? 'agent' : 'seller';
                    $link = \Illuminate\Support\Facades\Route::has($panel.'.wallet.top-up.create')
                        ? route($panel.'.wallet.top-up.create')
                        : null;

                    $created = $alerts->notifyOnce(
                        $user,
                        NotificationType::Warning,
                        trans_for($user, 'backend.notify_low_balance_title'),
                        trans_for($user, 'backend.notify_low_balance_body', ['balance' => format_money($balance)]),
                        'lowbal:'.$user->id,
                        $link,
                    );

                    if ($created !== null) {
                        $sent++;
                    }
                }
            });

        $this->line("Low balance alerts: {$sent}");
    }

    protected function alertExpiringAccounts(PanelAlertService $alerts): void
    {
        $days = max(1, min(30, (int) Setting::getValue('alert_expiry_days', '3')));
        $threshold = now()->addDays($days);

        $accounts = Account::query()
            ->where('status', AccountStatus::Active)
            ->whereNotNull('expiry_at')
            ->whereBetween('expiry_at', [now(), $threshold])
            ->with(['ownerSeller', 'clientUser'])
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
                trans_for($recipient, 'backend.notify_expiry_reminder_title'),
                trans_for($recipient, 'backend.notify_expiry_reminder_body', [
                    'username' => $account->remote_username,
                    'date' => jalali_date($account->expiry_at, 'Y/m/d'),
                ]),
                $account,
                'expiry:'.$account->id,
            );

            if ($created !== null) {
                $sent++;
            }

            if (($client = $this->clientRecipient($account)) !== null) {
                $alerts->notifyAccountAlert(
                    $client,
                    NotificationType::AccountExpiry,
                    trans_for($client, 'backend.notify_expiry_reminder_title'),
                    trans_for($client, 'backend.notify_expiry_reminder_body', [
                        'username' => $account->remote_username,
                        'date' => jalali_date($account->expiry_at, 'Y/m/d'),
                    ]),
                    $account,
                    'expiry:client:'.$account->id,
                );
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
            // Filtered in SQL: loading every limited account just to keep the
            // few near their limit ran out of memory on large panels.
            ->whereRaw('data_used_bytes >= data_limit_bytes * 0.9')
            ->whereColumn('data_used_bytes', '<', 'data_limit_bytes')
            ->with(['ownerSeller', 'clientUser'])
            ->get();

        $sent = 0;

        foreach ($accounts as $account) {
            $recipient = $account->ownerSeller;

            if ($recipient === null) {
                continue;
            }

            $created = $alerts->notifyAccountAlert(
                $recipient,
                NotificationType::QuotaExhausted,
                trans_for($recipient, 'backend.notify_quota_warning_title'),
                trans_for($recipient, 'backend.notify_quota_warning_body', ['username' => $account->remote_username]),
                $account,
                'quota90:'.$account->id,
            );

            if ($created !== null) {
                $sent++;
            }

            if (($client = $this->clientRecipient($account)) !== null) {
                $alerts->notifyAccountAlert(
                    $client,
                    NotificationType::QuotaExhausted,
                    trans_for($client, 'backend.notify_quota_warning_title'),
                    trans_for($client, 'backend.notify_quota_warning_body', ['username' => $account->remote_username]),
                    $account,
                    'quota90:client:'.$account->id,
                );
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
                    trans_for($admin, 'backend.notify_server_sync_error_title'),
                    trans_for($admin, 'backend.notify_server_sync_error_body', [
                        'server' => $log->server?->name ?? '',
                        'errors' => $log->errors_count,
                    ]),
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
