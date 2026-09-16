<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Server;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AgentServerChangeLimitService;
use App\Services\ActivityLogService;
use App\Services\PackageService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AccountTransferService
{
    public function __construct(
        protected AccountService $accountService,
        protected AgentServerChangeLimitService $changeLimitService,
        protected ActivityLogService $activityLogService,
        protected PackageService $packageService,
    ) {}

    public function transfer(Account $account, Server $newServer, User $actor): Account
    {
        if ($account->server_id === $newServer->id) {
            throw new InvalidArgumentException(__('services.account_already_on_server'));
        }

        $account->loadMissing('package');
        $this->packageService->assertServerAllowed($account->package, $newServer);

        if ($actor->role === \App\Enums\UserRole::Agent) {
            $this->changeLimitService->assertCanChange($actor);
        }

        return DB::transaction(function () use ($account, $newServer, $actor): Account {
            $oldServerId = (int) $account->server_id;
            $account->loadMissing('server');

            $this->accountService->disableRemoteOnly($account);

            $account->update(['server_id' => $newServer->id]);
            $account->refresh();

            // WireGuard IP/interface pools are per-server — never carry the old server's
            // address across, even when both routers share an identical interface layout.
            $this->accountService->reprovisionWireguardForServer($account, $newServer);

            $this->accountService->pushAccountToServer($account->fresh(), onlyMissing: false);

            if (\Illuminate\Support\Facades\Schema::hasTable('account_server_changes')) {
                $this->changeLimitService->record($actor, $account->id, $oldServerId, $newServer->id);
            }

            $this->activityLogService->log($actor, 'account.server_transferred', $account, [
                'old_server_id' => $oldServerId,
                'new_server_id' => $newServer->id,
            ]);

            return $account->fresh(['server', 'package']);
        });
    }
}
