<?php

namespace App\Models;

use App\Enums\BroadcastAudience;
use App\Enums\BroadcastStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationBroadcast extends Model
{
    protected $fillable = [
        'sender_user_id',
        'audience',
        'recipient_user_ids',
        'status',
        'title',
        'body',
        'link',
        'image_path',
        'reviewed_by_user_id',
        'reviewed_at',
        'admin_note',
    ];

    protected function casts(): array
    {
        return [
            'audience' => BroadcastAudience::class,
            'recipient_user_ids' => 'array',
            'status' => BroadcastStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function audienceLabel(): string
    {
        if ($this->audience === BroadcastAudience::Sellers
            && is_array($this->recipient_user_ids)
            && $this->recipient_user_ids !== []) {
            return __('broadcasts.to_sellers_selected', [
                'count' => persian_digits(count($this->recipient_user_ids)),
            ]);
        }

        return $this->audience->label();
    }
}
