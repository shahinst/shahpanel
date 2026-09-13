<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Support\PanelMaintenanceSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforcePanelMaintenance
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! PanelMaintenanceSettings::isEnabled()) {
            return $next($request);
        }

        if ($this->shouldBypass($request)) {
            return $next($request);
        }

        $user = $request->user();

        if ($user !== null && $user->role === UserRole::Admin) {
            return $next($request);
        }

        if ($user !== null) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => PanelMaintenanceSettings::message(),
            ], 503);
        }

        return response()->view('maintenance.panel', [
            'message' => PanelMaintenanceSettings::message(),
            'showAdminLoginLink' => true,
        ], 503);
    }

    protected function shouldBypass(Request $request): bool
    {
        if ($request->is('up')) {
            return true;
        }

        if ($request->routeIs(
            'webhooks.*',
            'tunneling.report',
            'auth.two-factor.challenge',
            'auth.two-factor.verify',
        )) {
            return true;
        }

        if ($request->routeIs('login', 'login.submit', 'login.captcha', 'home')) {
            return true;
        }

        if ($request->routeIs('logout', 'auth.logout', 'auth.login', 'auth.login.submit')) {
            return true;
        }

        return false;
    }
}
