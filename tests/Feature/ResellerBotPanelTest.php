<?php

namespace Tests\Feature;

use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Models\BotPayment;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\ShahBotServiceProvider;
use Modules\ShahBot\Support\StartImage;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class ResellerBotPanelTest extends TestCase
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
        DB::table('shahbot_bot_access')->insert(['user_id' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);

        return BotInstance::query()->create(['owner_user_id' => $owner->id, 'webhook_secret' => str_repeat('s', 40).$owner->id, 'is_active' => true]);
    }

    public function test_only_a_real_png_or_jpeg_is_kept_and_anything_else_is_refused(): void
    {
        $agent = $this->makeAgent();
        $bot = $this->bot($agent);

        $fake = UploadedFile::fake()->createWithContent('photo.png', "<?php system(\$_GET['c']); ?>");
        $this->actingAs($agent)->post(route('agent.shahbot.my-bot.update'), ['start_image' => $fake])->assertSessionHas('error');
        $this->assertNull(StartImage::path($bot));

        $png = UploadedFile::fake()->image('ok.png', 40, 40);
        $this->actingAs($agent)->post(route('agent.shahbot.my-bot.update'), ['start_image' => $png])->assertSessionHas('success');
        $this->assertNotNull(StartImage::path($bot));
        // What is kept is a re-encode, never the uploaded bytes.
        $this->assertStringStartsWith("\xFF\xD8\xFF", (string) file_get_contents(StartImage::path($bot)));

        StartImage::clear($bot);
    }

    public function test_an_agent_sees_their_sellers_customers_and_nobody_elses(): void
    {
        $agent = $this->makeAgent();
        $seller = $this->makeSeller($agent);
        $stranger = $this->makeSeller($this->makeAgent());

        foreach ([[$agent, 701], [$seller, 702], [$stranger, 703]] as [$owner, $tg]) {
            BotUser::query()->create(['bot_id' => $this->bot($owner)->id, 'telegram_id' => $tg, 'first_name' => 'u'.$tg]);
        }

        $page = $this->actingAs($agent)->get(route('agent.shahbot.customers'))->assertOk();
        $page->assertSee('701')->assertSee('702')->assertDontSee('703');

        // A filter for someone else's seller cannot widen the list.
        $this->actingAs($agent)->get(route('agent.shahbot.customers', ['owner' => $stranger->id]))
            ->assertOk()->assertDontSee('703');

        $this->actingAs($seller)->get(route('seller.shahbot.customers'))->assertOk()->assertSee('702')->assertDontSee('701');
        $this->assertSame(UserRole::Seller, $seller->role);
    }

    public function test_receipts_are_shown_to_their_owner_and_approved_only_by_them(): void
    {
        $agent = $this->makeAgent();
        $seller = $this->makeSeller($agent);
        $user = BotUser::query()->create(['bot_id' => $this->bot($seller)->id, 'telegram_id' => 811, 'first_name' => 'buyer']);
        $payment = BotPayment::query()->create(['bot_user_id' => $user->id, 'amount' => '12345.00', 'method' => 'card', 'status' => BotPayment::PENDING]);
        app(WalletService::class)->credit($seller, '50000.00', TransactionType::Charge);

        $this->actingAs($seller)->get(route('seller.shahbot.payments'))->assertOk()->assertSee('buyer');

        // The agent sees their seller's receipt but may not spend the seller's wallet.
        $this->actingAs($agent)->get(route('agent.shahbot.payments'))->assertOk()->assertSee('buyer');
        $this->actingAs($agent)->post(route('agent.shahbot.payments.approve', $payment))->assertForbidden();

        // Someone outside the tree cannot even fetch the receipt.
        $this->actingAs($this->makeAgent())->get(route('agent.shahbot.payments.receipt', $payment))->assertNotFound();

        $this->actingAs($seller)->post(route('seller.shahbot.payments.approve', $payment))->assertRedirect();
        $this->assertSame(BotPayment::APPROVED, $payment->fresh()->status);
    }

    public function test_the_mini_app_posts_the_field_the_server_reads(): void
    {
        $html = view('shahbot::mini-app', ['brand' => 'Shop', 'botUsername' => 'shopbot', 'botId' => 0])->render();

        $this->assertStringContainsString('initData: tg.initData', $html);
        $this->assertStringContainsString('Vazirmatn', $html);
    }

    private function initData(int $telegramId, string $token): string
    {
        $fields = ['auth_date' => (string) time(), 'user' => json_encode(['id' => $telegramId, 'first_name' => 'u'])];
        ksort($fields);
        $check = implode("\n", array_map(fn ($k, $v) => $k.'='.$v, array_keys($fields), $fields));
        $secret = hash_hmac('sha256', $token, 'WebAppData', true);
        $fields['hash'] = hash_hmac('sha256', $check, $secret);

        return http_build_query($fields);
    }

    public function test_the_mini_app_opens_only_the_users_own_service(): void
    {
        // A seller's bot runs only while their agent also holds bot access.
        $agent = $this->makeAgent();
        DB::table('shahbot_bot_access')->insert(['user_id' => $agent->id, 'created_at' => now(), 'updated_at' => now()]);
        $seller = $this->makeSeller($agent);
        $bot = $this->bot($seller);
        $token = '424242:'.str_repeat('t', 35);
        $bot->setToken($token);
        $bot->save();

        $client = $this->makeClient($seller);
        $server = $this->makeServer();
        $mine = $this->makeAccount($seller, $server, ['client_user_id' => $client->id]);
        $other = $this->makeAccount($seller, $server, ['client_user_id' => $this->makeClient($seller)->id]);
        BotUser::query()->create(['bot_id' => $bot->id, 'telegram_id' => 9001, 'first_name' => 'u', 'client_user_id' => $client->id]);

        $url = route('shahbot.app.service', ['bot' => $bot->id]);
        $auth = $this->initData(9001, $token);

        $this->postJson($url, ['initData' => $auth, 'account' => $mine->id])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('id', $mine->id)->assertJsonStructure(['renewals', 'balance']);

        // Another customer's account, even on the same bot, is not there.
        $this->postJson($url, ['initData' => $auth, 'account' => $other->id])->assertNotFound();

        // A forged signature gets nothing.
        $this->postJson($url, ['initData' => $this->initData(9001, 'wrong:token'), 'account' => $mine->id])->assertUnauthorized();
    }
}
