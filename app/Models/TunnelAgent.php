<?php

namespace App\Models;

use App\Enums\AgentHealth;
use App\Enums\TunnelKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TunnelAgent extends Model
{
    protected $fillable = [
        'tunnel_group_id',
        'tunnel_group_exit_id',
        'seq',
        'kind',
        'iran_interface',
        'foreign_interface',
        'transport_network',
        'iran_ip',
        'foreign_ip',
        'tunnel_id_value',
        'udp_port',
        'weight',
        'is_enabled',
        'health',
        'quality_score',
        'baseline_rx_bps',
        'baseline_tx_bps',
        'fail_count',
        'last_seen_up_at',
        'last_switched_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'kind' => TunnelKind::class,
            'health' => AgentHealth::class,
            'seq' => 'integer',
            'tunnel_id_value' => 'integer',
            'udp_port' => 'integer',
            'weight' => 'integer',
            'is_enabled' => 'boolean',
            'quality_score' => 'float',
            'baseline_rx_bps' => 'integer',
            'baseline_tx_bps' => 'integer',
            'fail_count' => 'integer',
            'last_seen_up_at' => 'datetime',
            'last_switched_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TunnelGroup::class, 'tunnel_group_id');
    }

    public function exit(): BelongsTo
    {
        return $this->belongsTo(TunnelGroupExit::class, 'tunnel_group_exit_id');
    }

    public function metricSamples(): HasMany
    {
        return $this->hasMany(TunnelMetricSample::class);
    }

    public function metricRollups(): HasMany
    {
        return $this->hasMany(TunnelMetricRollup::class);
    }

    public function isUsable(): bool
    {
        return $this->is_enabled && $this->health !== AgentHealth::Down;
    }
}
