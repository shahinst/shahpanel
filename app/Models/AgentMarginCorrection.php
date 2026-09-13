<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentMarginCorrection extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'agent_user_id',
        'account_id',
        'margin_transaction_id',
        'expected_margin',
        'actual_margin',
        'clawback_amount',
        'clawback_transaction_id',
        'reason',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_margin' => 'decimal:2',
            'actual_margin' => 'decimal:2',
            'clawback_amount' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function marginTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'margin_transaction_id');
    }

    public function clawbackTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'clawback_transaction_id');
    }
}
