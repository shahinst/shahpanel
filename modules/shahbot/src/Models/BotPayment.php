<?php

namespace Modules\ShahBot\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A wallet top-up through card-to-card: created when the user names an amount,
 * waits for the receipt photo, then for an admin's decision.
 */
class BotPayment extends Model
{
    public const AWAITING_RECEIPT = 'awaiting_receipt';

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $table = 'shahbot_payments';

    protected $fillable = [
        'bot_user_id',
        'amount',
        'stars',
        'method',
        'status',
        'receipt_file_id',
        'receipt_note',
        'external_id',
        'reviewed_by',
        'reviewed_at',
        'reject_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'stars' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'bot_user_id');
    }
}
