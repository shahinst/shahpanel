<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Services\WebhookMonitorService;
use Modules\ShahBot\ShahBotServiceProvider;
use Modules\ShahBot\Support\BotSettings;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class ShahBotWebhookMonitorTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    /** What getWebhookInfo answers for the agent's bot. */
    private array $info = [];

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
        config(['app.url' => 'https://panel.example']);
        app(BotSettings::class)->set(['mode' => 'webhook', 'bot_token' => '1:main'.str_repeat('m', 30), 'admin_chat_ids' => '11']);

        // One fake for every call: a later Http::fake would be shadowed.
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/getWebhookInfo')) {
                return Http::response(['ok' => true, 'result' => str_contains($request->url(), '1:main') ? ['url' => app(\Modules\ShahBot\Services\WebhookService::class)->webhookUrl()] : $this->info]);
            }

            return Http::response(['ok' => true, 'result' => []]);
        });
    }

    private function agentBot(): BotInstance
    {
        $agent = $this->makeAgent();
        DB::table('shahbot_bot_access')->insertOrIgnore(['user_id' => $agent->id, 'created_at' => now(), 'updated_at' => now()]);
        $bot = new BotInstance(['owner_user_id' => $agent->id, 'webhook_secret' => str_repeat('w', 40), 'is_active' => true, 'username' => 'agent_bot', 'settings' => ['admin_chat_ids' => '22']]);
        $bot->setToken($agent->id.'00:'.str_repeat('w', 35));
        $bot->save();

        return $bot;
    }

    private function sentTo(int $chatId, string $needle): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage') && (string) $r['chat_id'] === (string) $chatId && str_contains((string) $r['text'], $needle))->count();
    }

    public function test_a_failing_webhook_is_announced_once_then_its_recovery(): void
    {
        $bot = $this->agentBot();
        $url = 'https://panel.example/shahbot/webhook/'.$bot->webhook_secret;
        $this->info = ['url' => $url, 'last_error_date' => time() - 60, 'last_error_message' => 'Wrong response from the webhook: 404 Not Found', 'pending_update_count' => 5];

        $monitor = app(WebhookMonitorService::class);
        $this->assertSame(1, $monitor->run());
        $monitor->run();

        // The agent hears it through their bot, the panel admin through the main bot; once each.
        $this->assertSame(1, $this->sentTo(22, '404 Not Found'));
        $this->assertSame(1, $this->sentTo(11, '404 Not Found'));

        $this->info = ['url' => $url, 'last_error_date' => time() - 3600, 'last_error_message' => 'Wrong response from the webhook: 404 Not Found', 'pending_update_count' => 0];
        $this->assertSame(0, $monitor->run());
        $this->assertSame(1, $this->sentTo(22, '@agent_bot'.' is fine again') + $this->sentTo(22, 'برطرف شد'));
    }

    public function test_a_webhook_on_a_wrong_address_is_put_back_keeping_queued_updates(): void
    {
        $bot = $this->agentBot();
        $this->info = ['url' => 'https://203.0.113.9/shahbot/webhook/'.$bot->webhook_secret, 'pending_update_count' => 3];

        $this->assertSame(0, app(WebhookMonitorService::class)->run());

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/bot'.$bot->token().'/setWebhook')
            && $r['url'] === 'https://panel.example/shahbot/webhook/'.$bot->webhook_secret
            && $r['drop_pending_updates'] === 'false');
        $this->assertSame(1, $this->sentTo(22, 'https://panel.example/shahbot/webhook/'));
    }

    public function test_healthy_bots_stay_quiet(): void
    {
        $bot = $this->agentBot();
        $this->info = ['url' => 'https://panel.example/shahbot/webhook/'.$bot->webhook_secret, 'pending_update_count' => 0];

        $this->assertSame(0, app(WebhookMonitorService::class)->run());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/sendMessage') || str_contains($r->url(), '/setWebhook'));
    }
}
