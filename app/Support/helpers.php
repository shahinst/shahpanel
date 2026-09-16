<?php

declare(strict_types=1);

use Morilog\Jalali\Jalalian;

if (! function_exists('shahpanel_installed')) {
    function shahpanel_installed(): bool
    {
        return file_exists(config('shahpanel.installed_lock'));
    }
}

if (! function_exists('persian_digits')) {
    function persian_digits(int|float|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        // Only Persian reads Eastern Arabic numerals. Called from ~300 places,
        // so gating it here is what keeps English, Russian and Chinese pages
        // from showing ۱۲۳ instead of 123.
        if (locale_digits() !== 'fa') {
            return (string) $value;
        }

        $western = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

        return str_replace($western, $persian, (string) $value);
    }
}

if (! function_exists('locale_meta')) {
    /** @return array<string, string> */
    function locale_meta(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $all = (array) config('locales.supported', []);

        return (array) ($all[$locale] ?? $all['fa'] ?? ['dir' => 'rtl', 'digits' => 'fa']);
    }
}

if (! function_exists('locale_dir')) {
    /** "rtl" or "ltr" for the active language. */
    function locale_dir(?string $locale = null): string
    {
        return (string) (locale_meta($locale)['dir'] ?? 'rtl');
    }
}

if (! function_exists('locale_is_rtl')) {
    function locale_is_rtl(?string $locale = null): bool
    {
        return locale_dir($locale) === 'rtl';
    }
}

if (! function_exists('locale_digits')) {
    /** Numeral set the active language reads: "fa" or "latn". */
    function locale_digits(?string $locale = null): string
    {
        return (string) (locale_meta($locale)['digits'] ?? 'latn');
    }
}

if (! function_exists('western_digits')) {
    function western_digits(int|float|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $western = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

        return str_replace($persian, $western, (string) $value);
    }
}

if (! function_exists('format_toman')) {
    function format_toman(int|float|string|null $amount): string
    {
        return format_money($amount, \App\Enums\MoneyCurrency::IRT);
    }
}

if (! function_exists('format_money')) {
    function format_money(int|float|string|null $amount, \App\Enums\MoneyCurrency|string|null $currency = null): string
    {
        $currencyEnum = \App\Enums\MoneyCurrency::normalize(
            $currency instanceof \App\Enums\MoneyCurrency ? $currency->value : $currency
        );

        if ($amount === null || $amount === '') {
            $amount = 0;
        }

        $decimals = $currencyEnum->displayDecimals();
        $formatted = number_format((float) $amount, $decimals, '.', ',');

        return persian_digits($formatted).' '.$currencyEnum->symbol();
    }
}

if (! function_exists('webadmin_asset')) {
    function webadmin_asset(string $path): string
    {
        $base = trim((string) config('webadmin.asset_base', 'build'), '/');

        return asset($base.'/'.ltrim($path, '/'));
    }
}

if (! function_exists('login_page_captcha')) {
    /**
     * @return array{token: string, display: string, svg: string}
     */
    function login_page_captcha(\Illuminate\Http\Request $request): array
    {
        try {
            return app(\App\Services\LoginCaptchaService::class)->issue($request);
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'token' => '',
                'display' => '',
                'svg' => '',
            ];
        }
    }
}

if (! function_exists('panel_vite_use_dev')) {
    function panel_vite_use_dev(): bool
    {
        if (! app()->environment('local')) {
            return false;
        }

        return is_file(public_path('hot'));
    }
}

