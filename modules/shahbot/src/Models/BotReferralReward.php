<?php

namespace Modules\ShahBot\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotReferralReward extends Model
{
    protected $table = 'shahbot_referral_rewards';

    protected $fillable = ['referrer_id', 'referred_id', 'order_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'referrer_id');
    }

    public function referred(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'referred_id');
    }
}
