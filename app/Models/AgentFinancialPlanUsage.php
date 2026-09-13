<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentFinancialPlanUsage extends Model
{
    protected $fillable = [
        'purchase_id',
        'agent_id',
        'related_account_id',
        'buyer_user_id',
        'transaction_id',
        'wholesale_portion',
        'discount_percent',
        'discount_amount',
        'charged_portion',
        'usage_type',
    ];

    protected function casts(): array
    {
        return [
            'wholesale_portion' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'charged_portion' => 'decimal:2',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(AgentFinancialPlanPurchase::class, 'purchase_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function relatedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'related_account_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }
}
