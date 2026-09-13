<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerMetricSample extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'server_id',
        'sampled_at',
        'cpu_pct',
        'ram_pct',
        'conntrack',
        'conntrack_max',
        'rx_bps',
        'tx_bps',
        'per_core',
    ];

    protected function casts(): array
    {
        return [
            'sampled_at' => 'datetime',
            'cpu_pct' => 'float',
            'ram_pct' => 'float',
            'conntrack' => 'integer',
            'conntrack_max' => 'integer',
            'rx_bps' => 'integer',
            'tx_bps' => 'integer',
            'per_core' => 'array',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
