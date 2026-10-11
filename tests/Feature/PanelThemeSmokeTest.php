<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\Themes\ThemeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

/**
 * A theme may only change how pages look. This opens every parameter-less
 * GET page of each panel with the Metal3D theme chosen and makes sure none of
 * them errors; a theme bug must never take a page down.
 */
class PanelThemeSmokeTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_every_plain_panel_page_renders_with_a_theme_chosen(): void
    {
        Setting::setValue(ThemeRegistry::SETTING, 'metal3d');
        app(ThemeRegistry::class)->register(['slug' => 'metal3d', 'name' => 'Metal3D', 'styles' => ['/themes/metal3d/css'], 'scripts' => ['/themes/metal3d/js']]);

        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $seller = $this->makeSeller($agent);
        $failures = [];

        foreach (['admin' => $admin, 'agent' => $agent, 'seller' => $seller] as $prefix => $user) {
            foreach (Route::getRoutes() as $route) {
                $name = (string) $route->getName();

                if (! str_starts_with($name, $prefix.'.') || ! in_array('GET', $route->methods(), true) || str_contains($route->uri(), '{')) {
                    continue;
                }

                // Downloads, exports and redirects are not pages.
                if (preg_match('/download|export|csv|logout|impersonat|progress|stream|qr|ovpn/i', $name) === 1) {
                    continue;
                }

                $response = $this->actingAs($user)->get(route($name));

                if ($response->getStatusCode() >= 500) {
                    $failures[] = $name.' => '.$response->getStatusCode();
                }

                if ($response->getStatusCode() === 200 && str_contains($response->getContent(), '<body')) {
                    $this->assertStringContainsString('theme-metal3d', $response->getContent(), $name.' did not get the theme class');
                }
            }
        }

        $this->assertSame([], $failures);
    }
}
