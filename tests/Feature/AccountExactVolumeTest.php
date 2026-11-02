<?php

namespace Tests\Feature;

use App\Jobs\RefreshSubscriptionCacheJob;
use App\Services\AccountService;
use App\Services\SanaeiService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class AccountExactVolumeTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_only_the_main_admin_sets_an_exact_volume_without_touching_wallet_or_usage(): void
    {
        $main = $this->makeAdmin();
        $other = $this->makeAdmin();
        $agent = $this->makeAgent();
        [$package] = $this->makePackage();
        $account = $this->makeAccount($agent, $this->makeServer(), [
            'package_id' => $package->id, 'data_limit_bytes' => 50 * 1024 ** 3, 'data_used_bytes' => 3 * 1024 ** 3,
        ]);
        $balance = (string) app(WalletService::class)->getOrCreateWallet($agent)->balance;

        $this->app->instance(AccountService::class, \Mockery::mock(AccountService::class, function ($mock) {
            $mock->shouldReceive('setPurchasedVolumeGb')->once()->withArgs(fn ($a, $gb) => abs($gb - 20.0) < 0.001)->andReturnUsing(function ($a, $gb) {
                $a->forceFill(['data_limit_bytes' => (int) round($gb * 1024 ** 3)])->save();

                return $a->fresh();
            });
            $mock->shouldIgnoreMissing();
        }));

        $form = fn () => ['remote_username' => $account->remote_username, 'status' => $account->status->value, 'data_limit_gb' => '20'];

        // A second admin's value is ignored; the main admin's is applied once.
        $this->actingAs($other)->put(route('admin.accounts.update', $account), $form());
        $this->actingAs($main)->put(route('admin.accounts.update', $account), $form())->assertSessionHasNoErrors();

        $this->assertSame(20 * 1024 ** 3, (int) $account->fresh()->data_limit_bytes);
        $this->assertSame(3 * 1024 ** 3, (int) $account->fresh()->data_used_bytes);
        $this->assertSame($balance, (string) app(WalletService::class)->getOrCreateWallet($agent)->fresh()->balance);
    }

    public function test_a_volume_changed_on_3x_ui_comes_back_with_the_usage_sync(): void
    {
        [$package] = $this->makePackage();
        $account = $this->makeAccount($this->makeAgent(), $this->makeServer('sanaei'), [
            'package_id' => $package->id, 'service_type' => \App\Enums\ServiceType::SanaeiVless,
            'client_email' => 'ali-1', 'data_limit_bytes' => 50 * 1024 ** 3, 'data_used_bytes' => 0, 'expiry_at' => now()->addDays(20),
        ]);
        $this->app->instance(SanaeiService::class, \Mockery::mock(SanaeiService::class, function ($mock) {
            $mock->shouldReceive('getAggregatedClientTraffics')->andReturn(['email' => 'ali-1', 'up' => 100, 'down' => 200, 'total' => 30 * 1024 ** 3]);
            $mock->shouldReceive('normalizeTrafficSnapshot')->passthru();
            $mock->shouldIgnoreMissing();
        })->makePartial());

        app(\App\Services\SyncService::class)->syncAccount($account);

        $this->assertSame(30 * 1024 ** 3, (int) $account->fresh()->data_limit_bytes);
        $this->assertSame(300, (int) $account->fresh()->data_used_bytes);
    }

    public function test_rereading_the_config_takes_the_uuid_3x_ui_has_now(): void
    {
        [$package] = $this->makePackage();
        $account = $this->makeAccount($this->makeAgent(), $this->makeServer('sanaei'), [
            'package_id' => $package->id, 'service_type' => \App\Enums\ServiceType::SanaeiVless,
            'client_email' => 'ali-1', 'sanaei_client_uuid' => '11111111-1111-1111-1111-111111111111',
        ]);
        $this->app->instance(SanaeiService::class, \Mockery::mock(SanaeiService::class, function ($mock) {
            $mock->shouldReceive('findClientByEmail')->andReturn(['client' => ['id' => '22222222-2222-2222-2222-222222222222', 'email' => 'ali-1']]);
            $mock->shouldIgnoreMissing();
        }));

        RefreshSubscriptionCacheJob::runNowFor($account);

        $this->assertSame('22222222-2222-2222-2222-222222222222', $account->fresh()->sanaei_client_uuid);
    }
}
