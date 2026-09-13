<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TunnelGroupExit extends Model
{
    protected $fillable = [
        'tunnel_group_id',
        'server_id',
        'position',
        'status',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'meta' => 'array',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TunnelGroup::class, 'tunnel_group_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function agents(): HasMany
    {
        return $this->hasMany(TunnelAgent::class)->orderBy('seq');
    }
}
