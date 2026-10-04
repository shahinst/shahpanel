<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Services\WalletService;
use App\Support\PaymentSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Models\BotPayment;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Services\ResellerCryptoService;
use Modules\ShahBot\Services\ShopService;
use Modules\ShahBot\ShahBotServiceProvider;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

/**
 * The full NowPayments order cycle a reseller's bot runs, end to end against
 * a simulated NowPayments: order -> invoice -> signed callback -> account.
 */
class ResellerPaymentsTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'Modules\\ShahBot\\')) {
                $file = base_path('modules/shahbot/src/'.str_replace('\\', '/', substr($class, strlen('Modules\\ShahBot\\'))).'.php');
                if (is_file($file)) {
                    require_once $file;
                }
            }
        });
        $this->app->register(ShahBotServiceProvider::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    /** @return array{0: BotInstance, 1: BotUser, 2: \App\Models\User, 3: int} */
    protected function shop(): array
    {
        $agent = $this->makeAgent();
        app(WalletService::class)->credit($agent, '500000.00', \App\Enums\TransactionType::Charge, ['description' => 'test']);
        [$package, $duration] = $this->makePackage();
        $this->assignPackage($agent, $package);

        $bot = BotInstance::query()->create([
            'owner_user_id' => $agent->id, 'webhook_secret' => Str::random(40), 'is_active' => true,
            'settings' => [
                'np_api_key_enc' => Crypt::encryptString('KEY123'),
                'np_ipn_secret_enc' => Crypt::encryptString('IPNSECRET'),
            ],
        ]);
        $user = BotUser::query()->create(['bot_id' => $bot->id, 'telegram_id' => 4242, 'first_name' => 'buyer']);
        PaymentSettings::setUsdtTomanRate('100000');

        return [$bot, $user, $agent, (int) $duration->id];
    }

    protected function sign(array $payload, string $secret): string
    {
        $sort = function (array $d) use (&$sort): array {
            ksort($d);
            foreach ($d as $k => $v) {
                if (is_array($v)) {
                    $d[$k] = $sort($v);
                }
            }

            return $d;
        };

        return hash_hmac('sha512', json_encode($sort($payload), JSON_UNESCAPED_SLASHES), $secret);
    }

    public function test_a_paid_crypto_order_builds_the_account_from_the_resellers_wallet(): void
    {
        [$bot, $user, $agent, $durationId] = $this->shop();
        $this->mock(ShopService::class, function ($m) use ($user) {
            $m->shouldReceive('purchase')->once()->andReturnUsing(fn () => \Modules\ShahBot\Models\BotOrder::query()->create([
                'bot_user_id' => $user->id, 'type' => 'buy', 'amount' => '50000.00', 'status' => 'done',
            ]));
        });
        Http::fake([
            'api.nowpayments.io/v1/invoice' => Http::response(['id' => 'inv1', 'invoice_url' => 'https://nowpayments.io/payment/?iid=inv1']),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        $result = app(ResellerCryptoService::class)->invoice($bot, $user, 50000, ['duration' => $durationId, 'name' => 'my vpn']);

        $this->assertSame('1.00', $result['usd']); // 50,000 Toman at 100,000 is 0.50, raised to NowPayments' 1 USD floor
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.nowpayments.io/v1/invoice')
            && $r->header('x-api-key')[0] === 'KEY123'
            && str_contains((string) $r['ipn_callback_url'], '/shahbot/np/'.$bot->id.'/'.$bot->webhook_secret));
        $payment = $result['payment']->fresh();
        $this->assertSame(BotPayment::PENDING, $payment->status);

        $before = (float) $agent->fresh()->wallet->balance;
        $payload = ['payment_id' => 99, 'payment_status' => 'finished', 'order_id' => 'sb-'.$payment->id, 'price_amount' => 0.5];
        $body = json_encode($payload);

        // A forged callback is refused and changes nothing.
        $this->call('POST', route('shahbot.np.ipn', ['bot' => $bot->id, 'secret' => $bot->webhook_secret]), [], [], [],
            ['HTTP_X_NOWPAYMENTS_SIG' => $this->sign($payload, 'wrong'), 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(403);
        $this->assertSame(BotPayment::PENDING, $payment->fresh()->status);

        // The real one approves the payment and buys the order.
        $this->call('POST', route('shahbot.np.ipn', ['bot' => $bot->id, 'secret' => $bot->webhook_secret]), [], [], [],
            ['HTTP_X_NOWPAYMENTS_SIG' => $this->sign($payload, 'IPNSECRET'), 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertSame(BotPayment::APPROVED, $payment->fresh()->status);
        $this->assertEqualsWithDelta($before - 50000, (float) $agent->fresh()->wallet->balance, 0.01);

        // The same callback again does nothing more.
        $this->call('POST', route('shahbot.np.ipn', ['bot' => $bot->id, 'secret' => $bot->webhook_secret]), [], [], [],
            ['HTTP_X_NOWPAYMENTS_SIG' => $this->sign($payload, 'IPNSECRET'), 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertEqualsWithDelta($before - 50000, (float) $agent->fresh()->wallet->balance, 0.01);
    }

    public function test_another_bots_callback_cannot_confirm_a_payment(): void
    {
        [$bot, $user, , $durationId] = $this->shop();
        $payment = BotPayment::query()->create([
            'bot_user_id' => $user->id, 'amount' => '50000.00', 'method' => 'nowpayments',
            'status' => BotPayment::PENDING, 'order' => ['duration' => $durationId],
        ]);
        $other = BotInstance::query()->create([
            'owner_user_id' => $this->makeAgent()->id, 'webhook_secret' => Str::random(40), 'is_active' => true,
            'settings' => ['np_api_key_enc' => Crypt::encryptString('K2'), 'np_ipn_secret_enc' => Crypt::encryptString('S2')],
        ]);
        $payload = ['payment_status' => 'finished', 'order_id' => 'sb-'.$payment->id];

        $this->call('POST', route('shahbot.np.ipn', ['bot' => $other->id, 'secret' => $other->webhook_secret]), [], [], [],
            ['HTTP_X_NOWPAYMENTS_SIG' => $this->sign($payload, 'S2'), 'CONTENT_TYPE' => 'application/json'], json_encode($payload))->assertOk();

        $this->assertSame(BotPayment::PENDING, $payment->fresh()->status);
        // And a wrong secret in the URL is a plain 404.
        $this->post(route('shahbot.np.ipn', ['bot' => $bot->id, 'secret' => str_repeat('x', 40)]))->assertNotFound();
    }

    public function test_the_usdt_rate_is_fetched_and_kept_when_sources_fail(): void
    {
        Http::fake(['api.nobitex.ir/*' => Http::response(['stats' => ['usdt-rls' => ['latest' => '1050000']]])]);
        $this->artisan('panel:sync-usdt-rate')->assertSuccessful();
        $this->assertSame('105000.00', PaymentSettings::usdtTomanRate());

    }

    public function test_a_failed_rate_fetch_keeps_the_previous_rate(): void
    {
        PaymentSettings::setUsdtTomanRate('105000');
        Http::fake(['*' => Http::response('down', 500)]);
        $this->artisan('panel:sync-usdt-rate')->assertFailed();
        $this->assertSame('105000.00', PaymentSettings::usdtTomanRate());
    }
}
