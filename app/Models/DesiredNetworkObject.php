<?php

namespace App\Models;

use App\Enums\DesiredObjectStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesiredNetworkObject extends Model
{
    protected $fillable = [
        'server_id',
        'tunnel_group_id',
        'managed_interface_id',
        'object_type',
        'menu',
        'marker',
        'payload',
        'status',
        'last_error',
        'last_applied_at',
        'last_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => DesiredObjectStatus::class,
            'last_applied_at' => 'datetime',
            'last_verified_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function tunnelGroup(): BelongsTo
    {
        return $this->belongsTo(TunnelGroup::class);
    }

    public function managedInterface(): BelongsTo
    {
        return $this->belongsTo(ManagedInterface::class);
    }

    /** Stable marker format: vpnl:<type>:<id-or-key> */
    public static function makeMarker(string $type, string|int $key): string
    {
        return "vpnl:{$type}:{$key}";
    }
}
