<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PortalPaths
{
    /**
     * @var array<string, string>|null
     */
    protected static ?array $resolved = null;

    /**
     * URL segments that must not be used as custom portal paths.
     *
     * @var list<string>
     */
    protected static array $reserved = [
        'api',
        'client',
        'install',
        'install.php',
        'login',
        'notifications',
        'portal',
        's',
        'sub',
        'up',
    ];

    public static function slug(string $role): string
    {
        return self::all()[$role] ?? (string) config("shahpanel.portal_paths.{$role}", $role);
    }

    public static function slugForRole(UserRole $role): string
    {
        return match ($role) {
            UserRole::Admin => self::slug('admin'),
            UserRole::Agent => self::slug('agent'),
            UserRole::Seller => self::slug('seller'),
            default => 'client',
        };
    }

    /**
     * @return array<string, string> role key => slug
     */
    public static function all(): array
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $defaults = (array) config('shahpanel.portal_paths', []);
        $resolved = [
            'admin' => self::sanitizeSlug($defaults['admin'] ?? 'admin', 'admin'),
            'agent' => self::sanitizeSlug($defaults['agent'] ?? 'agent', 'agent'),
            'seller' => self::sanitizeSlug($defaults['seller'] ?? 'seller', 'seller'),
        ];

        if (function_exists('shahpanel_installed') && shahpanel_installed()) {
            try {
                if (Schema::hasTable('settings')) {
                    foreach (array_keys($resolved) as $role) {
                        $stored = Setting::getValue("portal_path_{$role}");
                        if ($stored !== null && $stored !== '') {
                            $resolved[$role] = self::sanitizeSlug($stored, $resolved[$role]);
                        }
                    }
                }
            } catch (\Throwable) {
                // Keep config defaults when DB is unavailable.
            }
        }

        return self::$resolved = $resolved;
    }

    public static function roleFromSlug(string $slug): ?UserRole
    {
        foreach (self::all() as $roleKey => $path) {
            if ($path === $slug) {
                return UserRole::from($roleKey);
            }
        }

        if ($slug === 'client') {
            return UserRole::Client;
        }

        return null;
    }

    public static function loginPortalPattern(): string
    {
        $slugs = array_values(array_unique(array_merge(array_values(self::all()), ['client'])));

        return implode('|', array_map(static fn (string $slug): string => preg_quote($slug, '/'), $slugs));
    }

    /**
     * @return list<string>
     */
    public static function legacyDefaults(): array
    {
        return ['admin', 'agent', 'seller'];
    }

    public static function shouldBlockLegacyPaths(): bool
    {
        if (! function_exists('shahpanel_installed') || ! shahpanel_installed()) {
            return false;
        }

        try {
            if (! Schema::hasTable('settings')) {
                return false;
            }

            return Setting::getValue('block_legacy_portal_paths', '1') === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    public static function isLegacyBlockedPath(string $segment): bool
    {
        if (! self::shouldBlockLegacyPaths()) {
            return false;
        }

        if (! in_array($segment, self::legacyDefaults(), true)) {
            return false;
        }

        $paths = self::all();

        return ($segment === 'admin' && $paths['admin'] !== 'admin')
            || ($segment === 'agent' && $paths['agent'] !== 'agent')
            || ($segment === 'seller' && $paths['seller'] !== 'seller');
    }

    public static function clearCache(): void
    {
        self::$resolved = null;
    }

    public static function sanitizeSlug(?string $value, string $fallback): string
    {
        $slug = Str::lower(trim((string) $value));
        $slug = trim($slug, '/');
        $slug = preg_replace('/[^a-z0-9\-_]+/', '-', $slug) ?? $fallback;
        $slug = trim($slug, '-_');

        if ($slug === '' || strlen($slug) < 3 || in_array($slug, self::$reserved, true)) {
            return $fallback;
        }

        return $slug;
    }

    public static function validateSlug(string $value, string $role): ?string
    {
        $fallback = (string) config("shahpanel.portal_paths.{$role}", $role);
        $slug = self::sanitizeSlug($value, '');

        if ($slug === '') {
            return __('security.invalid_portal_path');
        }

        $paths = self::all();
        foreach ($paths as $otherRole => $otherSlug) {
            if ($otherRole !== $role && $otherSlug === $slug) {
                return __('security.portal_path_duplicate');
            }
        }

        if ($slug !== self::sanitizeSlug($value, $fallback) && self::sanitizeSlug($value, $fallback) === '') {
            return __('security.invalid_portal_path');
        }

        return null;
    }

    public static function htaccessSnippet(string $role = 'admin'): string
    {
        $path = self::slug($role);

        return <<<HTACCESS
# محافظت Basic Auth برای مسیر {$path} (قبل از RewriteRule اصلی Laravel قرار دهید)
<IfModule mod_auth_basic.c>
    <If "%{REQUEST_URI} =~ m#^/{$path}#">
        AuthType Basic
        AuthName "سامانه امور مشتریان"
        AuthUserFile /home/USER/.htpasswd
        Require valid-user
    </If>
</IfModule>

# ساخت فایل .htpasswd:
# htpasswd -c /home/USER/.htpasswd admin_user
HTACCESS;
    }
}
