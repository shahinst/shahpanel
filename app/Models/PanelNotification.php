<?php

namespace App\Models;

use App\Enums\NotificationType;
use App\Traits\BelongsToHierarchy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PanelNotification extends Model
{
    use BelongsToHierarchy;

    protected $table = 'notifications';

    // This table has no updated_at column. Disabling only updated_at keeps
    // Eloquent's automatic created_at (which $timestamps = false suppressed).
    const UPDATED_AT = null;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'link',
        'reference_key',
        'is_read',
    ];

    protected function casts(): array
    {
        return [
            'type' => NotificationType::class,
            'is_read' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function markAsRead(): bool
    {
        return $this->update(['is_read' => true]);
    }
}
