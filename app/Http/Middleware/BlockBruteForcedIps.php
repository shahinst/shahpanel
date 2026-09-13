<?php

namespace App\Http\Middleware;

use App\Services\IpGuardService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the login screen to an address that has been blocked.
 *
 * Deliberately narrow: only the routes this middleware is attached to are
 * refused, so a shared carrier address losing the login page does not take
 * the customer portal or subscription links down with it.
 */
class BlockBruteForcedIps
{
    public function __construct(protected IpGuardService $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ip = (string) $request->ip();
        $block = $this->guard->activeBlock($ip);

        if ($block === null) {
            return $next($request);
        }

        // Still knocking after being told no: let the kernel handle it.
        $block->increment('attempts');

        if ($block->attempts >= IpGuardService::ESCALATE_AFTER) {
            $this->guard->escalateToFirewall($block);
        }

        $retryAfter = $block->expires_at !== null
            ? max(1, now()->diffInSeconds($block->expires_at, false))
            : 3600;

        $message = __('loginfw.ip_blocked', [
            'minutes' => (int) ceil($retryAfter / 60),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'ip_blocked', 'message' => $message],
            ], 429);
        }

        return response($message, 429)
            ->header('Retry-After', (string) $retryAfter)
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
