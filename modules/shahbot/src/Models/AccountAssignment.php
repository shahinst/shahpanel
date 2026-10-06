<?php

namespace Modules\ShahBot\Models;

use App\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountAssignment extends Model
{
    protected $table = 'shahbot_account_assignments';

    protected $fillable = [
        'bot_id', 'account_id', 'bot_user_id', 'previous_client_user_id',
        'client_user_id', 'assigned_by_user_id', 'revoked_at',
    ];

    protected function casts(): array
    {
        return ['revoked_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
