<?php

namespace App\Models;

use App\Enums\MoneyCurrency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    public $timestamps = false;

    const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'user_id',
        'balance',
        'locked_balance',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'locked_balance' => 'decimal:2',
            'updated_at' => 'datetime',
        ];
    }

    // ارز کیف‌پول را به‌صورت enum برمی‌گرداند تا نمایش مبالغ با واحد درست انجام شود.
    public function moneyCurrency(): MoneyCurrency
    {
        return MoneyCurrency::normalize($this->currency);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
