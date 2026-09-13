<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ImpersonationService;
use App\Services\LoginCaptchaService;
use App\Services\TwoFactorService;
use App\Support\PanelMaintenanceSettings;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Throwable;

class AuthController extends Controller
{
    public function __construct(
        protected TwoFactorService $twoFactor,
        protected ImpersonationService $impersonation,
        protected LoginCaptchaService $loginCaptcha,
    ) {}

    public function showHome(Request $request): View|RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        if (Auth::check()) {
            $user = Auth::user();

            if (PanelMaintenanceSettings::isEnabled() && $user->role !== UserRole::Admin) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return response()->view('maintenance.panel', [
                    'message' => PanelMaintenanceSettings::message(),
                    'showAdminLoginLink' => true,
                ], 503);
            }

            return redirect()->route($this->dashboardRoute($user->role));
        }

        if (PanelMaintenanceSettings::isEnabled() && ! $request->boolean('admin')) {
            return response()->view('maintenance.panel', [
                'message' => PanelMaintenanceSettings::message(),
                'showAdminLoginLink' => true,
            ], 503);
        }

        return view('auth.login', [
            'title' => __('auth.login'),
            'unified' => true,
            'captcha' => login_page_captcha($request),
        ]);
    }

    public function loginUnified(Request $request): RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        try {
            $credentials = $request->validate([
                'username' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string'],
                'captcha' => ['required', 'string', 'max:12'],
                'captcha_token' => ['required', 'string', 'size:40'],
            ]);
        } catch (ValidationException $exception) {
            return $this->loginFailedResponse($request, $exception);
        }

        try {
            $this->loginCaptcha->assertValid(
                $request,
                $credentials['captcha'],
                $credentials['captcha_token'],
            );
        } catch (ValidationException $exception) {
            return $this->loginFailedResponse($request, $exception);
        }

        $username = trim($credentials['username']);

        try {
            if (! Auth::attempt([
                'username' => $username,
                'password' => $credentials['password'],
            ], $request->boolean('remember'))) {
                return $this->loginFailedResponse($request, null, ['username' => __('auth.failed')]);
            }

            /** @var User $authenticated */
            $authenticated = Auth::user();

            if ($authenticated->status !== UserStatus::Active) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $this->loginFailedResponse($request, null, ['username' => __('auth.suspended')]);
            }

            if (PanelMaintenanceSettings::isEnabled() && $authenticated->role !== UserRole::Admin) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return response()->view('maintenance.panel', [
                    'message' => PanelMaintenanceSettings::message(),
                    'showAdminLoginLink' => true,
                ], 503);
            }

            if ($this->twoFactor->isEnabled($authenticated)) {
                $request->session()->put('two_factor_login', [
                    'user_id' => $authenticated->id,
                    'remember' => $request->boolean('remember'),
                ]);

                Auth::logout();

                return redirect()->route('auth.two-factor.challenge');
            }

            return $this->completeLogin($request, $authenticated);
        } catch (Throwable $exception) {
            report($exception);
            Auth::logout();

            return $this->loginFailedResponse($request, null, ['username' => __('auth.login_error')]);
        }
    }

    /**
     * @param  array<string, string>|null  $errors
     */
    protected function loginFailedResponse(
        Request $request,
        ?ValidationException $validationException = null,
        ?array $errors = null,
    ): RedirectResponse {
        usleep(random_int(150_000, 400_000));

        $captcha = login_page_captcha($request);

        $response = back()
            ->withInput($request->only('username', 'remember'))
            ->with('loginCaptcha', $captcha);

        if ($validationException !== null) {
            return $response->withErrors($validationException->errors());
        }

        if ($errors !== null) {
            return $response->withErrors($errors);
        }

        return $response;
    }

    public function showLoginForm(string $portal): RedirectResponse
    {
        return redirect()->route('login');
    }

    public function login(Request $request, string $portal): RedirectResponse
    {
        return $this->loginUnified($request);
    }

    public function logout(Request $request, ?string $portal = null): RedirectResponse
    {
        if ($this->impersonation->isImpersonating()) {
            $result = $this->impersonation->leave();

            return redirect()->to($result['returnUrl']);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    protected function completeLogin(Request $request, User $user): RedirectResponse
    {
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
