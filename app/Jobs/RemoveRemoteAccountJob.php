<?php

namespace App\Jobs;

use App\Services\AccountService;
use App\Support\RemoteAccountCleanupSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Retries remote (MikroTik/panel) account removal when the synchronous attempt made
 * during the delete request failed. The account is already gone from the panel DB
 * by the time this job runs; this only cleans up the leftover remote secret/peer.
 */
class RemoveRemoteAccountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(public RemoteAccountCleanupSnapshot $snapshot) {}

    public function handle(AccountService $accounts): void
    {
        try {
            $accounts->executeRemoteCleanupFromSnapshot($this->snapshot);
        } catch (Throwable $exception) {
            Log::warning('Queued remote account removal failed', [
                'account_id' => $this->snapshot->accountId,
                'server_id' => $this->snapshot->serverId,
                'remote_username' => $this->snapshot->remoteUsername,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
