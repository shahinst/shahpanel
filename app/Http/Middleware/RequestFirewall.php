<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestFirewall
{
    /**
     * @var list<string>
     */
    protected array $patterns;

    /**
     * @var list<string>
     */
    protected array $skipKeys;

    /**
     * @var list<string>
     */
    protected array $skipPaths;

    public function __construct()
    {
        $this->patterns = (array) config('security.firewall_patterns', []);
        $this->skipKeys = (array) config('security.firewall_skip_keys', []);
        $this->skipPaths = (array) config('security.firewall_skip_paths', []);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isFirewallEnabled()) {
            return $next($request);
        }

        $offender = $this->scanInput($request->query->all(), 'query')
            ?? $this->scanInput($request->request->all(), 'body');

        if ($offender !== null) {
            if (config('security.firewall_log_blocks', true)) {
                Log::warning('security.firewall_block', [
                    'ip' => $request->ip(),
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'user_id' => $request->user()?->id,
                    // کدام فیلد و کدام الگو. بدون این دو، یک ۴۰۳ روی فرمِ پنل
                    // هیچ سرنخی نمی‌داد و باید حدس می‌زدیم کدام ورودی رد شده.
                    'input' => $offender['path'],
                    'pattern' => $offender['pattern'],
                ]);
            }

            abort(403, __('security.firewall_blocked'));
        }

        return $next($request);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{path: string, pattern: string}|null
     */
    protected function scanInput(array $data, string $context, string $prefix = ''): ?array
    {
        foreach ($data as $key => $value) {
            $keyString = (string) $key;
            $path = $prefix === '' ? $keyString : "{$prefix}.{$keyString}";

            if ($this->shouldSkipKey($keyString) || $this->shouldSkipPath($path)) {
                continue;
            }

            if (is_array($value)) {
                $nested = $this->scanInput($value, $context, $path);

                if ($nested !== null) {
                    return $nested;
                }

                continue;
            }

            if (! is_scalar($value)) {
                continue;
            }

            $string = (string) $value;

            if ($string === '') {
                continue;
            }

            $pattern = $this->matchedAttackPattern($string);

            if ($pattern !== null) {
                return ['path' => $path, 'pattern' => $pattern];
            }
        }

        return null;
    }

    protected function shouldSkipPath(string $path): bool
    {
        return $this->skipPaths !== [] && Str::is($this->skipPaths, $path);
    }

    protected function shouldSkipKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach ($this->skipKeys as $skip) {
            if ($normalized === strtolower($skip)) {
                return true;
            }
        }

        return str_contains($normalized, 'password');
    }

    protected function matchedAttackPattern(string $value): ?string
    {
        foreach ($this->patterns as $pattern) {
            if (@preg_match($pattern, $value) === 1) {
                return $pattern;
            }
        }

        return null;
    }

    protected function isFirewallEnabled(): bool
    {
        if (! config('security.firewall_enabled', true)) {
            return false;
        }

        if (function_exists('shahpanel_installed') && shahpanel_installed()) {
            try {
                if (\Illuminate\Support\Facades\Schema::hasTable('settings')) {
                    return \App\Models\Setting::getValue('firewall_enabled', '1') === '1';
                }
            } catch (\Throwable) {
                // Fall back to config.
            }
        }

        return true;
    }
}
