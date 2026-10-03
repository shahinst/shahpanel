<?php

namespace Modules\ShahBot\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotWheelSpin extends Model
{
    protected $table = 'shahbot_wheel_spins';

    protected $fillable = ['bot_user_id', 'prize_label', 'prize_type', 'prize_value', 'code'];

    protected function casts(): array
    {
        return ['prize_value' => 'decimal:2'];
    }

    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'bot_user_id');
    }
}
