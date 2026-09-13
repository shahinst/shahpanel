<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ClientAccountLinkService
{
    public function __construct(
        protected EndUserService $endUserService,
        protected ActivityLogService $activityLogService,
    ) {}

    /**
     * @return Collection<int, Account>
     */
    public function unassignedAccountsForClient(User $client, User $viewer): Collection
    {
        if ($client->role !== UserRole::Client) {
            throw new InvalidArgumentException(__('clients.invalid_client'));
        }

        if (! $this->endUserService->canViewerManageClient($viewer, $client)) {
            throw new InvalidArgumentException(__('clients.client_not_owned'));
        }

        if ($client->parent_id === null) {
            return new Collection;
        }

        return Account::query()
            ->whereNull('client_user_id')
            ->where('owner_seller_id', $client->parent_id)
            ->ownedByHierarchy($viewer)
            ->with(['package', 'packageDuration', 'server', 'ownerSeller'])
            ->orderByDesc('created_at')
            ->get();
    }

    public function assign(Account $account, User $targetClient, User $actor): Account
    {
        $this->assertTargetClient($targetClient, $actor);
        $this->assertAccountMatchesClientOwner($account, $targetClient);

        if ($account->client_user_id !== null) {
            throw new InvalidArgumentException(__('clients.assign_requires_unassigned'));
        }

        return DB::transaction(function () use ($account, $targetClient, $actor): Account {
            $account->update(['client_user_id' => $targetClient->id]);

            $this->activityLogService->log(
                $actor,
                'account.client_assigned',
                $account,
                ['client_id' => $targetClient->id]
            );

            return $account->fresh();
        });
    }

    public function reassign(Account $account, User $targetClient, User $actor): Account
    {
        if ($actor->role !== UserRole::Agent && $actor->role !== UserRole::Admin) {
            throw new InvalidArgumentException(__('clients.reassign_agent_only'));
        }

        $this->assertTargetClient($targetClient, $actor);
        $this->assertAccountMatchesClientOwner($account, $targetClient);

        $account->loadMissing('clientUser');

        if ($account->client_user_id === null) {
            throw new InvalidArgumentException(__('clients.account_has_no_client'));
        }

        $currentClient = $account->clientUser;

        if ($currentClient === null) {
            throw new InvalidArgumentException(__('clients.account_has_no_client'));
        }

        if (! $this->endUserService->canViewerManageClient($actor, $currentClient)) {
            throw new InvalidArgumentException(__('clients.account_not_owned'));
        }

        if ((int) $currentClient->id === (int) $targetClient->id) {
            return $account;
        }

        return DB::transaction(function () use ($account, $currentClient, $targetClient, $actor): Account {
            $account->update(['client_user_id' => $targetClient->id]);

            $this->activityLogService->log(
                $actor,
                'account.client_reassigned',
                $account,
                [
                    'from_client_id' => $currentClient->id,
                    'to_client_id' => $targetClient->id,
                ]
            );

            return $account->fresh();
        });
    }

    protected function assertTargetClient(User $targetClient, User $actor): void
    {
        if ($targetClient->role !== UserRole::Client) {
            throw new InvalidArgumentException(__('clients.invalid_client'));
        }

        if (! $this->endUserService->canViewerManageClient($actor, $targetClient)) {
            throw new InvalidArgumentException(__('clients.client_not_owned'));
        }
    }

    protected function assertAccountMatchesClientOwner(Account $account, User $client): void
    {
        if ($client->parent_id === null) {
            throw new InvalidArgumentException(__('clients.missing_parent'));
        }

        if ((int) $account->owner_seller_id !== (int) $client->parent_id) {
            throw new InvalidArgumentException(__('clients.account_owner_mismatch'));
        }
    }
}
