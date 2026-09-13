<?php

namespace App\Listeners;

use App\Services\IpGuardService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;

/**
 * Hooks the framework's own auth events, so every login path the panel has —
 * the unified form and the per-portal ones — is covered without touching the
 * controller.
 */
class RecordLoginOutcome
{
    public function __construct(protected IpGuardService $guard) {}

    public function handleFailed(Failed $event): void
    {
        $request = request();

        $this->guard->recordFailure(
            (string) $request->ip(),
            $this->username($event->credentials),
            $request->userAgent(),
            $request->path(),
        );
    }

    public function handleLogin(Login $event): void
    {
        $request = request();

        $this->guard->recordSuccess(
            (string) $request->ip(),
            $event->user->username ?? null,
            $request->userAgent(),
            $request->path(),
        );
    }

    /** @param array<string, mixed> $credentials */
    protected function username(array $credentials): ?string
    {
        foreach (['username', 'email', 'login'] as $key) {
            if (isset($credentials[$key]) && is_string($credentials[$key])) {
                return $credentials[$key];
            }
        }

        return null;
    }
}
