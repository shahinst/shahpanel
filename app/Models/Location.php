<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $fillable = [
        'name',
        'code',
        'iran_server_id',
        'is_active',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function iranServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'iran_server_id');
    }

    public function tunnelGroups(): HasMany
    {
        return $this->hasMany(TunnelGroup::class);
    }

    public function managedInterfaces(): HasMany
    {
        return $this->hasMany(ManagedInterface::class);
    }
}
