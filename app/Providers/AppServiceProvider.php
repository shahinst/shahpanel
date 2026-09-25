<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\PaymentRequest;
use App\Models\Server;
use App\Models\User;
use App\Policies\AccountPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\PackagePolicy;
use App\Policies\PaymentRequestPolicy;
use App\Policies\ServerPolicy;
use App\Policies\UserPolicy;
use App\Enums\UserRole;
use App\Services\PaymentGateways\PaymentGatewayManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use App\Services\ImpersonationService;
use App\Services\LoginCaptchaService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ImpersonationService::class);
        $this->app->singleton(PaymentGatewayManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        date_default_timezone_set(config('app.timezone'));

        // All payment-gateway drivers are registered by the "payments" module
        // (see Modules\NowPayments\NowPaymentsServiceProvider). When that module
        // is deactivated, no driver is registered and the whole payment section
        // becomes non-operational and hidden.

        if (function_exists('app_display_name')) {
            config(['app.name' => app_display_name()]);
        }

        if (class_exists(\Carbon\Carbon::class)) {
            \Carbon\Carbon::setLocale(app()->getLocale());
        }

        if (extension_loaded('intl')) {
            \Illuminate\Support\Number::useLocale(app()->getLocale());
        }
        Paginator::useBootstrapFive();
        Paginator::defaultView('vendor.pagination.panel');

        // Queue worker heartbeat for the admin system-health page (cron runs
        // queue:work --stop-when-empty every minute on shared hosting).
        \Illuminate\Support\Facades\Queue::looping(function (): void {
            \Illuminate\Support\Facades\Cache::put(
                'system_health.queue_worker_at',
                now()->timestamp,
                now()->addHours(6),
            );
        });

        View::composer('auth.login', function ($view): void {
            $captcha = $view->getData()['captcha'] ?? null;

            if (is_array($captcha) && ($captcha['svg'] ?? '') !== '' && ($captcha['token'] ?? '') !== '') {
                return;
            }

            $flash = session('loginCaptcha');

            if (is_array($flash) && ($flash['svg'] ?? '') !== '' && ($flash['token'] ?? '') !== '') {
                $view->with('captcha', $flash);

                return;
            }

            try {
                $view->with('captcha', app(LoginCaptchaService::class)->issue(request()));
            } catch (\Throwable $exception) {
                report($exception);
            }
        });

        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(8)->by($request->ip()),
                Limit::perMinute(5)->by(western_digits((string) $request->input('username', '')).'|'.$request->ip()),
            ];
        });

        RateLimiter::for('login-captcha', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('two-factor', function (Request $request) {
            $pending = $request->session()->get('two_factor_login.user_id', 'guest');

            return Limit::perMinute(10)->by($pending.'|'.$request->ip());
        });

        RateLimiter::for('impersonation', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('portal-stats', function (Request $request) {
            return Limit::perMinute(120)->by($request->route('token').'|'.$request->ip());
        });

        RateLimiter::for('subscription', function (Request $request) {
            // هر کلاینت VPN این نشانی را روی تایمر صدا می‌زند و ربات‌های واسط
            // هم روی تایم‌اوت کوتاه دوباره تلاش می‌کنند؛ سقف باید طوری باشد که
            // یک کاربر معمولی هرگز مسدود نشود ولی اسکن توکن هم صرف نکند.
            return Limit::perMinute(60)->by($request->route('token').'|'.$request->ip());
        });

        RateLimiter::for('client-panel', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // مسیرهای پول‌جابه‌جاکن (تمدید، برگشت وجه، تأیید درخواست پرداخت) قفل ردیف دارند،
        // ولی بدون محدودیت نرخ یک مهاجم می‌تواند هزاران درخواست همزمان بفرستد تا شکاف
        // زمانی پیدا کند. این سقف کارِ عادی ادمین را نمی‌بندد و آن حمله را بی‌صرفه می‌کند.
        RateLimiter::for('money-actions', function (Request $request) {
            return [Limit::perMinute(60)->by($request->user()?->id ?: $request->ip())];
        });

        RateLimiter::for('client-actions', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Failed::class,
            [\App\Listeners\RecordLoginOutcome::class, 'handleFailed'],
        );
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Auth\Events\Login::class,
            [\App\Listeners\RecordLoginOutcome::class, 'handleLogin'],
        );

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        Route::bind('account', function (string $value) {
            $account = Account::query()->findOrFail($value);

            if (request()->routeIs('client.*') && auth()->check()) {
                $user = auth()->user();

                if ($user instanceof User && $user->role === UserRole::Client) {
                    abort_unless((int) $account->client_user_id === (int) $user->id, 404);
                }
            }

            return $account;
        });

        Route::bind('client', function (string $value) {
            $user = User::query()->findOrFail($value);
            abort_unless($user->role === UserRole::Client, 404);

            return $user;
        });

        Route::bind('seller', function (string $value) {
            $seller = User::query()->findOrFail($value);
            abort_unless($seller->role === UserRole::Seller, 404);

            $viewer = auth()->user();

            if ($viewer instanceof User && $viewer->role === UserRole::Agent) {
                abort_unless(in_array((int) $seller->id, User::subtreeUserIds($viewer), true), 404);
            }

            return $seller;
        });

        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(Server::class, ServerPolicy::class);
        Gate::policy(Package::class, PackagePolicy::class);

        if (class_exists(\App\Models\PackageCategory::class)) {
            Gate::policy(\App\Models\PackageCategory::class, \App\Policies\PackageCategoryPolicy::class);
        }

        Gate::policy(PaymentRequest::class, PaymentRequestPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
    }
}
