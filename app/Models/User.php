<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Traits\BelongsToHierarchy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use BelongsToHierarchy;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'role',
        'parent_id',
        'username',
        'email',
        'password',
        'full_name',
        'phone',
        'status',
        'enabled_currencies',
        'settlement_currency',
        'daily_server_change_limit',
        'telegram_id',
        'broadcast_banner_watermark_id',
        'last_login_at',
        'last_login_ip',
        'two_fa_secret',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_fa_secret',
    ];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'status' => UserStatus::class,
            'enabled_currencies' => 'array',
            'daily_server_change_limit' => 'integer',
            'broadcast_banner_watermark_id' => 'integer',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
            'two_fa_secret' => 'encrypted',
        ];
    }

    public function enabledCurrencyCodes(): array
    {
        return app(\App\Services\UserCurrencyService::class)->enabledCodes($this);
    }

    public function settlementMoneyCurrency(): \App\Enums\MoneyCurrency
    {
        return app(\App\Services\UserCurrencyService::class)->settlementCurrency($this);
    }

    public function canUseMoneyCurrency(\App\Enums\MoneyCurrency|string $currency): bool
    {
        return app(\App\Services\UserCurrencyService::class)->canUseCurrency($this, $currency);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class)
            ->where('currency', \App\Enums\MoneyCurrency::default()->value);
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function packageDurationPrices(): HasMany
    {
        return $this->hasMany(UserPackageDurationPrice::class);
    }

    public function ownedAccountsAsSeller(): HasMany
    {
        return $this->hasMany(Account::class, 'owner_seller_id');
    }

    public function ownedAccountsAsAgent(): HasMany
    {
        return $this->hasMany(Account::class, 'owner_agent_id');
    }

    public function paymentRequestsSubmitted(): HasMany
    {
        return $this->hasMany(PaymentRequest::class, 'requester_user_id');
    }

    public function paymentRequestsToApprove(): HasMany
    {
        return $this->hasMany(PaymentRequest::class, 'approver_user_id');
    }

    public function invoicesAsBuyer(): HasMany
    {
        return $this->hasMany(Invoice::class, 'buyer_user_id');
    }

    public function invoicesAsSeller(): HasMany
    {
        return $this->hasMany(Invoice::class, 'seller_user_id');
    }

    public function invoicesAsAgent(): HasMany
    {
        return $this->hasMany(Invoice::class, 'agent_user_id');
    }

    public function storefront(): HasOne
    {
        return $this->hasOne(Storefront::class);
    }

    public function paymentCards(): HasMany
    {
        return $this->hasMany(PaymentCard::class);
    }

    /** @deprecated Use paymentCards() */
    public function paymentCard(): HasOne
    {
        return $this->hasOne(PaymentCard::class)->latestOfMany();
    }

    public function clientDisplayPrices(): HasMany
    {
        return $this->hasMany(ClientDisplayPrice::class);
    }

    public function clientAccounts(): HasMany
    {
        return $this->hasMany(Account::class, 'client_user_id');
    }

    public function assignedPackages(): BelongsToMany
    {
        return $this->belongsToMany(Package::class, 'user_packages')
            ->withTimestamps()
            ->withPivot('assigned_by_user_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function panelNotifications(): HasMany
    {
        return $this->hasMany(PanelNotification::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', UserStatus::Active);
    }

    public function scopeRole($query, UserRole $role)
    {
        return $query->where('role', $role);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return filled($this->two_fa_secret);
    }
}
