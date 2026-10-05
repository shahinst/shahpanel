<?php

namespace Modules\Dedicated\Models;

use App\Models\InboundAllocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An inbound agent asking the admin for more volume on one of their inbounds.
 */
class InboundChargeRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $fillable = [
        'agent_user_id', 'allocation_id', 'pack_id', 'requested_gb', 'amount', 'currency',
        'status', 'approved_gb', 'charged_amount', 'note', 'admin_note', 'reviewed_by_user_id', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_gb' => 'integer',
            'approved_gb' => 'integer',
            'amount' => 'decimal:2',
            'charged_amount' => 'decimal:2',
            'reviewed_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(InboundAllocation::class, 'allocation_id');
    }

    public function pack(): BelongsTo
    {
        return $this->belongsTo(InboundVolumePack::class, 'pack_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
