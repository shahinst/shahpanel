<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
            'ip.guard' => \App\Http\Middleware\BlockBruteForcedIps::class,
        ];

        if (class_exists(\App\Http\Middleware\RestrictAdminByIp::class)) {
            $aliases['admin.ip'] = \App\Http\Middleware\RestrictAdminByIp::class;
        }

        $middleware->alias($aliases);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
