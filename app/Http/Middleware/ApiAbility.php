<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route behind one of the token's abilities, e.g. `ability:accounts:create`.
 */
class ApiAbility
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $token = $request->attributes->get('api_token');

        if (! $token instanceof ApiToken) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'unauthenticated', 'message' => __('api.missing_token')],
            ], 401);
        }

        foreach ($abilities as $ability) {
            if ($token->can($ability)) {
                return $next($request);
            }
        }

        return response()->json([
            'ok' => false,
            'error' => [
                'code' => 'ability_missing',
                'message' => __('api.ability_missing', ['ability' => implode(', ', $abilities)]),
            ],
        ], 403);
    }
}
