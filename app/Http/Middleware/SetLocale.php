<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the request language.
 *
 * Order: the signed-in user's saved preference, then the guest session, then
 * the app default. The user column wins so that someone who set English on
 * their laptop still gets English on their phone.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_keys(config('locales.supported', []));
        $sessionKey = (string) config('locales.session_key', 'app_locale');

        $locale = null;

        $user = $request->user();
        if ($user !== null && filled($user->locale ?? null) && in_array($user->locale, $supported, true)) {
            $locale = $user->locale;
        }

        if ($locale === null) {
            $fromSession = $request->session()->get($sessionKey);
            if (is_string($fromSession) && in_array($fromSession, $supported, true)) {
                $locale = $fromSession;
            }
        }

        if ($locale === null) {
            $default = (string) config('app.locale', 'fa');
            $locale = in_array($default, $supported, true) ? $default : 'fa';
        }

        App::setLocale($locale);

        return $next($request);
    }
}
