<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use App\Support\PanelMaintenanceSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    public function __construct(
        protected TwoFactorService $twoFactor,
    ) {}

    public function showChallenge(Request $request): View|RedirectResponse
    {
        $pending = $request->session()->get('two_factor_login');

        if (! is_array($pending) || empty($pending['user_id'])) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge', [
            'title' => __('security.two_factor_challenge'),
        ]);
    }

    public function verifyChallenge(Request $request): RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        $pending = $request->session()->get('two_factor_login');

        if (! is_array($pending) || empty($pending['user_id'])) {
            return redirect()->route('login');
        }

        $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        /** @var User|null $user */
        $user = User::query()->find($pending['user_id']);

        if ($user === null || ! $this->twoFactor->verify($user, $request->string('code')->toString())) {
            return back()->withErrors(['code' => __('security.two_factor_invalid')]);
        }

        if (PanelMaintenanceSettings::isEnabled() && $user->role !== UserRole::Admin) {
            $request->session()->forget('two_factor_login');

            return response()->view('maintenance.panel', [
                'message' => PanelMaintenanceSettings::message(),
                'showAdminLoginLink' => true,
            ], 503);
        }

        $request->session()->forget('two_factor_login');
        Auth::login($user, (bool) ($pending['remember'] ?? false));

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $request->session()->regenerate();
        $request->session()->forget('url.intended');

        return redirect()->route($this->dashboardRoute($user->role));
    }

    protected function dashboardRoute(UserRole $role): string
    {
        return match ($role) {
            UserRole::Admin => 'admin.dashboard',
            UserRole::Agent => 'agent.dashboard',
            UserRole::Seller => 'seller.dashboard',
            UserRole::Client => 'client.dashboard',
        };
    }
}
