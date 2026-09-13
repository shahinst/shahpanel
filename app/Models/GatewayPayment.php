<?php

namespace App\Models;

use App\Enums\CommissionPayer;
use App\Enums\GatewayPaymentStatus;
use App\Enums\PaymentGatewayDriver;
use App\Traits\BelongsToHierarchy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GatewayPayment extends Model
{
    use BelongsToHierarchy;

    protected $fillable = [
        'uuid',
        'user_id',
        'payment_gateway_id',
        'driver',
        'status',
        'amount_usdt',
        'amount_toman',
        'usdt_toman_rate',
        'gross_toman',
        'commission_toman',
        'net_toman',
        'commission_payer',
        'external_payment_id',
        'external_invoice_id',
        'pay_address',
        'pay_currency',
        'pay_amount',
        'invoice_url',
        'tracking_number',
        'card_last4',
        'requester_note',
        'reviewer_user_id',
        'reviewed_at',
        'admin_note',
        'callback_payload',
        'paid_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'driver' => PaymentGatewayDriver::class,
            'status' => GatewayPaymentStatus::class,
            'amount_usdt' => 'decimal:8',
            'amount_toman' => 'decimal:2',
            'usdt_toman_rate' => 'decimal:2',
            'gross_toman' => 'decimal:2',
            'commission_toman' => 'decimal:2',
            'net_toman' => 'decimal:2',
            'commission_payer' => CommissionPayer::class,
            'pay_amount' => 'decimal:8',
            'callback_payload' => 'array',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentGateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'related_gateway_payment_id');
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function isOwnedBy(User $user): bool
    {
        return (int) $this->user_id === (int) $user->id;
    }
}
