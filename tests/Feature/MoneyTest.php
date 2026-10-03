<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Enums\TransactionType;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AccountRefundService;
use App\Services\AccountService;
use App\Services\ClientPortalEconomicsService;
use App\Services\SanaeiService;
use App\Services\SyncService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    protected function balance(User $user): string
    {
        return number_format((float) app(WalletService::class)->getOrCreateWallet($user)->fresh()->balance, 2, '.', '');
    }

    public function test_portal_sale_pays_the_agent_margin_and_can_be_refunded(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $seller = $this->makeSeller($agent);
        $client = $this->makeClient($seller);
        [$package, $duration] = $this->makePackage();
        $account = $this->makeAccount($seller, $this->makeServer(), [
            'client_user_id' => $client->id,
            'package_id' => $package->id,
            'package_duration_id' => $duration->id,
            'expiry_at' => now()->addDays(30),
        ]);

        $wallets = app(WalletService::class);
        $wallets->credit($client, '1000.00', TransactionType::Charge);
        $wallets->credit($seller, '1000.00', TransactionType::Charge);

        app(ClientPortalEconomicsService::class)->settlePurchase($client, $seller, $account, [
            'display_total' => '300.00',
            'wholesale_total' => '200.00',
            'retail_profit' => '100.00',
            'upstream_cost' => '150.00',
        ]);

        $this->assertSame('700.00', $this->balance($client));
        $this->assertSame('1100.00', $this->balance($seller));
        $this->assertSame('50.00', $this->balance($agent));
        $this->assertTrue(Invoice::query()->where('account_id', $account->id)->exists());

        $svc = Mockery::mock(AccountService::class);
        $svc->shouldReceive('deactivateRemoteForRefund')->once();
        $this->app->instance(AccountService::class, $svc);

        $result = $this->app->make(AccountRefundService::class)->refund($account->fresh(), $admin);

        $this->assertFalse($result['without_payment']);
        $this->assertSame(AccountStatus::Disabled, $account->fresh()->status);
        // Nearly the whole period is unused, so nearly everything goes back.
        $this->assertGreaterThan(990, (float) $this->balance($client));
        $this->assertLessThan(1003, (float) $this->balance($seller));
        $this->assertLessThan(1, (float) $this->balance($agent));
    }

    public function test_sync_keeps_usage_when_the_panel_does_not_answer(): void
    {
        $agent = $this->makeAgent();
        $server = $this->makeServer('sanaei');
        $account = $this->makeAccount($agent, $server, [
            'service_type' => ServiceType::SanaeiVless,
            'data_used_bytes' => 5_000_000,
        ]);

        $sanaei = Mockery::mock(SanaeiService::class);
        $sanaei->shouldReceive('getAggregatedClientTraffics')->andReturn(null);
        $this->app->instance(SanaeiService::class, $sanaei);

        try {
            $this->app->make(SyncService::class)->syncAccount($account);
            $this->fail('A silent panel must not count as zero usage.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(5_000_000, (int) $account->fresh()->data_used_bytes);
    }

    public function test_expiry_skips_an_account_renewed_meanwhile(): void
    {
        $agent = $this->makeAgent();
        $account = $this->makeAccount($agent, $this->makeServer(), ['expiry_at' => now()->subMinute()]);
        $stale = $account->replicate();
        $stale->id = $account->id;

        $account->update(['expiry_at' => now()->addDays(30)]);

        $result = $this->app->make(AccountService::class)->expireAccount($stale);

        $this->assertSame(AccountStatus::Active, $result->status);
        $this->assertSame(AccountStatus::Active, $account->fresh()->status);
    }

    public function test_only_adding_volume_carries_the_used_bytes_over(): void
    {
        // add_volume is billed for the added gigabytes and raises the ceiling,
        // so the meter has to keep running. The other modes are billed for a
        // whole volume and replace the ceiling: carrying usage into them sold a
        // customer 20GB and left them 10GB because 10GB was already spent.
        $this->assertTrue(AccountService::renewalPreservesUsage('add_volume'));
        $this->assertFalse(AccountService::renewalPreservesUsage('upgrade_volume'));
        $this->assertFalse(AccountService::renewalPreservesUsage('same'));
    }
}
