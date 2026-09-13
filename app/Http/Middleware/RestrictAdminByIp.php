<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RestrictAdminByIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $whitelist = $this->resolveWhitelist();

        if ($whitelist === []) {
            return $next($request);
        }

        $clientIp = (string) $request->ip();

        if (! in_array($clientIp, $whitelist, true)) {
            abort(403, __('security.admin_ip_denied'));
        }

        return $next($request);
    }

    /**
     * @return list<string>
     */
    protected function resolveWhitelist(): array
    {
        $fromEnv = config('security.admin_ip_whitelist', []);

        if (function_exists('vpnpanel_installed') && vpnpanel_installed()) {
            try {
                if (Schema::hasTable('settings')) {
                    $stored = Setting::getValue('admin_ip_whitelist');

                    if ($stored !== null && trim($stored) !== '') {
                        return array_values(array_filter(array_map('trim', explode(',', $stored))));
                    }
                }
            } catch (\Throwable) {
                // Fall back to env/config.
            }
        }

        return is_array($fromEnv) ? $fromEnv : [];
    }
}
