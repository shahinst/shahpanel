<?php

namespace Tests\Feature;

use App\Enums\InvoiceType;
use App\Models\Account;
use App\Models\Invoice;
use App\Services\ActivityLogService;
use App\Services\ClientAccountDetailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class VolumeRepairAndSecretsTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    private const GIB = 1073741824;

    /**
     * Account renewed in the given mode, with its invoice line naming the new
     * total -- which is what every renewal writes, add-volume included -- and
     * a usage reading from just before the renewal that never went down.
     */
    private function renewed(string $mode): Account
    {
        $agent = $this->makeAgent();
        $account = $this->makeAccount($agent, $this->makeServer('pasarguard'), [
            'purchased_data_gb' => '70.00',
            'data_limit_bytes' => 70 * self::GIB,
            'data_used_bytes' => 52 * self::GIB,
        ]);

        $at = now()->subDays(3);
        $invoice = Invoice::query()->create([
            'invoice_number' => 'T-'.Str::upper(Str::random(8)),
            'buyer_user_id' => $agent->id,
            'seller_user_id' => $agent->id,
            'agent_user_id' => $agent->id,
            'account_id' => $account->id,
            'type' => InvoiceType::Renewal,
            'subtotal' => '160000.00',
            'total' => '160000.00',
            'currency' => 'IRT',
            'status' => 'paid',
            'issued_at' => $at,
            'created_at' => $at,
        ]);
        DB::table('invoice_items')->insert([
            'invoice_id' => $invoice->id,
            'description' => 'تمدید اکانت — v2ray (70 GB)',
            'quantity' => 1,
            'unit_price' => '160000.00',
            'total' => '160000.00',
        ]);
        DB::table('account_usage_logs')->insert([
            'account_id' => $account->id, 'rx_delta_bytes' => 0, 'tx_delta_bytes' => 0,
            'rx_snapshot' => 50 * self::GIB, 'tx_snapshot' => 0, 'recorded_at' => $at->copy()->subMinutes(5),
        ]);

        app(ActivityLogService::class)->log($agent, 'account.renewed', $account, $mode === 'add_volume'
            ? ['renewal_mode' => 'add_volume', 'renewal_gb' => 20, 'purchased_before_gb' => 50]
            : ['renewal_mode' => $mode, 'renewal_gb' => 70]);

        return $account;
    }

    public function test_a_top_up_renewal_is_not_mistaken_for_an_upgrade(): void
    {
        // The case that went wrong on a live panel: a 50 -> 70 GB top-up kept
        // its usage, as it should, and was credited that usage a second time.
        $account = $this->renewed('add_volume');

        $this->artisan('panel:repair-volume-renewals', ['--apply' => true])->assertSuccessful();

        $this->assertSame('70.00', (string) $account->fresh()->purchased_data_gb);
    }

    public function test_a_real_upgrade_is_still_found(): void
    {
        $account = $this->renewed('upgrade_volume');

        $this->artisan('panel:repair-volume-renewals')
            ->expectsOutputToContain('#'.$account->id)
            ->assertSuccessful();
    }

    public function test_a_masked_router_secret_never_replaces_the_panels_password(): void
    {
        $this->assertTrue(ClientAccountDetailService::isMaskedSecret('********'));
        $this->assertTrue(ClientAccountDetailService::isMaskedSecret('***'));
        $this->assertFalse(ClientAccountDetailService::isMaskedSecret(''));
        $this->assertFalse(ClientAccountDetailService::isMaskedSecret('a*b'));
        $this->assertFalse(ClientAccountDetailService::isMaskedSecret('481930'));
    }
}
