<?php

namespace App\Services;

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;
use Throwable;

class TwoFactorService
{
    public function __construct(
        protected Google2FA $google2fa,
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

        return $this->google2fa->verifyKey((string) $user->two_fa_secret, preg_replace('/\s+/', '', $code) ?? '');
    }

    public function verifySecret(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, preg_replace('/\s+/', '', $code) ?? '');
    }

    public function enable(User $user, string $secret, string $code): bool
    {
        if (! $this->verifySecret($secret, $code)) {
            return false;
        }

        $user->forceFill(['two_fa_secret' => $secret])->save();

        return true;
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_fa_secret' => null])->save();
    }
}
