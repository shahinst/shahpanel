<?php

namespace App\Services;

use App\Models\BlockedIp;
use App\Models\IpWhitelist;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Brute-force policy for the login screen.
 *
 * A wrong password costs the address a strike. On the third strike inside the
 * window the address loses the login page for an hour — the customer portal
 * and subscription links stay up, because Iranian carriers put many users
 * behind one address and a full block would take innocents down with it.
 *
 * An address that keeps hammering after that is escalated to the OS firewall,
 * where the traffic stops costing PHP anything at all.
 */
class IpGuardService
{
    /** Wrong passwords tolerated before the login page closes. */
    public const MAX_ATTEMPTS = 3;

    /** How long strikes are remembered. */
    public const WINDOW_MINUTES = 15;

    /** How long the login page stays shut. */
    public const BLOCK_MINUTES = 60;

    /** Requests from an already-blocked address before the kernel takes over. */
    public const ESCALATE_AFTER = 20;

    public function __construct(
        protected FirewallService $firewall,
        protected GeoIpService $geo,
    ) {}

    public function isWhitelisted(string $ip): bool
    {
        return Cache::remember('ip-whitelist:'.$ip, 60, function () use ($ip): bool {
            foreach (IpWhitelist::query()->pluck('ip') as $entry) {
                if ($this->matches($ip, (string) $entry)) {
                    return true;
                }
            }

            return false;
        });
    }

    public function activeBlock(string $ip): ?BlockedIp
    {
        $block = BlockedIp::query()->where('ip', $ip)->first();

        return $block !== null && $block->isActive() ? $block : null;
    }

    /** Called for every wrong password. Returns the block if this one caused it. */
    public function recordFailure(string $ip, ?string $username, ?string $userAgent, ?string $path): ?BlockedIp
    {
        $this->log($ip, $username, false, $userAgent, $path);

        if ($this->isWhitelisted($ip)) {
            return null;
        }

        $recent = LoginAttempt::query()
            ->where('ip', $ip)
            ->where('succeeded', false)
            ->where('created_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->count();

        if ($recent < self::MAX_ATTEMPTS) {
            return null;
        }

        return $this->block(
            $ip,
            reason: 'login_bruteforce',
            attempts: $recent,
            username: $username,
            userAgent: $userAgent,
        );
    }

    public function recordSuccess(string $ip, ?string $username, ?string $userAgent, ?string $path): void
    {
        $this->log($ip, $username, true, $userAgent, $path);

        // A good password clears the slate so a fumbled attempt earlier in the
        // window cannot strand someone who has just proved who they are.
        LoginAttempt::query()
            ->where('ip', $ip)
            ->where('succeeded', false)
            ->where('created_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->delete();
    }

    public function block(
        string $ip,
        string $reason = 'manual',
        int $attempts = 0,
        ?string $username = null,
        ?string $userAgent = null,
        ?int $minutes = null,
    ): ?BlockedIp {
        if ($this->isWhitelisted($ip) || ! $this->firewall->isRoutableIpv4($ip)) {
            return null;
        }

        $country = $this->geo->lookup($ip);
        $minutes ??= self::BLOCK_MINUTES;

        $existing = BlockedIp::query()->where('ip', $ip)->first();

        $block = BlockedIp::query()->updateOrCreate(
            ['ip' => $ip],
            [
                'reason' => $reason,
                'country_code' => $country['code'],
                'country_name' => $country['name'],
                'attempts' => $attempts,
                'last_username' => $username !== null ? mb_substr($username, 0, 191) : null,
                'last_user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
                // Keep the first sighting across repeat offences.
                'first_seen_at' => $existing?->first_seen_at ?? now(),
                'blocked_at' => now(),
                'expires_at' => $minutes > 0 ? now()->addMinutes($minutes) : null,
                'unblocked_at' => null,
                'unblocked_by_user_id' => null,
            ],
        );

        return $block;
    }

    /** Push an address down to the kernel once it refuses to take the hint. */
    public function escalateToFirewall(BlockedIp $block): void
    {
        if ($block->in_firewall) {
            return;
        }

        $seconds = $block->expires_at !== null
            ? max(60, now()->diffInSeconds($block->expires_at, false))
            : 0;

        if ($this->firewall->block($block->ip, (int) $seconds)) {
            $block->forceFill(['in_firewall' => true, 'reason' => 'escalated'])->save();
        }
    }

    public function unblock(BlockedIp $block, ?User $actor = null): void
    {
        $this->firewall->unblock($block->ip);

        $block->forceFill([
            'unblocked_at' => now(),
            'unblocked_by_user_id' => $actor?->id,
            'in_firewall' => false,
        ])->save();
    }

    /** Drop firewall entries for blocks that have timed out. */
    public function pruneExpired(): int
    {
        $lifted = 0;

        $expired = BlockedIp::query()
            ->whereNull('unblocked_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($expired as $block) {
            if ($block->in_firewall) {
                $this->firewall->unblock($block->ip);
            }

            $block->forceFill(['in_firewall' => false])->save();
            $lifted++;
        }

        LoginAttempt::query()->where('created_at', '<', now()->subDays(30))->delete();

        return $lifted;
    }

    protected function log(string $ip, ?string $username, bool $ok, ?string $userAgent, ?string $path): void
    {
        LoginAttempt::query()->create([
            'ip' => $ip,
            'username' => $username !== null ? mb_substr($username, 0, 191) : null,
            'succeeded' => $ok,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            'path' => $path !== null ? mb_substr($path, 0, 191) : null,
            'created_at' => now(),
        ]);
    }

    /** Exact address or IPv4 CIDR membership. */
    protected function matches(string $ip, string $rule): bool
    {
        if ($rule === $ip) {
            return true;
        }

        if (! str_contains($rule, '/')) {
            return false;
        }

        [$subnet, $bits] = array_pad(explode('/', $rule, 2), 2, null);

        if (! is_numeric($bits)) {
            return false;
        }

        $bits = (int) $bits;
        $ipLong = ip2long($ip);
        $subnetLong = ip2long((string) $subnet);

        if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = -1 << (32 - $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
