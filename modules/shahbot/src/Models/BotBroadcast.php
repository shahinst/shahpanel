<?php

namespace Modules\ShahBot\Models;

use Illuminate\Database\Eloquent\Model;

class BotBroadcast extends Model
{
    public const QUEUED = 'queued';

    public const SENDING = 'sending';

    public const DONE = 'done';

    public const CANCELLED = 'cancelled';

    protected $table = 'shahbot_broadcasts';

    protected $fillable = [
        'bot_id',
        'text',
        'audience',
        'status',
        'cursor',
        'total',
        'sent',
        'failed',
        'created_by',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'cursor' => 'integer',
            'total' => 'integer',
            'sent' => 'integer',
            'failed' => 'integer',
            'finished_at' => 'datetime',
        ];
    }
}
