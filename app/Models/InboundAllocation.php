<?php

namespace App\Models;

use App\Enums\MoneyCurrency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An agent's share of one Sanaei server: the inbounds they may sell on, how
 * much traffic their accounts may use in total, and the price per GB the
 * panel charges them for it.
 */
class InboundAllocation extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const SUSPENDED_QUOTA = 'quota';

    public const SUSPENDED_BALANCE = 'balance';

    public const SUSPENDED_ADMIN = 'admin';

    public const GB = 1073741824;

    protected $fillable = [
        'agent_user_id',
        'server_id',
        'title',
        'inbound_ids',
        'quota_bytes',
        'price_per_gb',
        'currency',
        'credit_limit',
        'status',
        'suspended_reason',
        'suspended_account_ids',
        'used_bytes',
        'billed_bytes',
        'billed_amount',
        'usage_watermark',
        'last_billed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'inbound_ids' => 'array',
            'suspended_account_ids' => 'array',
            'quota_bytes' => 'integer',
            'used_bytes' => 'integer',
            'billed_bytes' => 'integer',
            'usage_watermark' => 'integer',
            'price_per_gb' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'billed_amount' => 'decimal:2',
            'last_billed_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(Package::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function moneyCurrency(): MoneyCurrency
    {
        return MoneyCurrency::normalize($this->currency);
    }

    /** @return list<int> */
    public function inboundIdList(): array
    {
        return array_values(array_map('intval', (array) $this->inbound_ids));
    }

    public function remainingBytes(): int
    {
        return max(0, (int) $this->quota_bytes - (int) $this->used_bytes);
    }

    public function usedPercent(): float
    {
        return $this->quota_bytes > 0 ? min(100, round($this->used_bytes / $this->quota_bytes * 100, 1)) : 0.0;
    }

    public function quotaReached(): bool
    {
        return $this->quota_bytes > 0 && $this->used_bytes >= $this->quota_bytes;
    }

    public function label(): string
    {
        return $this->title ?: ($this->server?->name ?? '#'.$this->id);
    }
}
