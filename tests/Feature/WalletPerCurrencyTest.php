<?php

namespace Tests\Feature;

use App\Enums\MoneyCurrency;
use App\Services\UserCurrencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class WalletPerCurrencyTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_a_user_holds_one_wallet_per_currency_not_one_in_all(): void
    {
        $uniques = collect(Schema::getIndexes('wallets'))
            ->filter(fn (array $index): bool => $index['unique'] && ! $index['primary'])
            ->pluck('columns')
            ->all();

        $this->assertSame([['user_id', 'currency']], $uniques);
    }

    public function test_the_charge_page_opens_for_an_agent_with_a_second_currency(): void
    {
        $agent = $this->makeAgent();
        app(UserCurrencyService::class)->syncAgentCurrencies($agent, [MoneyCurrency::IRT->value, MoneyCurrency::USD->value]);

        $this->assertSame(2, $agent->wallets()->count());

        $this->actingAs($this->makeAdmin())
            ->get(route('admin.payment-requests.charge'))
            ->assertOk()
            ->assertSee($agent->full_name);
    }
}
