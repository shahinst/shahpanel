<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\PackageCategory;
use App\Models\User;

class PackageCategoryPolicy
{
    public function viewAny(User $viewer): bool
    {
        return $viewer->role === UserRole::Admin;
    }

    public function view(User $viewer, PackageCategory $category): bool
    {
        return $viewer->role === UserRole::Admin;
    }

    public function create(User $viewer): bool
    {
        return $viewer->role === UserRole::Admin;
    }

    public function update(User $viewer, PackageCategory $category): bool
    {
        return $viewer->role === UserRole::Admin;
    }

    public function delete(User $viewer, PackageCategory $category): bool
    {
        return $viewer->role === UserRole::Admin;
    }
}
