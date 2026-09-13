<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-token rate limiting. Falls back to per-IP for unauthenticated routes
 * such as login, where `$fallbackPerMinute` applies.
 */
class ApiThrottle
{
    public function handle(Request $request, Closure $next, int|string $fallbackPerMinute = 60): Response
    {
        $token = $request->attributes->get('api_token');

        if ($token instanceof ApiToken) {
            $key = 'api-token:'.$token->getKey();
            $max = max(10, (int) $token->rate_limit_per_minute);
        } else {
            $key = 'api-ip:'.sha1((string) $request->ip());
            $max = max(5, (int) $fallbackPerMinute);
        }

        if (RateLimiter::tooManyAttempts($key, $max)) {
            $retry = RateLimiter::availableIn($key);

            return response()->json([
                'ok' => false,
                'error' => [
                    'code' => 'rate_limited',
                    'message' => __('api.rate_limited', ['seconds' => $retry]),
                ],
            ], 429)->withHeaders([
                'Retry-After' => $retry,
                'X-RateLimit-Limit' => $max,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($key, 60);

        $response = $next($request);

        if (method_exists($response, 'withHeaders')) {
            $response->withHeaders([
                'X-RateLimit-Limit' => $max,
                'X-RateLimit-Remaining' => max(0, RateLimiter::remaining($key, $max)),
            ]);
        }

        return $response;
    }
}
