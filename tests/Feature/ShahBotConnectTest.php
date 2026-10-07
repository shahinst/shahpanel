<?php

namespace Tests\Feature;

use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Services\BotUserService;
use Modules\ShahBot\Services\WebhookService;
use Modules\ShahBot\ShahBotServiceProvider;
use Modules\ShahBot\Support\BotSettings;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class ShahBotConnectTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The module is not active in the test install; boot it by hand.
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
    }

    public function test_connecting_sends_a_test_message_to_the_admins(): void
    {
        app(BotSettings::class)->set(['bot_token' => '123456:'.str_repeat('a', 35), 'admin_chat_ids' => "111\n222", 'mode' => 'webhook']);

        Http::fake([
            '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'shop_bot']]),
            '*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
            '*/sendMessage' => Http::sequence()
                ->push(['ok' => true, 'result' => ['message_id' => 1]])
                ->push(['ok' => false, 'description' => 'Bad Request: chat not found']),
        ]);

        $result = app(WebhookService::class)->connect();

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('/start', $result['message']); // the second admin is told what to do
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && (string) $r['chat_id'] === '111');
    }

    public function test_an_agent_bot_webhook_uses_the_panel_address_not_the_request_host(): void
    {
        config(['app.url' => 'https://panel.example']);
        app(BotSettings::class)->set(['mode' => 'webhook']);
        $agent = $this->makeAgent();
        $bot = BotInstance::query()->create(['owner_user_id' => $agent->id, 'webhook_secret' => str_repeat('b', 40), 'is_active' => true]);
        $bot->setToken('654321:'.str_repeat('c', 35));
        $bot->save();

        // The agent opened the panel on another name the server also answers.
        $this->app['request']->headers->set('HOST', 'other.example');
        $this->app['url']->forceRootUrl(null);

        Http::fake([
            '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'agent_bot']]),
            '*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
            '*' => Http::response(['ok' => true, 'result' => true]),
        ]);

        app(WebhookService::class)->connectBot($bot);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/setWebhook')
            && $r['url'] === 'https://panel.example/shahbot/webhook/'.str_repeat('b', 40)
            && $r['secret_token'] === str_repeat('b', 40));
    }

    public function test_a_test_message_that_reaches_nobody_fails_the_connect(): void
    {
        app(BotSettings::class)->set(['bot_token' => '123456:'.str_repeat('a', 35), 'admin_chat_ids' => '111', 'mode' => 'webhook']);

        Http::fake([
            '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'shop_bot']]),
            '*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
            '*/sendMessage' => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user']),
        ]);

        $result = app(WebhookService::class)->connect();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('blocked', $result['message']);
    }

    public function test_the_bot_wallet_of_an_agent_is_their_panel_wallet(): void
    {
        $agent = $this->makeAgent();
        app(WalletService::class)->getOrCreateWallet($agent)->forceFill(['balance' => '123456.78'])->save();

        $bot = BotInstance::query()->create([
            'owner_user_id' => $agent->id, 'webhook_secret' => str_repeat('s', 40), 'is_active' => true,
        ]);
        $user = BotUser::query()->create(['bot_id' => $bot->id, 'telegram_id' => 555]);
        $users = app(BotUserService::class);

        // The owner writing from an admin chat holds the panel wallet, to the cent.
        $holder = $users->walletHolder($user, true);
        $this->assertSame($agent->id, $holder->id);
        $this->assertSame('123456.78', $users->walletBalance($holder));

        // The same Telegram account as an ordinary customer of that bot does not.
        $this->assertNotSame($agent->id, $users->walletHolder($user, false)->id);

        // A reseller made by an agency request holds their seller wallet.
        $seller = $this->makeSeller($agent);
        $user->forceFill(['reseller_user_id' => $seller->id])->save();
        $this->assertSame($seller->id, $users->walletHolder($user->fresh(), false)->id);
    }

    public function test_a_timed_out_webhook_is_recognised(): void
    {
        $this->assertTrue(WebhookService::webhookUnreachable(['last_error_message' => 'Connection timed out']));
        $this->assertFalse(WebhookService::webhookUnreachable(['last_error_message' => 'Wrong response from the webhook: 500']));
        $this->assertFalse(WebhookService::webhookUnreachable([]));
    }
}
