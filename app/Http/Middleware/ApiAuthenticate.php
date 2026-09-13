<?php

namespace App\Http\Middleware;

use App\Services\ApiTokenService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the bearer token, binds the owning user onto the request, and
 * refuses anything that is not a live token held by an active reseller.
 */
class ApiAuthenticate
{
    public function __construct(protected ApiTokenService $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $this->bearer($request);

        if ($plain === null) {
            return $this->deny('unauthenticated', __('api.missing_token'), 401);
        }

        $token = $this->tokens->resolve($plain);

        if ($token === null) {
            return $this->deny('unauthenticated', __('api.invalid_token'), 401);
        }

        if (! $token->allowsIp($request->ip())) {
            return $this->deny('ip_not_allowed', __('api.ip_not_allowed'), 403);
        }

        $user = $token->user;

        // Bind for controllers, policies and anything calling auth()->user().
        $request->setUserResolver(static fn () => $user);
        auth()->setUser($user);
        $request->attributes->set('api_token', $token);

        $this->tokens->markUsed($token, $request->ip());

        return $next($request);
    }

    protected function bearer(Request $request): ?string
    {
        $header = trim((string) $request->header('Authorization'));

        if ($header !== '' && preg_match('/^Bearer\s+(.+)$/i', $header, $m) === 1) {
            return trim($m[1]);
        }

        // Telegram bot frameworks often find a query/body field easier.
        $fallback = $request->input('api_token');

        return is_string($fallback) && trim($fallback) !== '' ? trim($fallback) : null;
    }

    protected function deny(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }
}
