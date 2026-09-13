<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TunnelMetricSample extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tunnel_agent_id',
        'sampled_at',
        'up',
        'latency_ms',
        'jitter_ms',
        'loss_pct',
        'rx_bps',
        'tx_bps',
        'score',
    ];

    protected function casts(): array
    {
        return [
            'sampled_at' => 'datetime',
            'up' => 'boolean',
            'latency_ms' => 'integer',
            'jitter_ms' => 'integer',
            'loss_pct' => 'float',
            'rx_bps' => 'integer',
            'tx_bps' => 'integer',
            'score' => 'float',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(TunnelAgent::class, 'tunnel_agent_id');
    }
}
