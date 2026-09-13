<?php

namespace App\Models;

use App\Enums\AgentFinancialPlanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentFinancialPlanPurchase extends Model
{
    protected $fillable = [
        'agent_id',
        'template_id',
        'name',
        'credit_total',
        'credit_remaining',
        'purchase_price',
        'discount_percent',
        'status',
        'sold_by_user_id',
        'wallet_transaction_id',
        'purchased_at',
    ];

    protected function casts(): array
    {
        return [
            'credit_total' => 'decimal:2',
            'credit_remaining' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'status' => AgentFinancialPlanStatus::class,
            'purchased_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(AgentFinancialPlanTemplate::class, 'template_id');
    }

    public function soldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by_user_id');
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'wallet_transaction_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(AgentFinancialPlanUsage::class, 'purchase_id');
    }

    public function isActive(): bool
    {
        return $this->status === AgentFinancialPlanStatus::Active
            && bccomp((string) $this->credit_remaining, '0', 2) > 0;
    }
}
