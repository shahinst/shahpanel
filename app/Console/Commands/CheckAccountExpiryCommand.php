<?php

namespace App\Console\Commands;

use App\Enums\AccountBillingContext;
use App\Enums\AccountStatus;
use App\Enums\NotificationType;
use App\Models\Account;
use App\Services\AccountService;
use App\Services\PanelAlertService;
use Illuminate\Console\Command;
use Throwable;

class CheckAccountExpiryCommand extends Command
{
    protected $signature = 'accounts:check-expiry';

    protected $description = 'Disable accounts that have passed their expiry date';

    /**
     * Renew an auto-renew account from its owner's wallet, at its current
     * package and period. False when that is not possible (no money, package
     * withdrawn...), so the caller expires it as usual and the owner is told.
     */
    protected function autoRenew(Account $account, AccountService $accountService, PanelAlertService $alerts): bool
    {
        $owner = $account->ownerSeller;

        if ($owner === null) {
            return false;
        }

        try {
            $accountService->renewAccount($account, null, AccountBillingContext::Staff, $owner);
            $this->line("Auto-renewed account #{$account->id} ({$account->remote_username})");

            return true;
        } catch (Throwable $exception) {
            $alerts->notifyAccountAlert(
                $owner,
                NotificationType::Warning,
                trans_for($owner, 'backend.notify_auto_renew_failed_title'),
                trans_for($owner, 'backend.notify_auto_renew_failed_body', [
                    'username' => $account->remote_username,
                    'error' => $exception->getMessage(),
                ]),
                $account,
                'autorenew:'.$account->id,
            );

            return false;
        }
    }

    public function handle(AccountService $accountService, PanelAlertService $alerts): int
    {
        $expired = Account::query()
            ->where('status', AccountStatus::Active)
            ->whereNotNull('expiry_at')
            ->where('expiry_at', '<=', now())
            ->with(['ownerSeller', 'server'])
            ->get();

        foreach ($expired as $account) {
            if ($account->auto_renew && $this->autoRenew($account, $accountService, $alerts)) {
                continue;
            }

            try {
                // Renewed while this list was being worked through.
                if ($accountService->expireAccount($account)->status !== AccountStatus::Expired) {
                    continue;
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->error("Failed to expire account #{$account->id}: {$exception->getMessage()}");

                continue;
            }

            $recipient = $account->ownerSeller;

            if ($recipient !== null) {
                $alerts->notifyAccountAlert(
                    $recipient,
                    NotificationType::AccountExpiry,
                    trans_for($recipient, 'backend.notify_account_expired_title'),
                    trans_for($recipient, 'backend.notify_account_expired_body', ['username' => $account->remote_username]),
                    $account,
                    'expired:'.$account->id,
                );
            }

            $this->line("Expired account #{$account->id} ({$account->remote_username})");
        }

        $this->info("Processed {$expired->count()} expired account(s).");

        // Accounts that expired while their server was unreachable are still
        // on there. Retry them, but not every minute against a dead server.
        $pending = Account::query()
            ->whereNotNull('remote_disable_pending_at')
            ->where('remote_disable_pending_at', '<=', now()->subMinutes(10))
            ->with('server')
            ->limit(200)
            ->get();

        foreach ($pending as $account) {
            if ($accountService->retryPendingRemoteDisable($account)) {
                $this->line("Disabled expired account #{$account->id} on its server");
            } else {
                $account->update(['remote_disable_pending_at' => now()]);
            }
        }

        return self::SUCCESS;
    }
}
