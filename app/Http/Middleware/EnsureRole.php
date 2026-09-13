<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * @param  string  ...$roles  Allowed role values (admin|agent|seller|client).
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $allowed = array_map(
            static fn (string $role): UserRole => UserRole::from($role),
            $roles
        );

        if (in_array($user->role, $allowed, true)) {
            return $next($request);
        }

        $impersonation = app(ImpersonationService::class);

        if ($impersonation->isImpersonating()) {
            return redirect()
                ->route($this->dashboardRoute($user->role))
                ->with('warning', __('security.impersonation_stay_in_panel'));
        }

        $dashboard = $this->dashboardRoute($user->role);

        if ($dashboard !== null) {
            return redirect()
                ->route($dashboard)
                ->with('warning', __('auth.wrong_portal'));
        }

        abort(403, __('auth.unauthorized'));
    }

    protected function dashboardRoute(UserRole $role): ?string
    {
        return match ($role) {
            UserRole::Admin => 'admin.dashboard',
            UserRole::Agent => 'agent.dashboard',
            UserRole::Seller => 'seller.dashboard',
            UserRole::Client => 'client.dashboard',
            default => null,
        };
    }
}
