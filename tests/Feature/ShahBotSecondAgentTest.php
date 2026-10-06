<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\ShahBotServiceProvider;
use Modules\ShahBot\Support\BotSettings;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class ShahBotSecondAgentTest extends TestCase
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
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        app(BotSettings::class)->set(['agent_bots_enabled' => '1']);
    }

    private function bot($owner, string $letter): BotInstance
    {
        DB::table('shahbot_bot_access')->insertOrIgnore(['user_id' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $bot = new BotInstance(['owner_user_id' => $owner->id, 'webhook_secret' => str_repeat($letter, 40), 'is_active' => true]);
        $bot->setToken($owner->id.'00:'.str_repeat($letter, 35));
        $bot->save();

        return $bot;
    }

    private function start(BotInstance $bot, int $telegramId): void
    {
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', $bot->webhook_secret)
            ->postJson('/shahbot/webhook/'.$bot->webhook_secret, ['update_id' => $telegramId, 'message' => [
                'message_id' => 1, 'chat' => ['id' => $telegramId, 'type' => 'private'], 'from' => ['id' => $telegramId, 'first_name' => 'Fan'], 'text' => '/start',
            ]])->assertOk();
    }

    public function test_the_second_agent_bot_answers_start_like_the_first(): void
    {
        $first = $this->bot($this->makeAgent(), 'a');
        $second = $this->bot($this->makeAgent(), 'b');

        // The same person tries both bots, then a newcomer tries the second.
        foreach ([[$first, 7001], [$second, 7001], [$second, 7002]] as [$bot, $telegramId]) {
            $this->start($bot, $telegramId);
            $this->assertTrue(BotUser::query()->where('bot_id', $bot->id)->where('telegram_id', $telegramId)->exists(), "bot {$bot->id} / {$telegramId}");
            Http::assertSent(fn (Request $r) => str_contains($r->url(), '/bot'.$bot->token().'/sendMessage') && (string) $r['chat_id'] === (string) $telegramId);
        }
    }

    public function test_a_telegram_user_is_unique_per_bot_not_across_bots(): void
    {
        $uniques = collect(Schema::getIndexes('shahbot_users'))
            ->filter(fn (array $index): bool => $index['unique'] && ! $index['primary'])
            ->pluck('columns')->all();

        $this->assertSame([['bot_id', 'telegram_id']], $uniques);
    }
}
