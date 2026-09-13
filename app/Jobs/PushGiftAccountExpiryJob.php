<?php

namespace App\Jobs;

use App\Models\Account;
use App\Services\AccountService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes gift-extended expiry/status to remote VPN panels in the background.
 */
class PushGiftAccountExpiryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;

    public int $tries = 2;

    public int $backoff = 20;

    /**
     * @param  list<int>  $accountIds
     */
    public function __construct(public array $accountIds) {}

    public function handle(AccountService $accountService): void
    {
        $accounts = Account::query()
            ->whereIn('id', $this->accountIds)
            ->with(['server', 'package', 'packageDuration'])
            ->get();

        foreach ($accounts as $account) {
            if ($account->server === null) {
                continue;
            }

            try {
                $accountService->pushAccountToServer($account, false);
            } catch (Throwable $exception) {
                Log::warning('Gift reward: queued remote expiry push failed', [
                    'account_id' => $account->id,
                    'server_id' => $account->server_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
