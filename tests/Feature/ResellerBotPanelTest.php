<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\ShahBot\Models\BotInstance;
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
}
