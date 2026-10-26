<?php

namespace Modules\Metal3d;

use App\Support\Themes\ThemeRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The Metal3D theme: a dark, layered look for every panel page, registered
 * with the panel's theme registry. The theme is a stylesheet and a script
 * laid over the panel's own markup; it changes nothing but appearance.
 *
 * The assets are served by a route straight from this module, so no build
 * step and no copy into public/ is needed on a customer install; the file's
 * modification time is in the URL so a module update is never served stale.
 */
class Metal3dServiceProvider extends ServiceProvider
{
    public const SLUG = 'metal3d';

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'metal3d');

        // Static files; no session, no CSRF, cached hard by the browser.
        Route::middleware('throttle:600,1')->group(__DIR__.'/../routes/assets.php');

        $this->app->booted(function (): void {
            $this->app->make(ThemeRegistry::class)->register([
                'slug' => self::SLUG,
                'name' => 'Metal3D',
                'description' => 'metal3d::theme.description',
                'author' => 'ShahPanel',
                'version' => (string) (json_decode((string) @file_get_contents(__DIR__.'/../module.json'), true)['version'] ?? '1.0.0'),
                'preview' => 'linear-gradient(135deg, #1e293b 0%, #0f172a 45%, #312e81 100%)',
                'styles' => [self::assetUrl('css')],
                'scripts' => [self::assetUrl('js')],
                'body_class' => 'theme-metal3d',
                'theme_color' => '#0f172a',
            ]);
        });
    }

    public static function assetPath(string $file): string
    {
        return dirname(__DIR__).'/assets/'.$file;
    }

    /**
     * @param  'css'|'js'  $kind
     */
    public static function assetUrl(string $kind): string
    {
        $path = self::assetPath('metal3d.'.$kind);
        $version = is_file($path) ? (string) filemtime($path) : '0';

        // Built from the path, not the route name: this runs while the app is
        // still booting, before route-name lookups are refreshed.
        return asset('themes/metal3d/'.$kind).'?v='.$version;
    }
}
