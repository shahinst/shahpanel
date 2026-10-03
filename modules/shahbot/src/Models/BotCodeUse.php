<?php

namespace Modules\ShahBot\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotCodeUse extends Model
{
    protected $table = 'shahbot_code_uses';

    protected $fillable = ['code_id', 'bot_user_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function code(): BelongsTo
    {
        return $this->belongsTo(BotCode::class, 'code_id');
    }

    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'bot_user_id');
    }
}
