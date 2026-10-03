<?php

namespace Modules\ShahBot\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BotTicket extends Model
{
    public const OPEN = 'open';

    public const ANSWERED = 'answered';

    public const CLOSED = 'closed';

    protected $table = 'shahbot_tickets';

    protected $fillable = ['bot_user_id', 'status', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'bot_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(BotTicketMessage::class, 'ticket_id')->orderBy('id');
    }
}
