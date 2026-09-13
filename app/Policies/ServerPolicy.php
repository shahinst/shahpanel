<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Server;
use App\Models\User;

class ServerPolicy
{
    public function viewAny(User $viewer): bool
    {
        return $viewer->role === UserRole::Admin;
    }

    public function view(User $viewer, Server $server): bool
    {
        return $viewer->role === UserRole::Admin;
    }

    public function create(User $viewer): bool
    {
        return $viewer->role === UserRole::Admin;
    }

    public function update(User $viewer, Server $server): bool
    {
        return $viewer->role === UserRole::Admin;
    }

    public function delete(User $viewer, Server $server): bool
    {
        return $viewer->role === UserRole::Admin;
    }
}
