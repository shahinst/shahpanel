<?php

namespace App\Http\Middleware;

use App\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleImpersonationSession
{
    public function __construct(
        protected ImpersonationService $impersonation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }

        try {
            if ($this->impersonation->isImpersonating() && $this->impersonation->isExpired()) {
                $result = $this->impersonation->leave();

                return redirect()
                    ->to($result['returnUrl'])
                    ->with('warning', __('security.impersonation_expired', [
                        'minutes' => (int) config('shahpanel.impersonation_ttl_minutes', 3),
                    ]));
            }

            if ($this->impersonation->isImpersonating()) {
                $this->impersonation->touchExpiry();
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $next($request);
    }
}
