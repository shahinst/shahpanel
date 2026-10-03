<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ImpersonationController extends Controller
{
    public function __construct(
        protected ImpersonationService $impersonation,
    ) {}

    public function start(Request $request, User $user): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        // Each route impersonates one kind of user. Without this check an admin
        // granted only the clients section could post an agent's id to
        // clients/{user}/impersonate and sit in that agent's panel.
        $expectedRole = $this->roleForRoute((string) $request->route()?->getName());
        abort_if($expectedRole !== null && $user->role !== $expectedRole, 404);

        Gate::authorize('impersonate', $user);

        $this->impersonation->start($actor, $user);

        return redirect()
            ->route($this->dashboardRoute($user->role))
            ->with('success', __('security.impersonation_started', ['name' => $user->full_name]));
    }

    public function leave(Request $request): RedirectResponse
    {
        $result = $this->impersonation->leave();

        return redirect()
            ->to($result['returnUrl'])
            ->with('success', __('security.impersonation_ended'));
    }

    protected function roleForRoute(string $routeName): ?UserRole
    {
        return match (true) {
            str_ends_with($routeName, 'clients.impersonate') => UserRole::Client,
            str_ends_with($routeName, 'sellers.impersonate') => UserRole::Seller,
            str_ends_with($routeName, 'users.impersonate') => UserRole::Agent,
            default => null,
        };
    }

    protected function dashboardRoute(UserRole $role): string
    {
        return match ($role) {
            UserRole::Admin => 'admin.dashboard',
            UserRole::Agent => 'agent.dashboard',
            UserRole::Seller => 'seller.dashboard',
            UserRole::Client => 'client.dashboard',
            default => 'home',
        };
    }
}
