<?php

namespace Modules\ShahBot\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotAgencyRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $table = 'shahbot_agency_requests';

    protected $fillable = ['bot_user_id', 'note', 'status', 'seller_user_id', 'reviewed_by', 'reviewed_at'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function botUser(): BelongsTo
    {
        return $this->belongsTo(BotUser::class, 'bot_user_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }
}
