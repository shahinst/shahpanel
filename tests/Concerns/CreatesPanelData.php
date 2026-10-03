<?php

namespace Tests\Concerns;

use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Package;
use App\Models\PackageDuration;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Small builders for the rows most tests need. They write only the columns
 * that have no database default, so a schema change shows up as a failure.
 */
trait CreatesPanelData
{
    protected function makeAdmin(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => UserRole::Admin], $attributes));
    }

    protected function makeAgent(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => UserRole::Agent], $attributes));
    }

    protected function makeSeller(?User $agent = null, array $attributes = []): User
    {
        $agent ??= $this->makeAgent();

        return User::factory()->create(array_merge(['role' => UserRole::Seller, 'parent_id' => $agent->id], $attributes));
    }

    protected function makeClient(User $owner, array $attributes = []): User
    {
        return User::factory()->create(array_merge(['role' => UserRole::Client, 'parent_id' => $owner->id], $attributes));
    }

    protected function makeServer(string $type = 'mikrotik', array $attributes = []): Server
    {
        return Server::query()->forceCreate(array_merge([
            'name' => 'srv-'.Str::lower(Str::random(5)),
            'type' => $type,
            'host' => '127.0.0.1',
            'port' => 1,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @return array{0: Package, 1: PackageDuration}
     */
    protected function makePackage(ServiceType $type = ServiceType::Wireguard, ?float $gb = 100, array $attributes = []): array
    {
        $package = Package::query()->forceCreate(array_merge([
            'name' => 'pkg-'.Str::lower(Str::random(5)),
            'service_type' => $type,
            'data_limit_gb' => $gb,
            'duration_days' => 30,
            'base_price' => 1000,
            'is_active' => true,
        ], $attributes));

        $duration = PackageDuration::query()->forceCreate([
            'package_id' => $package->id,
            'tier' => '1m',
            'price' => 1000,
            'is_enabled' => true,
        ]);

        return [$package, $duration];
    }

    protected function assignPackage(User $user, Package $package): void
    {
        DB::table('user_packages')->insert(['user_id' => $user->id, 'package_id' => $package->id]);
    }

    protected function makeAccount(User $owner, Server $server, array $attributes = []): Account
    {
        return Account::query()->forceCreate(array_merge([
            'owner_seller_id' => $owner->id,
            'owner_agent_id' => $owner->role === UserRole::Seller ? $owner->parent_id : $owner->id,
            'server_id' => $server->id,
            'service_type' => ServiceType::Wireguard,
            'remote_username' => 'u'.Str::lower(Str::random(8)),
            'portal_token' => Str::random(32),
            'status' => AccountStatus::Active,
        ], $attributes));
    }
}
