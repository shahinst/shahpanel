<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\Server;
use Throwable;

class ServerAccountPushService
{
    public function __construct(
        protected AccountService $accountService,
        protected ServerInterfaceSyncService $interfaceSyncService,
    ) {}

    /**
     * Push panel accounts to remote server (migration / repair).
     * DB fields data_limit_bytes, data_used_bytes, expiry_at are the source of truth.
     *
     * @return array{pushed: int, skipped: int, failed: int, lines: list<string>, errors: list<string>}
     */
    public function pushAll(Server $server, bool $onlyMissing = false): array
    {
        $lines = [];
        $errors = [];
        $pushed = 0;
        $skipped = 0;
        $failed = 0;

        $accounts = Account::query()
            ->where('server_id', $server->id)
            ->whereNotIn('status', [AccountStatus::Pending])
            ->orderBy('id')
            ->get();

        foreach ($accounts as $account) {
            try {
                $result = $this->accountService->pushAccountToServer($account, $onlyMissing);

                if ($result['action'] === 'skipped') {
                    $skipped++;
                } else {
                    $pushed++;
                }

                $lines[] = $result['message'];
            } catch (Throwable $exception) {
                $failed++;
                $errors[] = "اکانت #{$account->id} ({$account->remote_username}): ".$exception->getMessage();
            }
        }

        $lines[] = "جمع: {$pushed} اعمال شد، {$skipped} رد شد، {$failed} خطا.";

        return compact('pushed', 'skipped', 'failed', 'lines', 'errors');
    }
}
