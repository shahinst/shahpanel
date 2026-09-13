<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
    ): array {
        $this->assertUserMayHoldToken($user);

        // Filtered against the holder's role, so a hand-crafted request cannot
        // store an ability that role could never exercise.
        $abilities = $this->normaliseAbilities($abilities, $user->role);

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
     * @param  list<string>|null  $abilities
     * @return list<string>|null
     */
    protected function normaliseAbilities(?array $abilities, ?UserRole $role = null): ?array
    {
        if ($abilities === null || $abilities === [] || in_array('*', $abilities, true)) {
            return null;
        }

        $known = static::allAbilities($role);

        $clean = array_values(array_unique(array_filter(
            $abilities,
            static fn ($ability): bool => is_string($ability) && in_array($ability, $known, true),
        )));

        return $clean === [] ? null : $clean;
    }
}
