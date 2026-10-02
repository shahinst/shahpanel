<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Deletes an agent, seller or client from the admin panel.
 *
 * Only a soft delete is ever done. A hard delete would cascade through wallets,
 * transactions, invoices and wallet adjustments and erase the accounting trail,
 * and it would remove the user's accounts from the database while leaving them
 * live on the VPN servers. So a user is deleted only once nothing still hangs
 * off them: no accounts, no users under them and no money in their wallets.
 */
class UserDeletionService
{
    public function __construct(
        protected ActivityLogService $activityLogService,
        protected ApiTokenService $apiTokenService,
    ) {}

    public function delete(User $actor, User $target): void
    {
        if ($actor->role !== UserRole::Admin) {
            throw new InvalidArgumentException(__('users.delete_admin_only'));
        }

        if ((int) $actor->id === (int) $target->id) {
            throw new InvalidArgumentException(__('users.delete_self'));
        }

        if (! in_array($target->role, [UserRole::Agent, UserRole::Seller, UserRole::Client], true)) {
            throw new InvalidArgumentException(__('users.delete_role_not_allowed'));
        }

        $this->assertDeletable($target);

        DB::transaction(function () use ($actor, $target): void {
            $target = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            // Re-checked under the lock: an account bought or a deposit made
            // between the first check and now must still stop the delete.
            $this->assertDeletable($target);

            $this->apiTokenService->revokeAllForUser($target, 'user_deleted');

            $originalUsername = (string) $target->username;
            $originalEmail = (string) $target->email;
            $suffix = '#deleted-'.$target->id;

            // username and email are unique even across soft-deleted rows; freeing
            // them lets the same name be used for a new user later.
            $target->forceFill([
                'status' => UserStatus::Suspended,
                'remember_token' => null,
                'username' => mb_substr($originalUsername, 0, 255 - strlen($suffix)).$suffix,
                'email' => $originalEmail !== '' ? mb_substr($originalEmail, 0, 255 - strlen($suffix)).$suffix : $originalEmail,
            ])->save();

            $target->delete();

            $this->activityLogService->log($actor, 'user.deleted', $target, [
                'role' => $target->role->value,
                'username' => $originalUsername,
                'email' => $originalEmail,
            ]);
        });
    }

    /**
     * Why the user cannot be deleted right now, or null when they can.
     */
    public function blockingReason(User $target): ?string
    {
        $accounts = $target->ownedAccountsAsSeller()->count()
            + $target->ownedAccountsAsAgent()->where('owner_seller_id', '!=', $target->id)->count()
            + $target->clientAccounts()->count();

        if ($accounts > 0) {
            return __('users.delete_has_accounts', ['count' => $accounts]);
        }

        $children = $target->children()->count();

        if ($children > 0) {
            return __('users.delete_has_children', ['count' => $children]);
        }

        $fundedWallet = $target->wallets()
            ->where(fn ($query) => $query->where('balance', '!=', 0)->orWhere('locked_balance', '!=', 0))
            ->exists();

        if ($fundedWallet) {
            return __('users.delete_has_balance');
        }

        return null;
    }

    protected function assertDeletable(User $target): void
    {
        $reason = $this->blockingReason($target);

        if ($reason !== null) {
            throw new InvalidArgumentException($reason);
        }
    }
}
