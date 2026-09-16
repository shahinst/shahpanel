<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\MoneyCurrency;
use App\Enums\UserRole;
use App\Traits\BelongsToHierarchy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use BelongsToHierarchy;

    // This table has no updated_at column. Disabling only updated_at keeps
    // Eloquent's automatic created_at (which $timestamps = false suppressed).
    const UPDATED_AT = null;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'invoice_number',
        'buyer_user_id',
        'seller_user_id',
        'agent_user_id',
        'account_id',
        'type',
        'subtotal',
        'total',
        'currency',
        'status',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => InvoiceType::class,
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'status' => InvoiceStatus::class,
            'issued_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    // ارز فاکتور را به‌صورت enum برمی‌گرداند تا نمایش مبالغ با واحد درست انجام شود.
    public function moneyCurrency(): MoneyCurrency
    {
        return MoneyCurrency::normalize($this->currency);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_user_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'related_invoice_id');
    }

    public function scopeOwnedByHierarchy($query, User $viewer, string|array $columns = ['buyer_user_id', 'seller_user_id', 'agent_user_id'])
    {
        if ($viewer->role === UserRole::Admin) {
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
