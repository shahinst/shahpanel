<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Support\Str;

class PortalLinkService
{
    public function ttlMinutes(): int
    {
        return max(1, (int) config('vpnpanel.portal_link_ttl_minutes', 5));
    }

    public function isActive(Account $account): bool
    {
        return filled($account->portal_token)
            && $account->portal_token_expires_at !== null
            && $account->portal_token_expires_at->isFuture();
    }

    public function publicUrlIfActive(Account $account): ?string
    {
        if (! $this->isActive($account)) {
            return null;
        }

        return route('portal.show', $account->portal_token);
    }

    public function issue(Account $account): string
    {
        $account->update([
            'portal_token' => Str::random((int) config('vpnpanel.portal_token_length', 32)),
            'portal_token_expires_at' => now()->addMinutes($this->ttlMinutes()),
        ]);

        return route('portal.show', $account->fresh()->portal_token);
    }

    public function url(Account $account): string
    {
        return route('portal.show', $account->portal_token);
    }
}
