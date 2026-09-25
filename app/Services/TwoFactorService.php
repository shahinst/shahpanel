<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

class TwoFactorService
{
    /**
     * How long the last accepted TOTP timestamp is remembered.
     *
     * Replay protection only has to outlive the code itself: a six digit code
     * stays valid for one step plus the tolerance either side, so a couple of
     * minutes is enough. That is why this lives in the cache — no new users
     * column, and therefore no migration, is needed for it.
     */
    protected const REPLAY_MEMORY_SECONDS = 180;

    protected const REPLAY_CACHE_PREFIX = 'two-factor-last-ts:';

    public function __construct(
        protected Google2FA $google2fa,
        protected ApiTokenService $tokens,
    ) {}

    public function isEnabled(User $user): bool
    {
        try {
            return filled($user->two_fa_secret);
        } catch (Throwable) {
            return false;
        }
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    public function otpAuthUrl(User $user, string $secret): string
    {
        $issuer = config('app.name', 'سامانه امور مشتریان');
        $label = $issuer.':'.$user->username;

        return $this->google2fa->getQRCodeUrl($issuer, $label, $secret);
    }

    public function verify(User $user, string $code): bool
    {
        if (! $this->isEnabled($user)) {
            return false;
        }

        return $this->acceptOnce(
            (string) $user->two_fa_secret,
            $code,
            self::REPLAY_CACHE_PREFIX.$user->getKey(),
        );
    }

    public function verifySecret(string $secret, string $code): bool
    {
        // Keyed by the secret because this runs while enabling, when nothing is
        // stored on the user yet.
        return $this->acceptOnce(
            $secret,
            $code,
            self::REPLAY_CACHE_PREFIX.'secret:'.hash('sha256', $secret),
        );
    }

    public function enable(User $user, string $secret, string $code): bool
    {
        if (! $this->verifySecret($secret, $code)) {
            return false;
        }

        $user->forceFill(['two_fa_secret' => $secret])->save();

        // Only after the secret is persisted. API tokens issued earlier never
        // see two-factor at all: the Marzban façade refuses to mint a new token
        // for a two-factor account, but one minted the day before kept working
        // for up to 30 days and walked straight past the layer the user just
        // switched on.
        $this->tokens->revokeAllForUser($user, 'two_factor_enabled');

        return true;
    }

    /**
     * Verify a code and burn it.
     *
     * verifyKey() accepts any code inside the window, so the same six digits
     * kept working for roughly 90 seconds — long enough for a code read over a
     * shoulder, off a notification shade or through a phishing page to be used
     * for a second login or to switch two-factor off entirely. verifyKeyNewer()
     * returns the timestamp it matched; remembering it makes that timestamp and
     * anything older unusable, so each code works exactly once.
     */
    protected function acceptOnce(string $secret, string $code, string $cacheKey): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if ($secret === '' || $code === '') {
            return false;
        }

        $last = Cache::get($cacheKey);

        $accepted = $this->google2fa->verifyKeyNewer($secret, $code, is_int($last) ? $last : null);

        if ($accepted === false) {
            return false;
        }

        // The accepted step is what must be remembered; fall back to the current
        // step if a library version answers with a plain true.
        $stamp = is_int($accepted) ? $accepted : (int) floor(time() / 30);

        Cache::put($cacheKey, $stamp, now()->addSeconds(self::REPLAY_MEMORY_SECONDS));

        return true;
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_fa_secret' => null])->save();
    }
}
