<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $viewer): bool
    {
        return in_array($viewer->role, [UserRole::Admin, UserRole::Agent, UserRole::Seller], true);
    }

    public function view(User $viewer, User $target): bool
    {
        if ($viewer->id === $target->id) {
            return true;
        }

        if ($target->role === UserRole::Client) {
            return app(\App\Services\EndUserService::class)->canViewerManageClient($viewer, $target);
        }

        return $this->inHierarchy($viewer, $target);
    }

    public function create(User $viewer): bool
    {
        return in_array($viewer->role, [UserRole::Admin, UserRole::Agent, UserRole::Seller], true);
    }

    public function update(User $viewer, User $target): bool
    {
        if ($target->role === UserRole::Client) {
            return app(\App\Services\EndUserService::class)->canViewerManageClient($viewer, $target);
        }

        return $this->inHierarchy($viewer, $target);
    }

    public function delete(User $viewer, User $target): bool
    {
        if ($viewer->id === $target->id) {
            return false;
        }

        // Deleting agents, sellers and clients is for the admin only.
        if (in_array($target->role, [UserRole::Agent, UserRole::Seller, UserRole::Client], true)) {
            return $viewer->role === UserRole::Admin;
        }

        return $this->inHierarchy($viewer, $target);
    }

    public function impersonate(User $viewer, User $target): bool
    {
        return app(\App\Services\ImpersonationService::class)->canImpersonate($viewer, $target);
    }

    public function disableTwoFactor(User $viewer, User $target): bool
    {
        if ($viewer->role !== UserRole::Admin) {
            return false;
        }

        // Another admin's second factor is the panel owner's to remove. The route
        // sits in the agents section, so an admin given only that section could
        // otherwise strip the owner's 2FA.
        if ($target->role === UserRole::Admin) {
            return is_super_admin($viewer);
        }

        return in_array($target->role, [UserRole::Agent, UserRole::Seller], true);
    }

    protected function inHierarchy(User $viewer, User $target): bool
    {
        if ($viewer->role === UserRole::Admin) {
            return in_array($target->role, [UserRole::Agent, UserRole::Seller], true);
        }

        if (! $viewer->role->canManage($target->role)) {
            return false;
        }

        return in_array($target->id, User::subtreeUserIds($viewer), true);
    }
}