if (! function_exists('panel_vite_manifest_tags')) {
    /**
     * @param  list<string>  $entries
     * @return list<array{type: string, href: string}>
     */
    function panel_vite_manifest_tags(array $entries): array
    {
        $manifestPath = public_path('build/manifest.json');
        $manifest = null;

        try {
            if (is_readable($manifestPath)) {
                $decoded = json_decode((string) file_get_contents($manifestPath), true);
                $manifest = is_array($decoded) ? $decoded : null;
            }
        } catch (\Throwable) {
            $manifest = null;
        }

        $tags = [];
        $emittedJs = [];

        foreach ($entries as $entry) {
            $item = is_array($manifest) ? ($manifest[$entry] ?? null) : null;
            $file = is_array($item) ? ($item['file'] ?? null) : null;

            if (! is_string($file) || $file === '') {
                $file = panel_vite_glob_fallback($entry);
            }

            if (! is_string($file) || $file === '') {
                continue;
            }

            if (str_ends_with($file, '.css')) {
                $tags[] = ['type' => 'css', 'href' => asset('build/'.$file)];

                continue;
            }

            if (! str_ends_with($file, '.js')) {
                continue;
            }

            if (is_array($manifest) && is_array($item)) {
                foreach ((array) ($item['imports'] ?? []) as $importKey) {
                    $chunkFile = $manifest[$importKey]['file'] ?? null;

                    if (is_string($chunkFile) && $chunkFile !== '' && ! in_array($chunkFile, $emittedJs, true)) {
                        $emittedJs[] = $chunkFile;
                        $tags[] = ['type' => 'preload', 'href' => asset('build/'.$chunkFile)];
                    }
                }
            }

            if (! in_array($file, $emittedJs, true)) {
                $emittedJs[] = $file;
                $tags[] = ['type' => 'js', 'href' => asset('build/'.$file)];
            }
        }

        return $tags;
    }
}

if (! function_exists('panel_vite_dev_tags')) {
    /**
     * @param  list<string>  $entries
     * @return list<array{type: string, href: string}>
     */
    function panel_vite_dev_tags(array $entries): array
    {
        /** @var \Illuminate\Foundation\Vite $vite */
        $vite = app(\Illuminate\Foundation\Vite::class);
        $tags = [];

        foreach ($entries as $entry) {
            $href = $vite->asset($entry);
            $tags[] = str_ends_with($entry, '.css')
                ? ['type' => 'css', 'href' => $href]
                : ['type' => 'js', 'href' => $href];
        }

        return $tags;
    }
}

