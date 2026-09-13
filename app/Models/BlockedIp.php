<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockedIp extends Model
{
    protected $fillable = [
        'ip', 'reason', 'country_code', 'country_name', 'attempts',
        'last_username', 'last_user_agent', 'first_seen_at', 'blocked_at',
        'expires_at', 'unblocked_at', 'unblocked_by_user_id', 'in_firewall',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'blocked_at' => 'datetime',
            'expires_at' => 'datetime',
            'unblocked_at' => 'datetime',
            'in_firewall' => 'boolean',
            'attempts' => 'integer',
        ];
    }

    public function unblockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unblocked_by_user_id');
    }

    /** Still in force: neither lifted by an admin nor timed out. */
    public function isActive(): bool
    {
        if ($this->unblocked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('unblocked_at')
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /** Regional-indicator flag for the stored country code. */
    public function flag(): string
    {
        return \App\Services\GeoIpService::flagEmoji($this->country_code);
    }
}
