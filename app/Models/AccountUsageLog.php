<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountUsageLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'rx_delta_bytes',
        'tx_delta_bytes',
        'rx_snapshot',
        'tx_snapshot',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'rx_delta_bytes' => 'integer',
            'tx_delta_bytes' => 'integer',
            'rx_snapshot' => 'integer',
            'tx_snapshot' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
