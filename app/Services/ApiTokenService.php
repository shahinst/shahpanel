<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ApiTokenService
{
    public const PREFIX = 'mp';

    /** Roles that may hold an API token at all. */
    public const ALLOWED_ROLES = [UserRole::Agent, UserRole::Seller];

    /**
     * Abilities whose endpoints sit behind `api.role:agent`. A seller can never
     * exercise these, so they are never offered to one either.
     */
    public const AGENT_ONLY_ABILITIES = ['resellers:read'];

    /**
     * Every ability the API understands, grouped and labelled for the UI.
     *
     * Only abilities an endpoint actually enforces belong here — offering a
     * permission that gates nothing just misleads whoever ticks the box.
     *
     * Pass the holder's role to get only what that role can actually use.
     *
     * @return array<string, array{label: string, agent_only: bool, abilities: array<string, string>}>
     */
    public static function abilityCatalog(?UserRole $role = null): array
    {
        $catalog = static::fullAbilityCatalog();

        if ($role === null || $role === UserRole::Agent) {
            return $catalog;
        }

        return array_filter($catalog, static fn (array $group): bool => ! $group['agent_only']);
    }

    /**
     * @return array<string, array{label: string, agent_only: bool, abilities: array<string, string>}>
     */
    protected static function fullAbilityCatalog(): array
    {
        return [
            'accounts' => [
                'label' => __('api.ability_group_accounts'),
                'agent_only' => false,
                'abilities' => [
                    'accounts:read' => __('api.ability_accounts_read'),
                    'accounts:create' => __('api.ability_accounts_create'),
                    'accounts:renew' => __('api.ability_accounts_renew'),
                    'accounts:update' => __('api.ability_accounts_update'),
                ],
            ],
            'catalog' => [
                'label' => __('api.ability_group_catalog'),
                'agent_only' => false,
                'abilities' => [
                    'catalog:read' => __('api.ability_catalog_read'),
                ],
            ],
            'wallet' => [
                'label' => __('api.ability_group_wallet'),
                'agent_only' => false,
                'abilities' => [
                    'wallet:read' => __('api.ability_wallet_read'),
                ],
            ],
            'resellers' => [
                'label' => __('api.ability_group_resellers'),
                'agent_only' => true,
                'abilities' => [
                    'resellers:read' => __('api.ability_resellers_read'),
                ],
            ],
            'stats' => [
                'label' => __('api.ability_group_stats'),
                'agent_only' => false,
                'abilities' => [
                    'stats:read' => __('api.ability_stats_read'),
                ],
            ],
        ];
    }

    /**
     * Every ability, or only those the given role can exercise.
     *
     * @return list<string>
     */
    public static function allAbilities(?UserRole $role = null): array
    {
        $all = [];

        foreach (static::abilityCatalog($role) as $group) {
            $all = array_merge($all, array_keys($group['abilities']));
        }

        return $all;
    }

    /** Human label for one ability key, falling back to the raw key. */
    public static function abilityLabel(string $ability): string
    {
        foreach (static::abilityCatalog() as $group) {
            if (isset($group['abilities'][$ability])) {
                return $group['abilities'][$ability];
            }
        }

        return $ability;
    }

    /**
     * Labels for a stored ability list. Null/empty means unrestricted.
     *
     * @param  list<string>|null  $abilities
     * @return list<string>
     */
    public static function abilityLabels(?array $abilities): array
    {
        if ($abilities === null || $abilities === [] || in_array('*', $abilities, true)) {
            return [__('api.ability_full_access')];
        }

        return array_map(static fn (string $a): string => static::abilityLabel($a), $abilities);
    }

    /**
     * Issue a token. The plaintext is returned once and never stored.
     *
     * Pass $parent when the request that mints this token is itself
     * authenticated by a token (POST /api/v1/auth/tokens). The new token is
     * then bounded by that one and can never reach further.
     *
     * @param  list<string>|null  $abilities
     * @return array{token: ApiToken, plain_text: string}
     */
    public function issue(
        User $user,
        string $name,
        ?array $abilities = null,
        ?Carbon $expiresAt = null,
        ?string $allowedIps = null,
        int $rateLimitPerMinute = 120,
        ?ApiToken $parent = null,
    ): array {
        $this->assertUserMayHoldToken($user);

        // Filtered against the holder's role, so a hand-crafted request cannot
        // store an ability that role could never exercise.
        $abilities = $this->normaliseAbilities($abilities, $user->role);

        // Escalation this closes: a token scoped to catalog:read could mint a
        // token with *more* rights than itself — asking for an ability it did
        // not hold left the list empty, an empty list meant "unrestricted", and
        // the child walked away with full access, its own IP allowlist and a
        // ten-year life that outlived revoking the parent. A child is now never
        // wider than its parent in any of the three dimensions that matter:
        // abilities, reachable addresses, lifetime.
        if ($parent !== null) {
            $abilities = $this->boundedByParent($abilities, $parent);
            $allowedIps = $this->inheritedAllowedIps($allowedIps, $parent);
            $expiresAt = $this->cappedExpiry($expiresAt, $parent);
        }

        $plain = static::PREFIX.'_'.Str::random(48);

        $token = ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => mb_substr(trim($name) !== '' ? trim($name) : 'token', 0, 100),
            'token_hash' => static::hash($plain),
            'abilities' => $abilities,
            'allowed_ips' => $allowedIps !== null && trim($allowedIps) !== '' ? trim($allowedIps) : null,
            'rate_limit_per_minute' => max(10, min(600, $rateLimitPerMinute)),
            'expires_at' => $expiresAt,
        ]);

        return ['token' => $token, 'plain_text' => $plain];
    }

    /**
     * Resolve a bearer string to a usable token, or null.
     *
     * Returns null for unknown, revoked, expired, or role/status-disqualified
     * tokens — the caller must not distinguish these to the client.
     */
    public function resolve(?string $plain): ?ApiToken
    {
        if ($plain === null) {
            return null;
        }

        $plain = trim($plain);

        if ($plain === '' || ! str_starts_with($plain, static::PREFIX.'_')) {
            return null;
        }

        $token = ApiToken::query()
            ->with('user')
            ->where('token_hash', static::hash($plain))
            ->first();

        if ($token === null || ! $token->isUsable()) {
            return null;
        }

        $user = $token->user;

        if ($user === null || $user->deleted_at !== null) {
            return null;
        }

        if ($user->status !== UserStatus::Active) {
            return null;
        }

        if (! in_array($user->role, static::ALLOWED_ROLES, true)) {
            return null;
        }

        return $token;
    }

    public function revoke(ApiToken $token): ApiToken
    {
        if (! $token->isRevoked()) {
            $token->forceFill(['revoked_at' => now()])->save();
        }

        return $token;
    }

    /**
     * Revoke every live token of one user, e.g. after a password change.
     *
     * A token lives up to 30 days on its own, so without this a stolen bearer
     * survived the very reaction a victim has — resetting their password — and
     * a token minted before two-factor was switched on kept skipping it.
     *
     * The reason is only logged: api_tokens has no column for it, and adding
     * one is a migration this change does not need.
     */
    public function revokeAllForUser(User $user, string $reason): int
    {
        $revoked = ApiToken::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        if ($revoked > 0) {
            Log::info('API tokens revoked', [
                'user_id' => $user->getKey(),
                'reason' => $reason,
                'count' => $revoked,
            ]);
        }

        return $revoked;
    }

    /** Record usage without racing other in-flight requests on the counter. */
    public function markUsed(ApiToken $token, ?string $ip): void
    {
        ApiToken::query()->whereKey($token->getKey())->update([
            'last_used_at' => now(),
            'last_used_ip' => $ip,
            'request_count' => DB::raw('request_count + 1'),
        ]);
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    protected function assertUserMayHoldToken(User $user): void
    {
        if (! in_array($user->role, static::ALLOWED_ROLES, true)) {
            throw ValidationException::withMessages([
                'user' => __('api.token_role_not_allowed'),
            ]);
        }

        if ($user->status !== UserStatus::Active) {
            throw ValidationException::withMessages([
                'user' => __('api.token_user_suspended'),
            ]);
        }
    }

    /**
     * Keep only abilities that exist and that this role can actually exercise.
     *
     * A null result means "unrestricted", which is still bounded at request
     * time by the role middleware — an unrestricted seller token gets 403 on
     * the agent-only routes exactly like a scoped one.
     *
     * An explicit list that survives nothing is refused rather than turned into
     * null: that fallback was the root of the escalation described in issue(),
     * because "ask for an ability you may not have" answered "unrestricted".
     *
     * @param  list<string>|null  $abilities
     * @return list<string>|null
     *
     * @throws ValidationException
     */
    protected function normaliseAbilities(?array $abilities, ?UserRole $role = null): ?array
    {
        if ($abilities === null || $abilities === [] || in_array('*', $abilities, true)) {
            return null;
        }

        $known = static::allAbilities($role);
        // Group wildcards ("accounts:*") are documented in docs/API.md and are
        // understood by ApiToken::can(), so they must survive the filter.
        $groups = array_keys(static::abilityCatalog($role));

        $clean = array_values(array_unique(array_filter(
            $abilities,
            static fn ($ability): bool => is_string($ability) && (
                in_array($ability, $known, true)
                || (str_ends_with($ability, ':*') && in_array(substr($ability, 0, -2), $groups, true))
            ),
        )));

        if ($clean === []) {
            throw ValidationException::withMessages([
                'abilities' => __('api.validation_failed'),
            ]);
        }

        return $clean;
    }

    /**
     * The abilities a child token may keep: the request, intersected with what
     * the parent itself holds. Only an unrestricted parent may grant anything.
     *
     * @param  list<string>|null  $abilities
     * @return list<string>|null
     *
     * @throws ValidationException
     */
    protected function boundedByParent(?array $abilities, ApiToken $parent): ?array
    {
        $parentAbilities = $parent->abilities;

        if ($parentAbilities === null || $parentAbilities === [] || in_array('*', $parentAbilities, true)) {
            return $abilities;
        }

        // "Give me everything" from a scoped parent means "give me exactly what
        // the parent has" — never null, which would mean unrestricted.
        if ($abilities === null) {
            return array_values(array_unique(array_filter(
                $parentAbilities,
                static fn ($ability): bool => is_string($ability) && $ability !== '',
            )));
        }

        $granted = array_values(array_filter(
            $abilities,
            static fn (string $ability): bool => $parent->can($ability),
        ));

        if ($granted === []) {
            throw ValidationException::withMessages([
                'abilities' => __('api.ability_missing', ['ability' => implode(', ', $abilities)]),
            ]);
        }

        return $granted;
    }

    /**
     * A child must not be reachable from more addresses than its parent.
     *
     * Exact CIDR intersection is not worth the complexity here, so a parent
     * that has an allowlist hands its own list down; a narrower list for the
     * child can still be set from the panel, where there is no parent token.
     */
    protected function inheritedAllowedIps(?string $allowedIps, ApiToken $parent): ?string
    {
        $parentIps = trim((string) $parent->allowed_ips);

        return $parentIps !== '' ? $parentIps : $allowedIps;
    }

    /** A child expires no later than its parent, so the chain really does end. */
    protected function cappedExpiry(?Carbon $expiresAt, ApiToken $parent): ?Carbon
    {
        $parentExpiry = $parent->expires_at;

        if ($parentExpiry === null) {
            return $expiresAt;
        }

        return $expiresAt === null || $expiresAt->greaterThan($parentExpiry)
            ? $parentExpiry->copy()
            : $expiresAt;
    }
}
