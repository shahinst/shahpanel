<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\ShahBot\Services\WebhookService;
use Modules\ShahBot\ShahBotServiceProvider;
use Modules\ShahBot\Support\BotSettings;
use Tests\TestCase;

class ShahBotConnectTest extends TestCase
{
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

    public function test_a_timed_out_webhook_is_recognised(): void
    {
        $this->assertTrue(WebhookService::webhookUnreachable(['last_error_message' => 'Connection timed out']));
        $this->assertFalse(WebhookService::webhookUnreachable(['last_error_message' => 'Wrong response from the webhook: 500']));
        $this->assertFalse(WebhookService::webhookUnreachable([]));
    }
}
