<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfigVersion extends Model
{
    protected $fillable = [
        'tunnel_group_id',
        'version',
        'snapshot',
        'checksum',
        'reason',
        'created_by',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'snapshot' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function tunnelGroup(): BelongsTo
    {
        return $this->belongsTo(TunnelGroup::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
