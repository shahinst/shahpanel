<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Package;
use App\Models\User;

class PackagePolicy
{
    public function viewAny(User $viewer): bool
    {
        return true;
    }

    public function view(User $viewer, Package $package): bool
    {
        return true;
    }

    public function create(User $viewer): bool
    {
        return $viewer->role === UserRole::Admin;
    }

    public function update(User $viewer, Package $package): bool
    {
        return $viewer->role === UserRole::Admin;
    }

    public function delete(User $viewer, Package $package): bool
    {
        return $viewer->role === UserRole::Admin;
    }
}
