<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TwoFactorSettingsController extends Controller
{
    public function __construct(
        protected TwoFactorService $twoFactor,
    ) {}

    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $secret = $request->session()->get('two_factor_setup_secret');

        if (! $secret && ! $this->twoFactor->isEnabled($user)) {
            $secret = $this->twoFactor->generateSecret();
            $request->session()->put('two_factor_setup_secret', $secret);
        }

        return view('profile.two-factor', [
            'user' => $user,
            'enabled' => $this->twoFactor->isEnabled($user),
            'secret' => $secret,
            'qrUrl' => $secret ? $this->twoFactor->otpAuthUrl($user, $secret) : null,
        ]);
    }

    public function enable(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($this->twoFactor->isEnabled($user)) {
            return back()->with('warning', __('security.two_factor_already_enabled'));
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $secret = $request->session()->get('two_factor_setup_secret');

        if (! is_string($secret) || $secret === '') {
            return back()->with('error', __('security.two_factor_setup_expired'));
        }

        if (! $this->twoFactor->enable($user, $secret, $validated['code'])) {
            return back()->withErrors(['code' => __('security.two_factor_invalid')]);
        }

        $request->session()->forget('two_factor_setup_secret');

        return back()->with('success', __('security.two_factor_enabled'));
    }

    public function disable(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->twoFactor->isEnabled($user)) {
            return back()->with('warning', __('security.two_factor_not_enabled'));
        }

        $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        if (! $this->twoFactor->verify($user, $request->string('code')->toString())) {
            return back()->withErrors(['code' => __('security.two_factor_invalid')]);
        }

        $this->twoFactor->disable($user);
        $request->session()->forget('two_factor_setup_secret');

        return back()->with('success', __('security.two_factor_disabled'));
    }
}
