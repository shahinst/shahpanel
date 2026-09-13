<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceLog extends Model
{
    protected $fillable = [
        'user_id',
        'action',
        'status',
        'details',
        'output',
        'ran_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'ran_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
