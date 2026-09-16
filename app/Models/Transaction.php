<?php

namespace App\Models;

use App\Enums\MoneyCurrency;
use App\Enums\TransactionType;
use App\Traits\BelongsToHierarchy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    use BelongsToHierarchy;

    // This table has no updated_at column. Disabling only updated_at keeps
    // Eloquent's automatic created_at (which $timestamps = false suppressed).
    const UPDATED_AT = null;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'wallet_id',
        'user_id',
        'type',
        'amount',
        'currency',
        'balance_before',
        'balance_after',
        'source_user_id',
        'related_account_id',
        'related_invoice_id',
        'related_payment_request_id',
        'related_gateway_payment_id',
        'description',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    // ارز تراکنش را به‌صورت enum برمی‌گرداند تا نمایش مبالغ با واحد درست انجام شود.
    public function moneyCurrency(): MoneyCurrency
    {
        return MoneyCurrency::normalize($this->currency);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'source_user_id');
    }

    public function relatedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'related_account_id');
    }

    public function relatedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'related_invoice_id');
    }

    public function relatedPaymentRequest(): BelongsTo
    {
        return $this->belongsTo(PaymentRequest::class, 'related_payment_request_id');
    }

    public function relatedGatewayPayment(): BelongsTo
    {
        return $this->belongsTo(GatewayPayment::class, 'related_gateway_payment_id');
    }
}