if (! function_exists('panel_vite_emergency_tags')) {
    /**
     * Last-resort asset tags when manifest/hot are unavailable (prevents blank panel).
     *
     * @return list<array{type: string, href: string}>
     */
    function panel_vite_emergency_tags(array $entries = []): array
    {
        $tags = [];

        foreach ($entries as $entry) {
            $file = panel_vite_glob_fallback($entry);

            if (! is_string($file) || $file === '') {
                continue;
            }

            $href = asset('build/'.$file);

            if (str_ends_with($file, '.css') && ! collect($tags)->contains(fn (array $tag): bool => ($tag['href'] ?? '') === $href)) {
                $tags[] = ['type' => 'css', 'href' => $href];

                continue;
            }

            if (str_ends_with($file, '.js') && ! collect($tags)->contains(fn (array $tag): bool => ($tag['href'] ?? '') === $href)) {
                $tags[] = ['type' => 'js', 'href' => $href];
            }
        }

        if ($tags !== []) {
            return $tags;
        }

        foreach (['app-*.css', 'icons-*.css', 'app-*.js'] as $pattern) {
            $matches = glob(public_path('build/assets/'.$pattern)) ?: [];
            if ($matches === []) {
                continue;
            }

            usort($matches, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
            $file = 'assets/'.basename($matches[0]);
            $href = asset('build/'.$file);

            if (str_ends_with($file, '.css') && ! collect($tags)->contains(fn (array $tag): bool => ($tag['href'] ?? '') === $href)) {
                $tags[] = ['type' => 'css', 'href' => $href];
            } elseif (str_ends_with($file, '.js') && ! collect($tags)->contains(fn (array $tag): bool => ($tag['href'] ?? '') === $href)) {
                $tags[] = ['type' => 'js', 'href' => $href];
            }
        }

        return $tags;
    }
}

if (! function_exists('panel_vite_tags')) {
    /**
     * Resolve panel CSS/JS without Blade @vite (never throws HTTP 500 on missing manifest).
     *
     * @param  list<string>  $entries
     * @return list<array{type: string, href: string}>
     */
    function panel_vite_tags(array $entries): array
    {
        if (panel_vite_use_dev()) {
            try {
                $devTags = panel_vite_dev_tags($entries);
                if ($devTags !== []) {
                    return $devTags;
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $tags = panel_vite_manifest_tags($entries);

        if ($tags !== []) {
            return $tags;
        }

        return panel_vite_emergency_tags($entries);
    }
}

if (! function_exists('panel_vite_resolve')) {
    /**
     * @param  list<string>  $entries
     * @return list<array{type: string, href: string}>
     */
    function panel_vite_resolve(array $entries): array
    {
        return panel_vite_tags($entries);
    }
}

if (! function_exists('panel_vite_glob_fallback')) {
    function panel_vite_glob_fallback(string $entry): ?string
    {
        $pattern = match ($entry) {
            'resources/css/auth.css' => 'auth-*.css',
            'resources/css/portal.css' => 'portal-*.css',
            'resources/css/app.css' => 'app-*.css',
            'resources/js/app.js' => 'app-*.js',
            'resources/js/portal.js' => 'portal-*.js',
            default => null,
        };

        if ($pattern === null) {
            return null;
        }

        $matches = glob(public_path('build/assets/'.$pattern)) ?: [];

        if ($matches === []) {
            return null;
        }

        usort($matches, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return 'assets/'.basename($matches[0]);
    }
}

if (! function_exists('format_data_size')) {
    function format_data_size(int|float|null $bytes, int $precision = 2): string
    {
        if ($bytes === null || $bytes <= 0) {
            return persian_digits('0 B');
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return persian_digits(number_format($value, $precision, '.', ',')).' '.$units[$power];
    }
}

if (! function_exists('jalali_date')) {
    function jalali_date(DateTimeInterface|string|null $date, string $format = 'Y/m/d H:i'): string
    {
        if ($date === null || $date === '') {
            return '';
        }

        if (is_string($date)) {
            $date = new DateTimeImmutable($date);
        }

        return persian_digits(Jalalian::fromDateTime($date)->format($format));
    }
}

if (! function_exists('jalali_date_input')) {
    function jalali_date_input(DateTimeInterface|string|null $date): string
    {
        return jalali_date($date, 'Y/m/d');
    }
}

if (! function_exists('parse_jalali_date')) {
    function parse_jalali_date(?string $value, bool $endOfDay = false): ?\Illuminate\Support\Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = str_replace('-', '/', western_digits(trim($value)));

        try {
            $carbon = Jalalian::fromFormat('Y/m/d', $normalized)->toCarbon();
        } catch (\Throwable) {
            return null;
        }

        $carbon = $endOfDay ? $carbon->endOfDay() : $carbon->startOfDay();

        // Jalalian::toCarbon() yields a base Carbon\Carbon; the app standardises on
        // Illuminate\Support\Carbon (now(), model casts), so normalise it here to
        // avoid strict return-type mismatches in callers.
        return \Illuminate\Support\Carbon::instance($carbon);
    }
}

if (! function_exists('jalali_now')) {
    function jalali_now(string $format = 'Y/m/d H:i'): string
    {
        return persian_digits(Jalalian::now()->format($format));
    }
}

if (! function_exists('portal_path')) {
    function portal_path(string $role): string
    {
        return \App\Support\PortalPaths::slug($role);
    }
}

if (! function_exists('portal_login_slug')) {
    function portal_login_slug(?\App\Enums\UserRole $role = null): string
    {
        $role ??= auth()->user()?->role;

        if ($role === null) {
            return portal_path('admin');
        }

        return \App\Support\PortalPaths::slugForRole($role);
    }
}

if (! function_exists('client_portal_login_url')) {
    function client_portal_login_url(): string
    {
        return route('auth.login', ['portal' => portal_login_slug(\App\Enums\UserRole::Client)]);
    }
}

if (! function_exists('is_impersonating')) {
    function is_impersonating(): bool
    {
        return app(\App\Services\ImpersonationService::class)->isImpersonating();
    }
}

if (! function_exists('impersonator')) {
    function impersonator(): ?\App\Models\User
    {
        return app(\App\Services\ImpersonationService::class)->impersonator();
    }
}

if (! function_exists('app_display_name')) {
    function app_display_name(): string
    {
        $default = (string) __('app.name');

        $name = (string) config('app.name', $default);

        if (in_array($name, ['VPN Panel', 'Laravel'], true)) {
            $name = $default;
        }

        if (! function_exists('shahpanel_installed') || ! shahpanel_installed()) {
            return $name;
        }

        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('settings')) {
                return $name;
            }

            $stored = trim((string) \App\Models\Setting::getValue('site_name', ''));

            if ($stored === 'VPN Panel') {
                return $default;
            }

            if ($stored !== '') {
                return $stored;
            }
        } catch (\Throwable) {
            // keep computed name
        }

        return $name;
    }
}

if (! function_exists('panel_wallet_info')) {
    /**
     * @return array{balance: string, infinite: bool, currency: string, wallets: list<array{currency: string, balance: string, label: string, symbol: string}>}
     */
    function panel_wallet_info(?\App\Models\User $user = null): array
    {
        try {
            $user ??= auth()->user();
            $defaultCurrency = \App\Enums\MoneyCurrency::default();

            if ($user === null) {
                return [
                    'balance' => '0',
                    'infinite' => false,
                    'currency' => $defaultCurrency->value,
                    'wallets' => [],
                ];
            }

            $user->loadMissing('wallets');

            $wallets = $user->wallets
                ->sortBy(fn ($wallet) => $wallet->currency === $defaultCurrency->value ? 0 : 1)
                ->values()
                ->map(function ($wallet): array {
                    $currency = \App\Enums\MoneyCurrency::normalize((string) $wallet->currency);

                    return [
                        'currency' => $currency->value,
                        'balance' => (string) ($wallet->balance ?? '0'),
                        'label' => $currency->label(),
                        'symbol' => $currency->symbol(),
                    ];
                })
                ->all();

            if ($wallets === []) {
                $wallets[] = [
                    'currency' => $defaultCurrency->value,
                    'balance' => '0',
                    'label' => $defaultCurrency->label(),
                    'symbol' => $defaultCurrency->symbol(),
                ];
            }

            $primary = collect($wallets)->firstWhere('currency', $defaultCurrency->value) ?? $wallets[0];

            return [
                'balance' => $primary['balance'],
                'infinite' => $user->role === \App\Enums\UserRole::Admin
                    && (bool) config('shahpanel.admin_wallet_infinite', true),
                'currency' => $primary['currency'],
                'wallets' => $wallets,
            ];
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'balance' => '0',
                'infinite' => false,
                'currency' => \App\Enums\MoneyCurrency::default()->value,
                'wallets' => [],
            ];
        }
    }
}

if (! function_exists('server_interface_type_label')) {
    function server_interface_type_label(\App\Models\ServerInterface $iface): string
    {
        if (method_exists($iface, 'profileTypeLabel')) {
            return $iface->profileTypeLabel();
        }

        return match ($iface->category) {
            'wireguard' => 'WireGuard',
            'ppp' => 'PPP',
            'inbound' => (string) ($iface->protocol ?: 'Inbound'),
            default => (string) ($iface->category ?: '—'),
        };
    }
}

if (! function_exists('admin_server_sync_profiles_route')) {
    function admin_server_sync_profiles_route(): string
    {
        return \Illuminate\Support\Facades\Route::has('admin.servers.sync-profiles')
            ? 'admin.servers.sync-profiles'
            : 'admin.servers.sync-interfaces';
    }
}

if (! function_exists('admin_server_refresh_router_route')) {
    function admin_server_refresh_router_route(): string
    {
        return \Illuminate\Support\Facades\Route::has('admin.servers.refresh-from-router')
            ? 'admin.servers.refresh-from-router'
            : admin_server_sync_profiles_route();
    }
}

if (! function_exists('panel_api_message')) {
    /**
     * Normalize 3x-ui / panel API error payloads (msg may be string or array).
     */
    function panel_api_message(mixed $message, string $fallback = 'unknown error'): string
    {
        if ($message === null || $message === '') {
            return $fallback;
        }

        if (is_string($message) || is_numeric($message)) {
            return (string) $message;
        }

        if (is_array($message)) {
            foreach (['msg', 'message', 'error', 'detail', 'description'] as $key) {
                if (! isset($message[$key])) {
                    continue;
                }

                if (is_string($message[$key]) || is_numeric($message[$key])) {
                    return (string) $message[$key];
                }

                if (is_array($message[$key])) {
                    $nested = panel_api_message($message[$key], '');

                    if ($nested !== '') {
                        return $nested;
                    }
                }
            }

            $parts = [];
            foreach ($message as $item) {
                if (is_string($item) || is_numeric($item)) {
                    $parts[] = (string) $item;
                }
            }

            if ($parts !== []) {
                return implode('; ', $parts);
            }

            $encoded = json_encode($message, JSON_UNESCAPED_UNICODE);

            return $encoded !== false ? $encoded : $fallback;
        }

        if (is_bool($message)) {
            return $message ? 'true' : 'false';
        }

        return $fallback;
    }
}

if (! function_exists('money_string')) {
    function money_string(mixed $amount): string
    {
        if (is_array($amount)) {
            throw new InvalidArgumentException('Invalid monetary amount.');
        }

        return number_format((float) (string) $amount, 2, '.', '');
    }
}

if (! function_exists('decode_panel_json_field')) {
    /**
     * 3x-ui may return JSON fields as strings or already-decoded arrays.
     *
     * @return array<string, mixed>
     */
    function decode_panel_json_field(mixed $value, array $default = []): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_string($value) || is_numeric($value)) {
            $decoded = json_decode((string) $value, true);

            return is_array($decoded) ? $decoded : $default;
        }

        return $default;
    }
}

