<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\Server;
use App\Support\OperationProgress;
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
    public function pushAll(Server $server, bool $onlyMissing = false, ?OperationProgress $progress = null): array
    {
        $lines = [];
        $errors = [];
        $pushed = 0;
        $skipped = 0;
        $failed = 0;

        $accounts = Account::query()
            ->where('server_id', $server->id)
            ->whereNotIn('status', [AccountStatus::Pending])
            ->with(['server', 'package'])
            ->orderBy('id')
            ->get();

        $progress?->begin($accounts->count(), __('servers.progress_push_start', [
            'count' => $accounts->count(),
            'server' => $server->name,
        ]));

        $this->accountService->withServerBatch($server, function () use ($accounts, $onlyMissing, $progress, &$lines, &$errors, &$pushed, &$skipped, &$failed): void {
            foreach ($accounts as $account) {
                $label = $account->remote_username ?: ('#'.$account->id);
                $started = microtime(true);

                try {
                    $result = $this->accountService->pushAccountToServer($account, $onlyMissing);
                    $seconds = number_format(microtime(true) - $started, 2);

                    if ($result['action'] === 'skipped') {
                        $skipped++;
                        $progress?->advance("{$label} — {$result['message']} ({$seconds}s)", 'skip');
                    } else {
                        $pushed++;
                        $progress?->advance("{$label} — {$result['message']} ({$seconds}s)", 'ok');
                    }

                    $lines[] = $result['message'];
                } catch (Throwable $exception) {
                    $failed++;
                    $seconds = number_format(microtime(true) - $started, 2);
                    $errors[] = "اکانت #{$account->id} ({$account->remote_username}): ".$exception->getMessage();
                    $progress?->advance("{$label} — ".__('servers.progress_error').': '.$exception->getMessage()." ({$seconds}s)", 'error');
                }
            }
        });

        $lines[] = "جمع: {$pushed} اعمال شد، {$skipped} رد شد، {$failed} خطا.";

        return compact('pushed', 'skipped', 'failed', 'lines', 'errors');
    }
}
