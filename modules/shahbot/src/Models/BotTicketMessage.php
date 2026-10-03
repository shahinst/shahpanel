<?php

namespace Modules\ShahBot\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotTicketMessage extends Model
{
    protected $table = 'shahbot_ticket_messages';

    protected $fillable = ['ticket_id', 'from_admin', 'author', 'body'];

    protected function casts(): array
    {
        return ['from_admin' => 'boolean'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(BotTicket::class, 'ticket_id');
    }
}
