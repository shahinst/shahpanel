<?php

namespace Tests\Feature;

use App\Models\AccountUsageLog;
use App\Services\SanaeiService;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class StabilityTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_unchanged_usage_is_not_logged_again(): void
    {
        $agent = $this->makeAgent();
        $account = $this->makeAccount($agent, $this->makeServer('sanaei'), [
            'service_type' => \App\Enums\ServiceType::SanaeiVless,
        ]);

        $sanaei = Mockery::mock(SanaeiService::class);
        $sanaei->shouldReceive('getAggregatedClientTraffics')->andReturn(['up' => 100, 'down' => 900]);
        $sanaei->shouldReceive('normalizeTrafficSnapshot')->andReturn([
            'up' => 100, 'down' => 900, 'used_bytes' => 1000, 'limit_bytes' => null, 'remaining_bytes' => null,
            'rx_bytes' => 900, 'tx_bytes' => 100, 'rx_snapshot' => 900, 'tx_snapshot' => 100,
        ]);
        $this->app->instance(SanaeiService::class, $sanaei);

        $sync = $this->app->make(SyncService::class);
        $sync->syncAccount($account);
        $sync->syncAccount($account->fresh());
        $sync->syncAccount($account->fresh());

        $this->assertSame(1, AccountUsageLog::query()->where('account_id', $account->id)->count());
        $this->assertSame(1000, (int) $account->fresh()->data_used_bytes);
    }

    public function test_prune_keeps_account_history_and_recent_rows(): void
    {
        $agent = $this->makeAgent();
        $old = now()->subDays(400);

        DB::table('activity_logs')->insert([
            ['user_id' => $agent->id, 'action' => 'POST x', 'entity_type' => null, 'entity_id' => null, 'created_at' => $old],
            ['user_id' => $agent->id, 'action' => 'account.refunded', 'entity_type' => 'account', 'entity_id' => 1, 'created_at' => $old],
            ['user_id' => $agent->id, 'action' => 'POST y', 'entity_type' => null, 'entity_id' => null, 'created_at' => now()],
        ]);

        Artisan::call('panel:prune-logs');

        $this->assertSame(['account.refunded', 'POST y'], DB::table('activity_logs')->orderBy('id')->pluck('action')->all());
    }
}
