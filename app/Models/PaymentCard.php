<?php

namespace App\Models;

use App\Enums\PaymentCardApprovalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentCard extends Model
{
    protected $fillable = [
        'user_id',
        'card_number',
        'card_holder',
        'bank_name',
        'instructions',
        'approval_status',
        'approved_by_user_id',
        'approved_at',
        'deletion_requested_at',
        'deletion_approved_by_user_id',
        'deletion_approved_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'approval_status' => PaymentCardApprovalStatus::class,
            'approved_at' => 'datetime',
            'deletion_requested_at' => 'datetime',
            'deletion_approved_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function isPendingApproval(): bool
    {
        return $this->approval_status === PaymentCardApprovalStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->approval_status === PaymentCardApprovalStatus::Approved;
    }

    public function isPendingDeletion(): bool
    {
        return $this->deletion_requested_at !== null && $this->deletion_approved_at === null;
    }

    public function isPublished(): bool
    {
        return $this->is_active
            && $this->isApproved()
            && ! $this->isPendingDeletion();
    }
}
