<?php

namespace Modules\ShahBot\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Discount codes (taken off a purchase) and gift codes (credited to the wallet)
 * share one table: they differ only in where the value lands.
 */
class BotCode extends Model
{
    public const DISCOUNT = 'discount';

    public const GIFT = 'gift';

    protected $table = 'shahbot_codes';

    protected $fillable = [
        'kind',
        'code',
        'value_type',
        'value',
        'min_amount',
        'max_uses',
        'used_count',
        'first_purchase_only',
        'expires_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_amount' => 'decimal:2',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'first_purchase_only' => 'boolean',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function uses(): HasMany
    {
        return $this->hasMany(BotCodeUse::class, 'code_id');
    }

    public function isUsable(): bool
    {
        return $this->is_active
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && ($this->max_uses === null || $this->used_count < $this->max_uses);
    }
}