if (! function_exists('scalar_string')) {
    function scalar_string(mixed $value, string $default = ''): string
    {
        if ($value === null) {
            return $default;
        }

        if (is_string($value) || is_numeric($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            foreach (['email', 'id', 'subId', 'sub_id', 'msg', 'message', 'value', 'text'] as $key) {
                if (isset($value[$key]) && ! is_array($value[$key])) {
                    return scalar_string($value[$key], $default);
                }
            }

            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);

            return $encoded !== false ? $encoded : $default;
        }

        return $default;
    }
}

if (! function_exists('scalar_int')) {
    function scalar_int(mixed $value, int $default = 0): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_string($value) || is_numeric($value)) {
            return (int) $value;
        }

        if (is_array($value)) {
            foreach (['total', 'value', 'count', 'limit', 'totalGB', 'expiryTime', 'tgId', 'limitIp'] as $key) {
                if (isset($value[$key]) && ! is_array($value[$key])) {
                    return scalar_int($value[$key], $default);
                }
            }
        }

        return $default;
    }
}

if (! function_exists('scalar_bool')) {
    function scalar_bool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        return $default;
    }
}

if (! function_exists('module_active')) {
    /**
     * Whether an installed module (by slug) is currently active. Use this to
     * gate any panel feature/menu so it disappears when its module is disabled.
     */
    function module_active(string $slug): bool
    {
        try {
            return app(\App\Services\Modules\ModuleManager::class)->isActive($slug);
        } catch (\Throwable) {
            return false;
        }
    }
}

if (! function_exists('country_flag')) {
    /** ISO country code → flag emoji (IR → 🇮🇷). */
    function country_flag(?string $code): string
    {
        return \App\Services\GeoIpService::flagEmoji($code);
    }
}

if (! function_exists('ip_flag')) {
    /** IPv4 / CIDR → country flag emoji via offline GeoIP table. */
    function ip_flag(?string $ipOrCidr): string
    {
        try {
            return app(\App\Services\GeoIpService::class)->flagForIp($ipOrCidr);
        } catch (\Throwable) {
            return '🏳️';
        }
    }
}

if (! function_exists('discount_pricing_enabled')) {
    /**
     * Whether the reseller pricing model is in "discount" mode (Model 3).
     * While false (legacy) the old per-user assigned-price logic is used.
     */
    function discount_pricing_enabled(): bool
    {
        try {
            return app(\App\Services\Pricing\ResellerDiscountService::class)->isDiscountMode();
        } catch (\Throwable) {
            return false;
        }
    }
}
