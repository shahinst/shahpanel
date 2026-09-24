<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Reseller API (token auth) lives in routes/api.php. The tunneling
        // module still registers its own admin routes under the same prefix.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware) {
        $prepend = [];

        if (class_exists(\App\Http\Middleware\RequestFirewall::class)) {
            $prepend[] = \App\Http\Middleware\RequestFirewall::class;
        }

        if ($prepend !== []) {
            $middleware->web(prepend: $prepend);
        }

        $append = [
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\EnsureInstalled::class,
            \App\Http\Middleware\HandleImpersonationSession::class,
            \App\Http\Middleware\EnforcePanelMaintenance::class,
        ];

        if (class_exists(\App\Http\Middleware\SecurityHeaders::class)) {
            $append[] = \App\Http\Middleware\SecurityHeaders::class;
        }

        if (class_exists(\App\Http\Middleware\BlockLegacyPortalPaths::class)) {
            $append[] = \App\Http\Middleware\BlockLegacyPortalPaths::class;
        }

        $middleware->web(append: $append);

        // بدون این خط، پاسخ‌های API همیشه به زبان پیش‌فرض پنل برمی‌گشتند: گروه
        // «web» زبان را تعیین می‌کرد و گروه «api» هیچ‌وقت از آن رد نمی‌شد. یک ربات
        // تلگرام معمولاً برای کاربر غیرفارسی‌زبان کار می‌کند، پس هدر Accept-Language
        // باید شنیده شود. جلوتر از api.auth اجرا می‌شود، یعنی ترجیح صریحِ
        // درخواست تعیین‌کننده است نه ستون locale صاحب توکن.
        $middleware->api(prepend: [
            \App\Http\Middleware\SetLocale::class,
        ]);

        // Router probe reports authenticate with a per-server signed token.
        $middleware->validateCsrfTokens(except: [
            'tunneling/report',
            'webhooks/nowpayments',
            'webhooks/zarinpal',
        ]);

        $aliases = [
            'guest.installed' => \App\Http\Middleware\RedirectIfInstalled::class,
            'role' => \App\Http\Middleware\EnsureRole::class,
            'log.activity' => \App\Http\Middleware\LogActivity::class,
            'module' => \App\Http\Middleware\EnsureModuleActive::class,
            'noindex' => \App\Http\Middleware\BlockSearchEngineIndexing::class,
            'api.auth' => \App\Http\Middleware\ApiAuthenticate::class,
            'api.ability' => \App\Http\Middleware\ApiAbility::class,
            'api.role' => \App\Http\Middleware\ApiRole::class,
            'api.throttle' => \App\Http\Middleware\ApiThrottle::class,
            // نمای سازگار با مرزبان (routes/marzban.php). جدا از api.auth و
            // api.throttle است، چون شکل خطای آن {"detail": ...} است نه
            // {"ok":false,...} — ربات‌های مرزبان‌محور همان کلید را می‌خوانند.
            'marzban.auth' => \App\Http\Middleware\MarzbanAuthenticate::class,
            'marzban.throttle' => \App\Http\Middleware\MarzbanThrottle::class,
            'ip.guard' => \App\Http\Middleware\BlockBruteForcedIps::class,
        ];

        if (class_exists(\App\Http\Middleware\RestrictAdminByIp::class)) {
            $aliases['admin.ip'] = \App\Http\Middleware\RestrictAdminByIp::class;
        }

        $middleware->alias($aliases);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /*
         * قالب خطای API.
         *
         * ربات فقط روی کلید ok شرط می‌گذارد. خطاهای کاری و احراز هویت همیشه
         * {"ok":false,"error":{...}} برمی‌گرداندند، اما خطای اعتبارسنجی با قالب
         * پیش‌فرض لاراول بیرون می‌رفت: بدون ok، یعنی برای ربات «موفق‌شکل» بود و
         * بعد سرِ data کرش می‌کرد. پس هر خطای API در همین قالب بسته می‌شود.
         *
         * دامنه فقط api/v1/* است: فرم‌های Blade پنل و اندپوینت‌های ادمینِ ماژول
         * تانل (api/servers و api/tunnels با گروه web) باید دست‌نخورده بمانند،
         * وگرنه هر فرم پنل و هر اسکریپت ادمین می‌شکند.
         */
        $isApi = static fn (Request $request): bool => $request->is('api/v1/*');

        $envelope = static fn (string $code, string $message, int $status, array $extra = []): JsonResponse => response()->json([
            'ok' => false,
            'error' => array_merge(['code' => $code, 'message' => $message], $extra),
        ], $status);

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi, $envelope) {
            if (! $isApi($request)) {
                return null;
            }

            // نام کلید «errors» است چون راهنمای API همین را وعده داده
            // (api.docs_e_validation) و ربات‌های موجود روی آن حساب می‌کنند.
            return $envelope('validation_failed', __('api.validation_failed'), $e->status, [
                'errors' => $e->errors(),
            ]);
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($isApi, $envelope) {
            if (! $isApi($request)) {
                return null;
            }

            $headers = $e->getHeaders();

            // همان کد خطایی که api.throttle می‌دهد، تا ربات یک شاخه کمتر داشته باشد.
            return $envelope('rate_limited', __('api.rate_limited', [
                'seconds' => (int) ($headers['Retry-After'] ?? 60),
            ]), 429)->withHeaders($headers);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($isApi, $envelope) {
            if (! $isApi($request)) {
                return null;
            }

            $status = $e->getStatusCode();

            [$code, $message] = match ($status) {
                401 => ['unauthenticated', __('api.missing_token')],
                403 => ['forbidden', __('api.forbidden')],
                404 => ['not_found', __('api.not_found')],
                405 => ['method_not_allowed', __('api.method_not_allowed')],
                default => ['http_error', __('api.server_error')],
            };

            return $envelope($code, $message, $status);
        });

        // آخرین سد: یک استثنای مدیریت‌نشده نباید صفحهٔ HTML لاراول را به پارسر
        // ربات تحویل بدهد (کلاینت‌های Guzzle معمولاً هدر Accept نمی‌فرستند، پس
        // expectsJson دروغ می‌گوید). در حالت دیباگ کنار می‌رود تا ردّ خطا دیده
        // شود. دو استثنا رد می‌شوند چون خودِ لاراول بعدِ همین callback‌ها درست
        // ترجمه‌شان می‌کند و این سدِ عمومی ۵۰۰ اشتباه می‌ساخت.
        $exceptions->render(function (Throwable $e, Request $request) use ($isApi, $envelope) {
            if (! $isApi($request) || config('app.debug')) {
                return null;
            }

            if ($e instanceof HttpResponseException || $e instanceof AuthenticationException) {
                return null;
            }

            return $envelope('server_error', __('api.server_error'), 500);
        });
    })->create();
