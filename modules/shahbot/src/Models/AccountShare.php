<?php

namespace Modules\ShahBot\Models;

use App\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountShare extends Model
{
    protected $table = 'shahbot_account_shares';

    protected $fillable = ['account_id', 'owner_bot_user_id', 'member_bot_user_id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'owner_bot_user_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'member_bot_user_id');
    }
}
