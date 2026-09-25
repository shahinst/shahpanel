<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Support\MarzbanDetailResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * محدودسازی نرخ نمای مرزبان.
 *
 * چرا میان‌افزار اختصاصی و نه throttle پیش‌فرض لاراول: پاسخ ۴۲۹ پیش‌فرض
 * {"message": "..."} است و هندلرهای withExceptions پروژه فقط api/v1/* را پوشش
 * می‌دهند. ربات‌های مرزبان‌محور کلید detail را می‌خوانند، پس شکل خطا باید در
 * همین مسیر هم یکدست بماند.
 *
 * سطح‌ها با پارامتر مسیر داده می‌شوند، چون /api/admin/token ذاتاً پرتماس‌تر از
 * بقیه است: ویزویز توکن را کش نمی‌کند و پیش از «هر» عملیات یک بار لاگین می‌کند.
 */
class MarzbanThrottle
{
    /** سقف پایین‌دست، تا یک پیکربندی اشتباه ربات را قفل نکند. */
    protected const FLOOR = 30;

    public function handle(Request $request, Closure $next, int|string $perMinute = 120, string $bucket = 'api'): Response
    {
        $max = max(self::FLOOR, (int) $perMinute);
        $key = 'marzban-throttle:'.$bucket.':'.$this->identity($request);

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $seconds = RateLimiter::availableIn($key);

            return MarzbanDetailResponse::make(__('marzban.rate_limited', ['seconds' => $seconds]), 429)
                ->header('Retry-After', (string) $seconds);
        }

        RateLimiter::hit($key, 60);

        return $next($request);
    }

    /**
     * پس از احراز هویت، توکن شناسهٔ دقیق‌تری از IP است (چند ربات می‌توانند پشت
     * یک NAT باشند). روی مسیر توکن هنوز احراز هویتی نشده، پس فقط IP.
     *
     * نام کاربری عمداً از این شناسه حذف شده: وقتی در کلید بود، هر نام کاربری
     * سطل تازهٔ خودش را می‌گرفت و یک IP می‌توانست بی‌نهایت نام را امتحان کند
     * (شمارش حساب‌ها). سقف حالا واقعاً «به ازای IP» است؛ شمارش شکست‌های
     * TokenController هم جداگانه به ازای (نام کاربری، IP) سر جایش می‌ماند.
     */
    protected function identity(Request $request): string
    {
        $token = $request->attributes->get('api_token');

        if ($token instanceof ApiToken) {
            return 'token:'.$token->getKey();
        }

        return 'ip:'.sha1((string) $request->ip());
    }
}
