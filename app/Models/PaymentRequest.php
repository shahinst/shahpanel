<?php

namespace App\Models;

use App\Enums\PaymentRequestStatus;
use App\Traits\BelongsToHierarchy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentRequest extends Model
{
    use BelongsToHierarchy;

    // This table has no updated_at column. Disabling only updated_at keeps
    // Eloquent's automatic created_at (which $timestamps = false suppressed).
    const UPDATED_AT = null;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'requester_user_id',
        'approver_user_id',
        'amount',
        'currency',
        'tracking_number',
        'card_last4',
        'receipt_image_path',
        'status',
        'admin_note',
        'requester_note',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => PaymentRequestStatus::class,
            'decided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function moneyCurrency(): \App\Enums\MoneyCurrency
    {
        return \App\Enums\MoneyCurrency::normalize($this->currency);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'related_payment_request_id');
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function scopeOwnedByHierarchy($query, User $viewer, string|array $columns = ['requester_user_id', 'approver_user_id'])
    {
        if ($viewer->role === \App\Enums\UserRole::Admin) {
            return $query;
        }

        $columns = (array) $columns;
        $userIds = static::subtreeUserIds($viewer);

        return $query->where(function ($inner) use ($columns, $userIds): void {
            foreach ($columns as $column) {
                $inner->orWhereIn($column, $userIds);
            }
        });
    }
}
