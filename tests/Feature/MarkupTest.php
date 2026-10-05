<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\UserPackageDurationPrice;
use App\Services\UserPackagePricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Markup\MarkupServiceProvider;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class MarkupTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'Modules\\Markup\\')) {
                $file = base_path('modules/markup/src/'.str_replace('\\', '/', substr($class, strlen('Modules\\Markup\\'))).'.php');
                if (is_file($file)) {
                    require_once $file;
                }
            }
        });

        $this->artisan('migrate', ['--path' => 'modules/markup/database/migrations']);
        $this->app->register(MarkupServiceProvider::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    public function test_the_seller_pays_the_agents_price_plus_the_markup_and_follows_it(): void
    {
        $agent = $this->makeAgent();
        $seller = $this->makeSeller($agent);
        [, $duration] = $this->makePackage();
        $agentRow = UserPackageDurationPrice::query()->create(['user_id' => $agent->id, 'package_duration_id' => $duration->id, 'wholesale_price' => '150000.00']);
        UserPackageDurationPrice::query()->create(['user_id' => $seller->id, 'package_duration_id' => $duration->id, 'wholesale_price' => '140000.00']);
        $pricing = app(UserPackagePricingService::class);

        // No markup yet: the hand-set seller price stays, as before.
        $this->assertSame('140000.00', $pricing->wholesalePriceFor($seller, $duration));

        $this->actingAs($agent)->post(route('agent.markup.update'), ['default' => '7'])->assertRedirect();
        $this->assertSame('160500.00', $pricing->wholesalePriceFor($seller, $duration));

        // The admin changes the agent's price: the seller follows at once.
        $agentRow->update(['wholesale_price' => '200000.00']);
        $this->assertSame('214000.00', $pricing->wholesalePriceFor($seller, $duration));

        // A seller-specific percent wins over the default.
        $this->actingAs($agent)->post(route('agent.markup.update'), ['default' => '7', 'sellers' => [$seller->id => '5']])->assertRedirect();
        $this->assertSame('210000.00', $pricing->wholesalePriceFor($seller, $duration));
    }

    public function test_the_ceiling_holds_and_strangers_are_ignored(): void
    {
        Setting::setValue('markup_max_percent', '10');
        $agent = $this->makeAgent();
        $stranger = $this->makeSeller($this->makeAgent());
        [, $duration] = $this->makePackage();
        UserPackageDurationPrice::query()->create(['user_id' => $agent->id, 'package_duration_id' => $duration->id, 'wholesale_price' => '100000.00']);

        $this->actingAs($agent)->post(route('agent.markup.update'), ['default' => '25'])->assertSessionHasErrors('default');
        $this->actingAs($agent)->post(route('agent.markup.update'), ['default' => '5', 'sellers' => [$stranger->id => '9']])->assertRedirect();
        $this->assertFalse(DB::table('agent_markups')->where('seller_user_id', $stranger->id)->exists());

        // A ceiling lowered later caps markups saved before it.
        $seller = $this->makeSeller($agent);
        DB::table('agent_markups')->where('agent_user_id', $agent->id)->update(['percent' => 9]);
        Setting::setValue('markup_max_percent', '4');
        $this->assertSame('104000.00', app(UserPackagePricingService::class)->wholesalePriceFor($seller, $duration));
    }

    public function test_pages_render_for_their_roles(): void
    {
        $agent = $this->makeAgent();
        $this->makeSeller($agent);

        $this->actingAs($agent)->get(route('agent.markup.index'))->assertOk();
        $this->actingAs($this->makeAdmin())->get(route('admin.markup.index'))->assertOk();
        // The role middleware sends a seller back to their own panel.
        $this->actingAs($this->makeSeller($agent))->get(route('agent.markup.index'))->assertRedirect();
    }
}
