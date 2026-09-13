<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentFinancialPlanTemplate extends Model
{
    protected $fillable = [
        'name',
        'credit_amount',
        'purchase_price',
        'discount_percent',
        'is_active',
        'sort_order',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'credit_amount' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(AgentFinancialPlanPurchase::class, 'template_id');
    }
}
