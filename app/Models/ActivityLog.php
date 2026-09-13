<?php

namespace App\Models;

use App\Traits\BelongsToHierarchy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    use BelongsToHierarchy;

    // This table has no updated_at column. Disabling only updated_at keeps
    // Eloquent's automatic created_at (which $timestamps = false suppressed).
    const UPDATED_AT = null;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'ip',
        'user_agent',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entity(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'entity_type', 'entity_id');
    }
}
