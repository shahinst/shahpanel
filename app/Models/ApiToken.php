<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bearer credential a reseller hands to their Telegram bot.
 *
 * Only the sha256 of the secret is stored, so a database leak cannot be
 * replayed against the API.
 */
class ApiToken extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'token_hash',
        'abilities',
        'allowed_ips',
        'rate_limit_per_minute',
        'last_used_at',
        'last_used_ip',
        'request_count',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'rate_limit_per_minute' => 'integer',
            'request_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * A token with no abilities recorded is unrestricted; '*' means the same.
     */
    public function can(string $ability): bool
    {
        $abilities = $this->abilities;

        if ($abilities === null || $abilities === [] || in_array('*', $abilities, true)) {
            return true;
        }

        if (in_array($ability, $abilities, true)) {
            return true;
        }

        // "accounts:*" covers "accounts:create".
        $group = explode(':', $ability)[0] ?? '';

        return $group !== '' && in_array($group.':*', $abilities, true);
    }

    public function allowsIp(?string $ip): bool
    {
        $allowed = trim((string) $this->allowed_ips);

        if ($allowed === '') {
            return true;
        }

        if ($ip === null || $ip === '') {
            return false;
        }

        foreach (array_filter(array_map('trim', explode(',', $allowed))) as $rule) {
            if ($rule === $ip) {
                return true;
            }

            if (str_contains($rule, '/') && static::ipMatchesCidr($ip, $rule)) {
                return true;
            }
        }

        return false;
    }

    /** IPv4 CIDR containment. Non-IPv4 input never matches. */
    protected static function ipMatchesCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);

        if ($bits === null || ! is_numeric($bits)) {
            return false;
        }

        $bits = (int) $bits;

        if ($bits < 0 || $bits > 32) {
            return false;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long((string) $subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        if ($bits === 0) {
            return true;
        }

        $mask = -1 << (32 - $bits);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
