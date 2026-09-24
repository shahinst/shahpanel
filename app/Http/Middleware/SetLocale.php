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
 *
 * Runs in the "api" group too, where there is no session and no resolved user
 * yet — hence the guards below. There Accept-Language is the only signal, which
 * is what an API consumer serving non-Persian users actually needs.
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

        // گروه api هیچ سشنی ندارد؛ بدون این شرط، خواندن سشن هر درخواست API را
        // با خطای «Session store not set on request» می‌انداخت.
        if ($locale === null && $request->hasSession()) {
            $fromSession = $request->session()->get($sessionKey);
            if (is_string($fromSession) && in_array($fromSession, $supported, true)) {
                $locale = $fromSession;
            }
        }

        // هیچ انتخاب ذخیره‌شده‌ای نیست: زبان خودِ مرورگر ملاک است، نه پیش‌فرض پنل.
        // بدون این، یک بازدیدکنندهٔ انگلیسی‌زبان صفحهٔ ورود را فارسی و راست‌چین می‌بیند
        // و تازه باید دنبال پرچم بگردد.
        if ($locale === null) {
            foreach ($request->getLanguages() as $browserLocale) {
                $candidate = strtolower(substr(str_replace('_', '-', $browserLocale), 0, 2));

                if (in_array($candidate, $supported, true)) {
                    $locale = $candidate;
                    break;
                }
            }
        }

        // مرورگر هم چیز قابل‌فهمی نگفت.
        if ($locale === null) {
            $default = (string) config('app.locale', 'fa');
            $locale = in_array($default, $supported, true) ? $default : 'fa';
        }

        App::setLocale($locale);

        return $next($request);
    }
}
