<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

    public function __construct()
    {
        $this->patterns = (array) config('security.firewall_patterns', []);
        $this->skipKeys = (array) config('security.firewall_skip_keys', []);
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isFirewallEnabled()) {
            return $next($request);
        }

        if ($this->scanInput($request->query->all(), 'query')
            || $this->scanInput($request->request->all(), 'body')
        ) {
            if (config('security.firewall_log_blocks', true)) {
                Log::warning('security.firewall_block', [
                    'ip' => $request->ip(),
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'user_id' => $request->user()?->id,
                ]);
            }

            abort(403, __('security.firewall_blocked'));
        }

        return $next($request);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function scanInput(array $data, string $context, string $prefix = ''): bool
    {
        foreach ($data as $key => $value) {
            $keyString = (string) $key;
            $path = $prefix === '' ? $keyString : "{$prefix}.{$keyString}";

            if ($this->shouldSkipKey($keyString)) {
                continue;
            }

            if (is_array($value)) {
                if ($this->scanInput($value, $context, $path)) {
                    return true;
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

            if ($this->matchesAttackPattern($string)) {
                return true;
            }
        }

        return false;
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

    protected function matchesAttackPattern(string $value): bool
    {
        foreach ($this->patterns as $pattern) {
            if (@preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
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
