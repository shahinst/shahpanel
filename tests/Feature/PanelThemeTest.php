<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\Themes\ThemeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Metal3d\Metal3dServiceProvider;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class PanelThemeTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    private function loadMetal3d(): void
    {
        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'Modules\\Metal3d\\')) {
                $file = base_path('modules/metal3d/src/'.str_replace('\\', '/', substr($class, strlen('Modules\\Metal3d\\'))).'.php');

                if (is_file($file)) {
                    require_once $file;
                }
            }
        });
        $this->app->register(Metal3dServiceProvider::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        // The provider registers on "booted"; the app is already booted here.
        $this->app->make(ThemeRegistry::class)->register([
            'slug' => 'metal3d', 'name' => 'Metal3D',
            'styles' => [Metal3dServiceProvider::assetUrl('metal3d.css')],
            'scripts' => [Metal3dServiceProvider::assetUrl('metal3d.js')],
        ]);
    }

    public function test_the_panel_ships_with_the_shahpanel_theme_and_the_admin_can_switch(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get(route('admin.themes.index'))->assertOk()->assertSee('Shahpanel');
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('theme-shahpanel', false);

        // A theme nobody registered cannot be chosen.
        $this->actingAs($admin)->post(route('admin.themes.update'), ['theme' => 'metal3d'])->assertSessionHasErrors('theme');

        $this->loadMetal3d();
        $this->actingAs($admin)->post(route('admin.themes.update'), ['theme' => 'metal3d'])->assertSessionHasNoErrors();
        $this->assertSame('metal3d', Setting::getValue(ThemeRegistry::SETTING));

        $page = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $page->assertSee('theme-metal3d', false)->assertSee('themes/metal3d/metal3d.css', false)->assertSee('themes/metal3d/metal3d.js', false);

        $this->get('/themes/metal3d/metal3d.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=UTF-8');
        $this->get('/themes/metal3d/other.css')->assertNotFound();
    }

    public function test_a_chosen_theme_whose_module_is_off_falls_back_to_the_default(): void
    {
        Setting::setValue(ThemeRegistry::SETTING, 'metal3d');

        $this->actingAs($this->makeAdmin())->get(route('admin.dashboard'))->assertOk()->assertSee('theme-shahpanel', false)->assertDontSee('theme-metal3d');
    }

    public function test_the_account_page_shows_the_account_card(): void
    {
        $agent = $this->makeAgent();
        [$package] = $this->makePackage();
        $account = $this->makeAccount($agent, $this->makeServer(), ['package_id' => $package->id, 'display_label' => 'Office phone', 'data_limit_bytes' => 10 * 1024 ** 3, 'data_used_bytes' => 4 * 1024 ** 3, 'expiry_at' => now()->addDays(12)]);

        $this->actingAs($agent)->get(route('agent.accounts.show', $account))->assertOk()
            ->assertSee('id="account-card"', false)
            ->assertSee('Office phone')
            ->assertSee(__('accounts.card_copy'))
            ->assertSee('--p: 40', false);
    }
}
