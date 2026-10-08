<?php

namespace Tests\Feature;

use App\Services\SubscriptionFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\ShahBot\Http\Controllers\OpenAppController;
use Modules\ShahBot\ShahBotServiceProvider;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class ShahBotOpenAppTest extends TestCase
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
        Http::fake();
    }

    public function test_a_signed_link_opens_the_app_with_this_account_subscription_only(): void
    {
        [$package] = $this->makePackage();
        $account = $this->makeAccount($this->makeAgent(), $this->makeServer(), ['package_id' => $package->id]);
        $this->app->instance(SubscriptionFeedService::class, \Mockery::mock(SubscriptionFeedService::class, fn ($m) => $m->shouldReceive('urlFor')->andReturn('https://sub.example/s/abc?x=1')));

        $link = OpenAppController::link($account, 'v2rayng');
        $this->get($link)->assertOk()
            ->assertSee('v2rayng://install-config?url='.rawurlencode('https://sub.example/s/abc?x=1'), false);

        // Another account id, another app, or no signature: nothing.
        $this->get(str_replace('/open/'.$account->id.'/', '/open/'.($account->id + 1).'/', $link))->assertNotFound();
        $this->get(str_replace('/v2rayng?', '/hiddify?', $link))->assertNotFound();
        $this->get(strtok($link, '?'))->assertNotFound();
    }
}
