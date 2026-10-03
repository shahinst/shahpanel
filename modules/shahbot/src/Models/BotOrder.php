<?php

namespace Modules\ShahBot\Models;

use App\Models\Account;
use App\Models\PackageDuration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotOrder extends Model
{
    protected $table = 'shahbot_orders';

    protected $fillable = [
        'bot_user_id',
        'account_id',
        'package_duration_id',
        'type',
        'amount',
        'discount',
        'discount_code',
        'data_gb',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'discount' => 'decimal:2',
            'data_gb' => 'decimal:2',
        ];
    }

    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'bot_user_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }

    public function duration(): BelongsTo
    {
        return $this->belongsTo(PackageDuration::class, 'package_duration_id');
    }
}
