<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TunnelMetricRollup extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'tunnel_agent_id',
        'bucket_at',
        'samples',
        'up_samples',
        'latency_avg_ms',
        'latency_max_ms',
        'jitter_avg_ms',
        'loss_avg_pct',
        'rx_bps_avg',
        'tx_bps_avg',
        'rx_bps_max',
        'tx_bps_max',
        'score_avg',
    ];

    protected function casts(): array
    {
        return [
            'bucket_at' => 'datetime',
            'samples' => 'integer',
            'up_samples' => 'integer',
            'latency_avg_ms' => 'integer',
            'latency_max_ms' => 'integer',
            'jitter_avg_ms' => 'integer',
            'loss_avg_pct' => 'float',
            'rx_bps_avg' => 'integer',
            'tx_bps_avg' => 'integer',
            'rx_bps_max' => 'integer',
            'tx_bps_max' => 'integer',
            'score_avg' => 'float',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(TunnelAgent::class, 'tunnel_agent_id');
    }
}
