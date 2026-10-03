<?php

namespace Modules\ShahBot\Models;

use App\Models\GatewayPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a panel gateway payment (ZarinPal, crypto) to the bot user who
 * started it, so the bot can report the outcome.
 */
class BotGatewayPayment extends Model
{
    protected $table = 'shahbot_gateway_payments';

    protected $fillable = ['bot_user_id', 'gateway_payment_id', 'notified_at'];

    protected function casts(): array
    {
        return ['notified_at' => 'datetime'];
    }

    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'bot_user_id');
    }

    public function gatewayPayment(): BelongsTo
    {
        return $this->belongsTo(GatewayPayment::class);
    }
}
