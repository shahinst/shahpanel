<?php

namespace App\Http\Middleware;

use App\Services\ApiTokenService;
use App\Support\MarzbanDetailResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * احراز هویت نمای مرزبان.
 *
 * عیناً همان توکن mp_ پنل را می‌پذیرد (ApiTokenService::resolve که ادمین، کاربر
 * غیرفعال، توکن ابطال‌شده و منقضی را رد می‌کند) و تنها تفاوتش با ApiAuthenticate
 * شکل پاسخ خطاست: مرزبان با کلید detail خطا می‌دهد، نه با پوشش ok/error پنل.
 *
 * هیچ دسترسی‌ای گسترده نمی‌شود: کاربر روی درخواست نشانده می‌شود و کنترلرها
 * همه‌جا از Account::ownedByHierarchy استفاده می‌کنند، پس یک نماینده هرگز
 * اکانت نمایندهٔ دیگر را نمی‌بیند.
 */
class MarzbanAuthenticate
{
    public function __construct(protected ApiTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->tokens->resolve($this->bearer($request));

        if ($token === null) {
            // پیام مرزبان برای توکن نامعتبر؛ همان متن تا ربات‌هایی که روی آن
            // شرط گذاشته‌اند سردرگم نشوند.
            return MarzbanDetailResponse::make('Could not validate credentials', 401);
        }

        if (! $token->allowsIp($request->ip())) {
            return MarzbanDetailResponse::make(__('marzban.ip_not_allowed'), 403);
        }

        $user = $token->user;

        if ($user === null) {
            return MarzbanDetailResponse::make('Could not validate credentials', 401);
        }

        $request->setUserResolver(static fn () => $user);
        auth()->setUser($user);
        $request->attributes->set('api_token', $token);

        $this->tokens->markUsed($token, $request->ip());

        return $next($request);
    }

    protected function bearer(Request $request): ?string
    {
        $header = trim((string) $request->header('Authorization'));

        if ($header !== '' && preg_match('/^Bearer\s+(.+)$/i', $header, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }
}
