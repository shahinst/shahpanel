<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\EndUserService;
use App\Support\PortalPaths;
use Illuminate\Support\Facades\Auth;

class ImpersonationService
{
    public const SESSION_IMPERSONATOR_ID = 'impersonator_id';

    public const SESSION_IMPERSONATOR_ROLE = 'impersonator_role';

    public const SESSION_RETURN_URL = 'impersonator_return_url';

    public const SESSION_EXPIRES_AT = 'impersonation_expires_at';

    /** Absolute deadline that is never extended — enforces a hard maximum session length. */
    public const SESSION_HARD_EXPIRES_AT = 'impersonation_hard_expires_at';

    /**
     * @return array{user: User, returnUrl: string}
     */
    public function leave(): array
    {
        $impersonatorId = session(self::SESSION_IMPERSONATOR_ID);

        if (! $impersonatorId) {
            abort(403, __('auth.unauthorized'));
        }

        $storedReturnUrl = session(self::SESSION_RETURN_URL);

        session()->forget([
            self::SESSION_IMPERSONATOR_ID,
            self::SESSION_IMPERSONATOR_ROLE,
            self::SESSION_RETURN_URL,
            self::SESSION_EXPIRES_AT,
            self::SESSION_HARD_EXPIRES_AT,
        ]);

        /** @var User|null $impersonator */
        $impersonator = User::query()->find($impersonatorId);

        if ($impersonator === null) {
            Auth::logout();

            abort(403, __('auth.unauthorized'));
        }

        Auth::login($impersonator);
        session()->regenerate();

        return [
            'user' => $impersonator,
            'returnUrl' => $this->resolveReturnUrl($impersonator, $storedReturnUrl),
        ];
    }

    public function canImpersonate(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return false;
        }

        if ($this->isImpersonating()) {
            return false;
        }

        if ($actor->role === UserRole::Admin) {
            return in_array($target->role, [UserRole::Agent, UserRole::Seller, UserRole::Client], true);
        }

        if ($actor->role === UserRole::Agent && $target->role === UserRole::Seller) {
            return $this->agentOwnsSeller($actor, $target);
        }

        if (in_array($actor->role, [UserRole::Agent, UserRole::Seller], true) && $target->role === UserRole::Client) {
            return app(EndUserService::class)->canViewerManageClient($actor, $target);
        }

        return false;
    }

    public function start(User $actor, User $target): void
    {
        if (! $this->canImpersonate($actor, $target)) {
            abort(403, __('auth.unauthorized'));
        }

        $returnUrl = $this->defaultReturnUrlForActor($actor);
        $previous = url()->previous();

        if ($this->isSafeReturnUrlForActor($previous, $actor)) {
            $returnUrl = $this->normalizePortalPath($previous);
        }

        Auth::login($target);
        session()->regenerate();

        session([
            self::SESSION_IMPERSONATOR_ID => $actor->id,
            self::SESSION_IMPERSONATOR_ROLE => $actor->role->value,
            self::SESSION_RETURN_URL => $returnUrl,
            self::SESSION_EXPIRES_AT => $this->expiryTimestamp(),
        ]);

        // Agents get a hard, non-extendable cap (login to a seller for N minutes
        // only). Admin support sessions keep the sliding idle timeout.
        if ($actor->role === UserRole::Agent) {
            session([self::SESSION_HARD_EXPIRES_AT => $this->expiryTimestamp()]);
        }
    }

    public function touchExpiry(): void
    {
        if (! $this->isImpersonating()) {
            return;
        }

        session([self::SESSION_EXPIRES_AT => $this->expiryTimestamp()]);
    }

    public function isExpired(): bool
    {
        // Absolute deadline wins: the session can never live longer than the TTL
        // from when it started, regardless of activity.
        $hardExpiresAt = session(self::SESSION_HARD_EXPIRES_AT);

        if (is_int($hardExpiresAt) && now()->timestamp >= $hardExpiresAt) {
            return true;
        }

        $expiresAt = session(self::SESSION_EXPIRES_AT);

        return is_int($expiresAt) && now()->timestamp >= $expiresAt;
    }

    protected function expiryTimestamp(): int
    {
        $minutes = max(1, (int) config('vpnpanel.impersonation_ttl_minutes', 3));

        return now()->addMinutes($minutes)->timestamp;
    }

    protected function resolveReturnUrl(User $impersonator, mixed $stored): string
    {
        if (is_string($stored) && $stored !== '' && $this->isSafeReturnUrlForActor($stored, $impersonator)) {
            return $this->normalizePortalPath($stored);
        }

        return $this->defaultReturnUrlForActor($impersonator);
    }

    protected function isSafeReturnUrlForActor(string $url, User $actor): bool
    {
        if ($url === '' || (! str_starts_with($url, 'http') && ! str_starts_with($url, '/'))) {
            return false;
        }

        $parsed = parse_url($url);

        if ($parsed === false) {
            return false;
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($appHost && ! empty($parsed['host']) && strcasecmp((string) $parsed['host'], (string) $appHost) !== 0) {
            return false;
        }

        $path = (string) ($parsed['path'] ?? '/');
        $segment = trim(explode('/', ltrim($path, '/'))[0] ?? '', '/');

        if ($segment === '') {
            return false;
        }

        $allowedSegment = PortalPaths::slugForRole($actor->role);

        return $segment === $allowedSegment || $segment === $actor->role->value;
    }

    protected function normalizePortalPath(string $url): string
    {
        $parsed = parse_url($url);

        if (! is_array($parsed) || empty($parsed['path'])) {
            return $url;
        }

        $path = $parsed['path'];
        $paths = PortalPaths::all();

        foreach (PortalPaths::legacyDefaults() as $legacyRole) {
            $currentSlug = $paths[$legacyRole] ?? $legacyRole;

            if ($currentSlug === $legacyRole) {
                continue;
            }

            if ($path === '/'.$legacyRole) {
                $path = '/'.$currentSlug;

                break;
            }

            if (str_starts_with($path, '/'.$legacyRole.'/')) {
                $path = '/'.$currentSlug.substr($path, strlen($legacyRole) + 1);

                break;
            }
        }

        $query = isset($parsed['query']) && $parsed['query'] !== '' ? '?'.$parsed['query'] : '';

        if (! empty($parsed['host'])) {
            $scheme = isset($parsed['scheme']) ? $parsed['scheme'].'://' : '';
            $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';

            return $scheme.$parsed['host'].$port.$path.$query;
        }

        return $path.$query;
    }

    protected function agentOwnsSeller(User $agent, User $seller): bool
    {
        if ($seller->role !== UserRole::Seller) {
            return false;
        }

        if ((int) $seller->parent_id === (int) $agent->id) {
            return true;
        }

        return in_array((int) $seller->id, User::subtreeUserIds($agent), true);
    }

    protected function defaultReturnUrlForActor(User $actor): string
    {
        return match ($actor->role) {
            UserRole::Admin => route('admin.dashboard'),
            UserRole::Agent => route('agent.sellers.index'),
            UserRole::Seller => route('seller.dashboard'),
            UserRole::Client => route('client.dashboard'),
            default => route('home'),
        };
    }

    public function isImpersonating(): bool
    {
        return session()->has(self::SESSION_IMPERSONATOR_ID);
    }

    public function impersonator(): ?User
    {
        $id = session(self::SESSION_IMPERSONATOR_ID);

        if (! $id) {
            return null;
        }

        return User::query()->find($id);
    }
}
