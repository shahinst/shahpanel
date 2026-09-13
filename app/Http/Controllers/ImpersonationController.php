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
