<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON counterpart of the panel's `role` middleware, e.g. `api.role:agent`.
 */
class ApiRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'unauthenticated', 'message' => __('api.missing_token')],
            ], 401);
        }

        foreach ($roles as $role) {
            $expected = UserRole::tryFrom($role);

            if ($expected !== null && $user->role === $expected) {
                return $next($request);
            }
        }

        return response()->json([
            'ok' => false,
            'error' => [
                'code' => 'role_forbidden',
                'message' => __('api.role_forbidden'),
            ],
        ], 403);
    }
}
