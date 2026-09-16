<?php

namespace App\Models;

use App\Enums\AccountCategory;
use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Traits\BelongsToHierarchy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

class Account extends Model
{
    use BelongsToHierarchy;
    use SoftDeletes;

    protected $fillable = [
        'owner_seller_id',
        'owner_agent_id',
        'client_user_id',
        'package_id',
        'package_duration_id',
        'server_id',
        'mikrotik_profile_key',
        'service_type',
        'remote_username',
        'remote_password_enc',
        'wireguard_private_key_enc',
        'wireguard_public_key',
        'wireguard_address',
        'sanaei_inbound_id',
        'sanaei_client_uuid',
        'sanaei_sub_id',
        'pasarguard_user_id',
        'pasarguard_subscription_url',
        'remnawave_uuid',
        'remnawave_subscription_url',
        'cisco_asa_username',
        'client_email',
        'client_panel_password_hash',
        'client_portal_password_enc',
        'portal_token',
        'portal_token_expires_at',
        'login_sms_sent_at',
        'data_limit_bytes',
        'purchased_data_gb',
        'data_used_bytes',
        'lifetime_used_bytes',
        'expiry_at',
        'status',
        'last_sync_at',
        'refunded_at',
        'display_label',
        'renewal_charge_override',
    ];

    protected $hidden = [
        'remote_password_enc',
        'wireguard_private_key_enc',
        'client_panel_password_hash',
        'client_portal_password_enc',
    ];

    protected function casts(): array
    {
        return [
            'service_type' => ServiceType::class,
            'remote_password_enc' => 'encrypted',
            'wireguard_private_key_enc' => 'encrypted',
            'client_panel_password_hash' => 'hashed',
            'client_portal_password_enc' => 'encrypted',
            'sanaei_inbound_id' => 'integer',
            'data_limit_bytes' => 'integer',
            'purchased_data_gb' => 'decimal:2',
            'renewal_charge_override' => 'decimal:2',
            'data_used_bytes' => 'integer',
            'lifetime_used_bytes' => 'integer',
            'expiry_at' => 'datetime',
            'portal_token_expires_at' => 'datetime',
            'status' => AccountStatus::class,
            'last_sync_at' => 'datetime',
            'refunded_at' => 'datetime',
            'login_sms_sent_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function ownerSeller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_seller_id');
    }

    public function ownerAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_agent_id');
    }

    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_user_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function packageDuration(): BelongsTo
    {
        return $this->belongsTo(PackageDuration::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(AccountUsageLog::class);
    }

    public function portalViews(): HasMany
    {
        return $this->hasMany(ClientPortalView::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'related_account_id');
    }

    public function kycVerification(): HasOne
    {
        return $this->hasOne(AccountKycVerification::class);
    }

    public function scopeForClient(Builder $query, User $client): Builder
    {
        return $query->where('client_user_id', $client->id);
    }

    public function scopeInCategory(Builder $query, AccountCategory|string $category): Builder
    {
        $category = is_string($category) ? AccountCategory::fromString($category) : $category;

        return $query->whereIn('service_type', $category->serviceTypeValues());
    }

    public function scopeOwnedByHierarchy(Builder $query, User $viewer, string|array $columns = ['owner_seller_id', 'owner_agent_id']): Builder
    {
        if ($viewer->role === UserRole::Admin) {
            return $query;
        }

        $columns = (array) $columns;
        $userIds = static::subtreeUserIds($viewer);

        return $query->where(function (Builder $inner) use ($columns, $userIds): void {
            foreach ($columns as $column) {
                $inner->orWhereIn($column, $userIds);
            }
        });
    }

    public function isRefunded(): bool
    {
        return $this->refunded_at !== null;
    }

    public static function hasLoginSmsSentColumn(): bool
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        try {
            $cached = Schema::hasTable('accounts')
                && Schema::hasColumn('accounts', 'login_sms_sent_at');
        } catch (\Throwable) {
            $cached = false;
        }

        return $cached;
    }

    protected static function loginSmsSentSettingKey(int $accountId): string
    {
        return 'account_login_sms_sent_'.$accountId;
    }

    public function loginSmsSent(): bool
    {
        if (static::hasLoginSmsSentColumn() && $this->login_sms_sent_at !== null) {
            return true;
        }

        if (! function_exists('shahpanel_installed') || ! shahpanel_installed()) {
            return false;
        }

        try {
            return Setting::getValue(static::loginSmsSentSettingKey($this->id)) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    public function markLoginSmsSent(): void
    {
        if ($this->loginSmsSent()) {
            return;
        }

        if (static::hasLoginSmsSentColumn()) {
            $this->forceFill(['login_sms_sent_at' => now()])->save();

            return;
        }

        Setting::setValue(static::loginSmsSentSettingKey($this->id), now()->toDateTimeString());
    }

    public function isUnlimited(): bool
    {
        return $this->data_limit_bytes === null;
    }

    public function isExpired(): bool
    {
        return $this->expiry_at !== null && $this->expiry_at->isPast();
    }

    /**
     * Admin-set fixed renewal price for this account (money string), or null to
     * price renewals normally. A stored 0 means renewals are free — that is a
     * deliberate value, not "unset".
     */
    public function renewalChargeOverride(): ?string
    {
        if ($this->renewal_charge_override === null) {
            return null;
        }

        return money_string((string) $this->renewal_charge_override);
    }

    public function isQuotaExhausted(): bool
    {
        if ($this->isUnlimited()) {
            return false;
        }

        return $this->data_used_bytes >= $this->data_limit_bytes;
    }

    /**
     * Purchased / provisioned data volume for display (elastic GB or fixed package bytes).
     */
    public function purchasedVolumeLabel(): string
    {
        if ($this->purchased_data_gb !== null && (float) $this->purchased_data_gb > 0) {
            $gb = (float) $this->purchased_data_gb;
            $formatted = rtrim(rtrim(number_format($gb, 2, '.', ''), '0'), '.');

            return persian_digits($formatted).' '.__('accounts.gb_unit');
        }

        if ($this->isUnlimited()) {
            return __('accounts.unlimited_data');
        }

        if ($this->data_limit_bytes !== null && $this->data_limit_bytes > 0) {
            return format_data_size($this->data_limit_bytes);
        }

        return '—';
    }

    public function remainingVolumeLabel(): string
    {
        if ($this->isUnlimited()) {
            return __('accounts.unlimited_data');
        }

        $limitBytes = (int) ($this->data_limit_bytes ?? 0);

        if ($limitBytes <= 0) {
            return '—';
        }

        $usedBytes = max(0, (int) $this->data_used_bytes);
        $remainingBytes = max(0, $limitBytes - $usedBytes);

        return format_data_size($remainingBytes);
    }

    public function wireguardHostAddress(): ?string
    {
        $address = trim((string) ($this->wireguard_address ?? ''));

        if ($address === '') {
            return null;
        }

        return explode('/', $address, 2)[0];
    }
}
