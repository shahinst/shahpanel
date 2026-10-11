<?php

namespace App\Support\Themes;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

/**
 * The panel's look, chosen once by the admin for every page and every role.
 *
 * A theme is a set of stylesheets and scripts layered on top of the panel's
 * own markup: it never replaces a view, so a theme bug can only change how a
 * page looks, never what it does. The built-in "Shahpanel" theme is the panel
 * as shipped. Modules register further themes; when a theme's module is
 * turned off, the panel falls back to the built-in one on its own.
 */
class ThemeRegistry
{
    public const SETTING = 'panel_theme';

    public const DEFAULT = 'shahpanel';

    /** @var array<string, array{slug: string, name: string, description: string, author: string, version: string, preview: string, styles: list<string>, scripts: list<string>, body_class: string}> */
    protected array $themes = [];

    protected ?string $active = null;

    public function __construct()
    {
        $this->register([
            'slug' => self::DEFAULT,
            'name' => 'Shahpanel',
            'description' => 'themes.shahpanel_description',
            'author' => 'ShahPanel',
            'version' => '1.0.0',
            'preview' => 'linear-gradient(135deg, #6366f1 0%, #4338ca 55%, #312e81 100%)',
        ]);
    }

    /**
     * @param  array{slug: string, name: string, description?: string, author?: string, version?: string, preview?: string, styles?: list<string>, scripts?: list<string>, body_class?: string}  $theme
     */
    public function register(array $theme): void
    {
        $slug = (string) $theme['slug'];

        if (preg_match('/^[a-z0-9][a-z0-9_-]{1,40}$/', $slug) !== 1) {
            return;
        }

        $this->themes[$slug] = [
            'slug' => $slug,
            'name' => (string) $theme['name'],
            'description' => (string) ($theme['description'] ?? ''),
            'author' => (string) ($theme['author'] ?? ''),
            'version' => (string) ($theme['version'] ?? ''),
            'preview' => (string) ($theme['preview'] ?? 'linear-gradient(135deg, #64748b, #0f172a)'),
            'styles' => array_values($theme['styles'] ?? []),
            'scripts' => array_values($theme['scripts'] ?? []),
            'body_class' => (string) ($theme['body_class'] ?? 'theme-'.$slug),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->themes;
    }

    public function has(string $slug): bool
    {
        return isset($this->themes[$slug]);
    }

    /**
     * The chosen theme's slug, or the default when the choice is unknown here
     * (module deactivated, setting mistyped, table not yet migrated).
     */
    public function activeSlug(): string
    {
        if ($this->active !== null) {
            return $this->active;
        }

        $slug = self::DEFAULT;

        try {
            if (Schema::hasTable('settings')) {
                $slug = (string) (Setting::getValue(self::SETTING, self::DEFAULT) ?: self::DEFAULT);
            }
        } catch (\Throwable) {
            $slug = self::DEFAULT;
        }

        return $this->active = $this->has($slug) ? $slug : self::DEFAULT;
    }

    /**
     * @return array<string, mixed>
     */
    public function active(): array
    {
        return $this->themes[$this->activeSlug()];
    }

    public function activate(string $slug): void
    {
        if (! $this->has($slug)) {
            throw new \InvalidArgumentException(__('themes.unknown'));
        }

        Setting::setValue(self::SETTING, $slug);
        $this->active = $slug;
    }
}
