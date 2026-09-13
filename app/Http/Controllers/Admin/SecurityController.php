<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Services\HtaccessBasicAuthService;
use App\Services\TwoFactorService;
use App\Support\PortalPaths;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SecurityController extends Controller
{
    public function index(): View
    {
        $paths = PortalPaths::all();

        $htaccessStatus = [
            'admin' => false,
            'agent' => false,
            'seller' => false,
        ];
        $htaccessPending = false;

        try {
            $htaccessStatus = app(HtaccessBasicAuthService::class)->status();
            $htaccessPending = collect($htaccessStatus)->contains(static fn (bool $applied): bool => ! $applied)
                && \Illuminate\Support\Facades\Route::has('admin.security.htaccess.apply');
        } catch (\Throwable $exception) {
            report($exception);
        }

        $firewallEnabled = true;
        $adminIpWhitelist = '';

        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('settings')) {
                $firewallEnabled = Setting::getValue('firewall_enabled', '1') === '1';
                $adminIpWhitelist = (string) Setting::getValue('admin_ip_whitelist', '');
            }
        } catch (\Throwable) {
            // Keep defaults when DB is unavailable.
        }

        return view('admin.security.index', [
            'paths' => $paths,
            'blockLegacy' => PortalPaths::shouldBlockLegacyPaths(),
            'htaccessStatus' => $htaccessStatus,
            'htaccessPending' => $htaccessPending,
            'firewallEnabled' => $firewallEnabled,
            'adminIpWhitelist' => $adminIpWhitelist,
        ]);
    }

    public function updateFirewall(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'firewall_enabled' => ['nullable', 'boolean'],
            'admin_ip_whitelist' => ['nullable', 'string', 'max:2000'],
        ]);

        Setting::setValue('firewall_enabled', $request->boolean('firewall_enabled') ? '1' : '0');

        $whitelist = trim((string) ($validated['admin_ip_whitelist'] ?? ''));
        if ($whitelist !== '') {
            $ips = array_values(array_filter(array_map('trim', explode(',', $whitelist))));
            foreach ($ips as $ip) {
                if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                    return back()->withInput()->withErrors([
                        'admin_ip_whitelist' => __('security.admin_ip_invalid', ['ip' => $ip]),
                    ]);
                }
            }
            $whitelist = implode(',', $ips);
        }

        Setting::setValue('admin_ip_whitelist', $whitelist);

        return redirect()
            ->route('admin.security.index')
            ->with('success', __('app.saved'));
    }

    public function updatePaths(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'portal_path_admin' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9\-_]+$/', 'min:3'],
            'portal_path_agent' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9\-_]+$/', 'min:3'],
            'portal_path_seller' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9\-_]+$/', 'min:3'],
            'block_legacy_portal_paths' => ['nullable', 'boolean'],
        ]);

        foreach (['admin', 'agent', 'seller'] as $role) {
            $key = "portal_path_{$role}";
            $error = PortalPaths::validateSlug($validated[$key], $role);

            if ($error !== null) {
                return back()->withInput()->withErrors([$key => $error]);
            }
        }

        $slugs = [
            PortalPaths::sanitizeSlug($validated['portal_path_admin'], 'admin'),
            PortalPaths::sanitizeSlug($validated['portal_path_agent'], 'agent'),
            PortalPaths::sanitizeSlug($validated['portal_path_seller'], 'seller'),
        ];

        if (count($slugs) !== count(array_unique($slugs))) {
            return back()->withInput()->withErrors(['portal_path_admin' => __('security.portal_path_duplicate')]);
        }

        Setting::setValue('portal_path_admin', $slugs[0]);
        Setting::setValue('portal_path_agent', $slugs[1]);
        Setting::setValue('portal_path_seller', $slugs[2]);
        Setting::setValue('block_legacy_portal_paths', $request->boolean('block_legacy_portal_paths') ? '1' : '0');

        PortalPaths::clearCache();

        return redirect()
            ->route('admin.security.index')
            ->with('success', __('app.saved'))
            ->with('warning', __('security.paths_changed_hint'));
    }

    public function disableUserTwoFactor(User $user): RedirectResponse
    {
        Gate::authorize('disableTwoFactor', $user);

        if (! in_array($user->role->value, ['admin', 'agent', 'seller'], true)) {
            abort(404);
        }

        app(TwoFactorService::class)->disable($user);

        return back()->with('success', __('security.two_factor_admin_disabled', ['user' => $user->username]));
    }

    public function applyHtaccessBasicAuth(Request $request, string $role): RedirectResponse
    {
        if (! in_array($role, ['admin', 'agent', 'seller'], true)) {
            abort(404);
        }

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_.-]+$/'],
            'password' => ['required', 'string', 'min:6', 'max:128'],
        ]);

        $result = app(HtaccessBasicAuthService::class)->apply(
            $role,
            $validated['username'],
            $validated['password'],
        );

        if (! $result['ok']) {
            return back()->with('error', $result['message']);
        }

        return back()->with('success', $result['message']);
    }
}
