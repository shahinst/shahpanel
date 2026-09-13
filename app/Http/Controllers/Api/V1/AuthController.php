<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserStatus;
use App\Http\Controllers\Api\V1\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\User;
use App\Services\ApiTokenService;
use App\Services\TwoFactorService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        protected ApiTokenService $tokens,
        protected TwoFactorService $twoFactor,
        protected WalletService $wallets,
    ) {}

    /**
     * Exchange panel credentials for a bearer token.
     *
     * This is the one endpoint a reseller uses by hand when wiring their bot.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'two_fa_code' => ['nullable', 'string', 'max:12'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        // Throttle by username+IP so one bot cannot brute force another account.
        $key = 'api-login:'.sha1(mb_strtolower($data['username']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $this->fail(
                'login_throttled',
                __('api.login_throttled', ['seconds' => RateLimiter::availableIn($key)]),
                429,
            );
        }

        $user = User::query()
            ->whereNull('deleted_at')
            ->where('username', $data['username'])
            ->first();

        if ($user === null || ! Hash::check($data['password'], (string) $user->password)) {
            RateLimiter::hit($key, 900);

            return $this->fail('login_failed', __('api.login_failed'), 401);
        }

        if (! in_array($user->role, ApiTokenService::ALLOWED_ROLES, true)) {
            RateLimiter::hit($key, 900);

            return $this->fail('login_role_not_allowed', __('api.login_role_not_allowed'), 403);
        }

        if ($user->status !== UserStatus::Active) {
            RateLimiter::hit($key, 900);

            return $this->fail('login_suspended', __('api.login_suspended'), 403);
        }

        if ($this->twoFactor->isEnabled($user)) {
            $code = trim((string) ($data['two_fa_code'] ?? ''));

            if ($code === '' || ! $this->twoFactor->verify($user, $code)) {
                RateLimiter::hit($key, 900);

                return $this->fail('two_fa_required', __('api.login_failed'), 401, [
                    'two_fa_required' => true,
                ]);
            }
        }

        RateLimiter::clear($key);

        $issued = $this->tokens->issue(
            $user,
            $data['device_name'] ?? 'telegram-bot',
            null,
            isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null,
        );

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->saveQuietly();

        return $this->ok([
            'token' => $issued['plain_text'],
            'token_id' => $issued['token']->id,
            'expires_at' => optional($issued['token']->expires_at)->toIso8601String(),
            'user' => $this->userPayload($user),
        ], status: 201);
    }

    /** Who this token belongs to, plus wallet balance for the bot's header. */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $request->attributes->get('api_token');

        return $this->ok([
            'user' => $this->userPayload($user),
            'token' => $token instanceof ApiToken ? [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities ?? ['*'],
                'expires_at' => optional($token->expires_at)->toIso8601String(),
                'last_used_at' => optional($token->last_used_at)->toIso8601String(),
                'rate_limit_per_minute' => $token->rate_limit_per_minute,
            ] : null,
        ]);
    }

    /** Revoke the token used for this very request. */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->attributes->get('api_token');

        if ($token instanceof ApiToken) {
            $this->tokens->revoke($token);
        }

        return $this->ok(['revoked' => true]);
    }

    public function tokens(Request $request): JsonResponse
    {
        $tokens = ApiToken::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (ApiToken $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'abilities' => $t->abilities ?? ['*'],
                'allowed_ips' => $t->allowed_ips,
                'rate_limit_per_minute' => $t->rate_limit_per_minute,
                'request_count' => $t->request_count,
                'last_used_at' => optional($t->last_used_at)->toIso8601String(),
                'expires_at' => optional($t->expires_at)->toIso8601String(),
                'revoked_at' => optional($t->revoked_at)->toIso8601String(),
                'created_at' => optional($t->created_at)->toIso8601String(),
            ])
            ->all();

        return $this->ok($tokens);
    }

    /** Mint an extra, optionally narrower token (e.g. a read-only bot). */
    public function createToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['nullable', 'array'],
            'abilities.*' => ['string', 'max:64'],
            'allowed_ips' => ['nullable', 'string', 'max:512'],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:10', 'max:600'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $issued = $this->tokens->issue(
            $request->user(),
            $data['name'],
            $data['abilities'] ?? null,
            isset($data['expires_in_days']) ? now()->addDays((int) $data['expires_in_days']) : null,
            $data['allowed_ips'] ?? null,
            (int) ($data['rate_limit_per_minute'] ?? 120),
        );

        return $this->ok([
            'token' => $issued['plain_text'],
            'token_id' => $issued['token']->id,
            'abilities' => $issued['token']->abilities ?? ['*'],
            'expires_at' => optional($issued['token']->expires_at)->toIso8601String(),
        ], status: 201);
    }

    public function revokeToken(Request $request, int $token): JsonResponse
    {
        $model = ApiToken::query()
            ->where('user_id', $request->user()->id)
            ->find($token);

        if ($model === null) {
            return $this->fail('not_found', __('api.not_found'), 404);
        }

        $this->tokens->revoke($model);

        return $this->ok(['revoked' => true, 'id' => $model->id]);
    }

    /** @return array<string, mixed> */
    protected function userPayload(User $user): array
    {
        $wallet = $this->wallets->getOrCreateWallet($user);

        return [
            'id' => $user->id,
            'username' => $user->username,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role->value,
            'status' => $user->status->value,
            'parent_id' => $user->parent_id,
            'telegram_id' => $user->telegram_id,
            'wallet' => [
                'balance' => (string) $wallet->balance,
                'locked_balance' => (string) $wallet->locked_balance,
                'currency' => $wallet->currency,
            ],
        ];
    }
}
