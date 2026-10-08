<?php

namespace Tests\Feature;

use App\Enums\ServiceType;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class OcservUsageSyncTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    private function account()
    {
        [$package] = $this->makePackage();
        $server = $this->makeServer('ocserv', ['host' => 'oc.example', 'port' => 9443, 'username_enc' => 'shahpanel', 'password_enc' => 'token']);

        return $this->makeAccount($this->makeAgent(), $server, [
            'package_id' => $package->id,
            'service_type' => ServiceType::Ocserv,
            'remote_username' => 'u1',
            'data_used_bytes' => 0,
            'data_limit_bytes' => 10 * 1024 ** 3,
            'expiry_at' => now()->addDays(30),
        ]);
    }

    public function test_usage_follows_the_agent_cumulative_counters(): void
    {
        $account = $this->account();
        Http::fakeSequence('oc.example:9443/api/traffic')
            ->push(['cumulative' => true, 'users' => [['username' => 'u1', 'rx' => 100, 'tx' => 900], ['username' => 'other', 'rx' => 5, 'tx' => 5]]])
            ->push(['cumulative' => true, 'users' => [['username' => 'u1', 'rx' => 300, 'tx' => 2700]]]);

        $sync = app(SyncService::class);
        $sync->syncAccount($account);
        $this->assertSame(1000, (int) $account->fresh()->data_used_bytes);

        // A later run reads the server again and adds only what grew.
        $this->travel(2)->minutes();
        app()->forgetInstance(SyncService::class);
        app(SyncService::class)->syncAccount($account->fresh());
        $this->assertSame(3000, (int) $account->fresh()->data_used_bytes);
    }

    public function test_an_old_agent_reporting_live_sessions_only_is_not_trusted(): void
    {
        $account = $this->account();
        Http::fake(['oc.example:9443/api/traffic' => Http::response(['users' => [['username' => 'u1', 'rx' => 100, 'tx' => 900]]])]);

        app(SyncService::class)->syncAccount($account);
        $this->assertSame(0, (int) $account->fresh()->data_used_bytes);
    }
}
