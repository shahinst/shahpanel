<?php

namespace Modules\ShahBot\Models;

use Illuminate\Database\Eloquent\Model;

class BotLottery extends Model
{
    public const OPEN = 'open';

    public const DRAWN = 'drawn';

    public const CANCELLED = 'cancelled';

    protected $table = 'shahbot_lotteries';

    protected $fillable = [
        'bot_id', 'title', 'description', 'prize_amount', 'winners_count',
        'starts_at', 'draw_at', 'status', 'winners', 'participants', 'drawn_at',
    ];

    protected function casts(): array
    {
        return [
            'bot_id' => 'integer',
            'prize_amount' => 'decimal:2',
            'winners_count' => 'integer',
            'starts_at' => 'datetime',
            'draw_at' => 'datetime',
            'winners' => 'array',
            'participants' => 'integer',
            'drawn_at' => 'datetime',
        ];
    }
}
