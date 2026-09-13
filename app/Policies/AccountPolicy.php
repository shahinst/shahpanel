<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\User;
use App\Support\SmsSettings;

class AccountPolicy
{
    public function viewAny(User $viewer): bool
    {
        return in_array($viewer->role, [UserRole::Admin, UserRole::Agent, UserRole::Seller], true);
    }

    public function view(User $viewer, Account $account): bool
    {
        return $this->ownsInHierarchy($viewer, $account);
    }

    public function create(User $viewer): bool
    {
        return in_array($viewer->role, [UserRole::Admin, UserRole::Agent, UserRole::Seller], true);
    }

    public function transferServer(User $viewer, Account $account): bool
    {
        if ($viewer->role === UserRole::Admin) {
            return true;
        }

        if ($viewer->role === UserRole::Agent) {
            return $this->ownsInHierarchy($viewer, $account);
        }

        return false;
    }

    public function update(User $viewer, Account $account): bool
    {
        return $this->ownsInHierarchy($viewer, $account);
    }

    public function refund(User $viewer, Account $account): bool
    {
        if ($account->isRefunded()) {
            return false;
        }

        if ($viewer->role === UserRole::Admin) {
            return true;
        }

        // Sellers may refund their own sales; agents may refund anything in their subtree.
        // The commission ledger (agent margin + admin revenue) is reversed automatically.
        return in_array($viewer->role, [UserRole::Agent, UserRole::Seller], true)
            && $this->ownsInHierarchy($viewer, $account);
    }

    public function reactivateAfterRefund(User $viewer, Account $account): bool
    {
        return $viewer->role === UserRole::Admin && $account->isRefunded();
    }

    public function delete(User $viewer, Account $account): bool
    {
        return $viewer->role === UserRole::Admin;
    }

    public function sendLoginInfo(User $viewer, Account $account): bool
    {
        if (! SmsSettings::isAccountLoginSmsEnabled()) {
            return false;
        }

        if ($account->loginSmsSent() || $account->isRefunded() || ! filled($account->portal_token)) {
            return false;
        }

        if (! in_array($viewer->role, [UserRole::Admin, UserRole::Agent, UserRole::Seller], true)) {
            return false;
        }

        return $this->ownsInHierarchy($viewer, $account);
    }

    protected function ownsInHierarchy(User $viewer, Account $account): bool
    {
        if ($viewer->role === UserRole::Admin) {
            return true;
        }

        $userIds = User::subtreeUserIds($viewer);

        if ($viewer->role === UserRole::Seller) {
            return in_array($account->owner_seller_id, $userIds, true);
        }

        return in_array($account->owner_agent_id, $userIds, true)
            || in_array($account->owner_seller_id, $userIds, true);
    }
}
