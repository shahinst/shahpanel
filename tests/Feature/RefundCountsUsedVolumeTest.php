<?php

namespace Tests\Feature;

use App\Enums\InvoiceType;
use App\Models\Account;
use App\Models\Invoice;
use App\Services\AccountRefundService;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class RefundCountsUsedVolumeTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    private function boughtAccount(int $usedBytes): Account
    {
        $agent = $this->makeAgent();
        [$package, $duration] = $this->makePackage();
        $account = $this->makeAccount($agent, $this->makeServer(), [
            'package_id' => $package->id,
            'package_duration_id' => $duration->id,
            'data_limit_bytes' => 1024 ** 3,
            'data_used_bytes' => $usedBytes,
            'expiry_at' => now()->addDays(30),
        ]);
        Invoice::query()->forceCreate([
            'invoice_number' => 'T-'.Str::random(8),
            'buyer_user_id' => $agent->id,
            'seller_user_id' => $agent->id,
            'agent_user_id' => $agent->id,
            'account_id' => $account->id,
            'type' => InvoiceType::NewAccount->value,
            'subtotal' => 6000,
            'total' => 6000,
            'status' => 'paid',
            'issued_at' => now(),
        ]);

        $svc = Mockery::mock(AccountService::class)->shouldIgnoreMissing();
        $this->app->instance(AccountService::class, $svc);

        return $account;
    }

    public function test_an_account_whose_volume_is_spent_gets_nothing_back_despite_days_left(): void
    {
        $account = $this->boughtAccount(1024 ** 3);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(__('accounts.nothing_to_refund'));

        $this->app->make(AccountRefundService::class)->refund($account, $this->makeAdmin());
    }

    public function test_half_the_volume_used_returns_about_half(): void
    {
        $account = $this->boughtAccount(512 * 1024 ** 2);

        $result = $this->app->make(AccountRefundService::class)->refund($account, $this->makeAdmin());

        $this->assertEqualsWithDelta(3000, (float) $result['refund_amount'], 5);
    }
}
