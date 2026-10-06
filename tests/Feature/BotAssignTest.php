<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\ShahBot\Models\AccountAssignment;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\ShahBotServiceProvider;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class BotAssignTest extends TestCase
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
    }

    private function bot($owner): BotInstance
    {
        // A seller only has the bot while their agent has it too.
        foreach (array_filter([$owner->id, $owner->parent_id]) as $id) {
            DB::table('shahbot_bot_access')->insertOrIgnore(['user_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }

        $bot = BotInstance::query()->create(['owner_user_id' => $owner->id, 'webhook_secret' => str_repeat('s', 40).$owner->id, 'is_active' => true]);
        $bot->setToken('1:tok'.$owner->id);
        $bot->save();

        return $bot;
    }

    public function test_a_seller_gives_an_account_takes_it_back_and_gives_it_to_the_right_member(): void
    {
        $seller = $this->makeSeller();
        $bot = $this->bot($seller);
        $account = $this->makeAccount($seller, $this->makeServer(), ['display_label' => 'Box-1']);
        $wrong = BotUser::query()->create(['bot_id' => $bot->id, 'telegram_id' => 9001, 'first_name' => 'Wrong', 'username' => 'wrongone']);
        $right = BotUser::query()->create(['bot_id' => $bot->id, 'telegram_id' => 9002, 'first_name' => 'Right']);

        $this->actingAs($seller)->get(route('seller.shahbot.assign'))->assertOk()->assertSee($account->remote_username)->assertSee('9001');

        $this->actingAs($seller)->post(route('seller.shahbot.assign.store', $account), ['member' => '@wrongone'])->assertSessionHas('success');
        $wrong->refresh();
        $this->assertNotNull($wrong->client_user_id);
        $this->assertSame($wrong->client_user_id, $account->fresh()->client_user_id);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'sendMessage') && (string) $r['chat_id'] === '9001' && str_contains((string) $r['text'], 'Box-1'));

        // Giving it again while it is held is refused.
        $this->actingAs($seller)->post(route('seller.shahbot.assign.store', $account), ['member' => '9002'])->assertSessionHas('error');

        $given = AccountAssignment::query()->active()->firstOrFail();
        $this->actingAs($seller)->delete(route('seller.shahbot.assign.destroy', $given))->assertSessionHas('success');
        $this->assertNull($account->fresh()->client_user_id);
        $this->assertNotNull($given->fresh()->revoked_at);

        $this->actingAs($seller)->post(route('seller.shahbot.assign.store', $account), ['member' => '9002'])->assertSessionHas('success');
        $this->assertSame($right->fresh()->client_user_id, $account->fresh()->client_user_id);
    }

    public function test_nobody_gives_or_takes_back_someone_elses_account(): void
    {
        $seller = $this->makeSeller();
        $bot = $this->bot($seller);
        $other = $this->makeSeller();
        $this->bot($other);
        $foreign = $this->makeAccount($other, $this->makeServer());
        BotUser::query()->create(['bot_id' => $bot->id, 'telegram_id' => 9100, 'first_name' => 'M']);

        $this->actingAs($seller)->post(route('seller.shahbot.assign.store', $foreign), ['member' => '9100'])->assertSessionHas('error');
        $this->assertNull($foreign->fresh()->client_user_id);

        // A member of another bot cannot be named either.
        $mine = $this->makeAccount($seller, $this->makeServer());
        BotUser::query()->create(['bot_id' => $bot->id + 999, 'telegram_id' => 9200, 'first_name' => 'X']);
        $this->actingAs($seller)->post(route('seller.shahbot.assign.store', $mine), ['member' => '9200'])->assertSessionHas('error');

        $this->actingAs($seller)->post(route('seller.shahbot.assign.store', $mine), ['member' => '9100'])->assertSessionHas('success');
        $given = AccountAssignment::query()->active()->firstOrFail();
        $this->actingAs($other)->delete(route('seller.shahbot.assign.destroy', $given))->assertNotFound();
        $this->assertNull($given->fresh()->revoked_at);
    }
}
