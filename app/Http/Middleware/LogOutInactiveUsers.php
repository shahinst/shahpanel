<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user's status used to be checked only at sign-in, so suspending (or
 * deleting) someone left their open sessions and "remember me" cookie working
 * until they logged out themselves. Every request now ends such a session.
 */
class LogOutInactiveUsers
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->status !== UserStatus::Active) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => __('auth.suspended')], 401);
            }

            return redirect()->route('login')->with('error', __('auth.suspended'));
        }

        return $next($request);
    }
}
