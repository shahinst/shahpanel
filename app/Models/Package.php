<?php

namespace App\Models;

use App\Enums\PasarguardExpiryActivation;
use App\Enums\PackagePricingModel;
use App\Enums\MoneyCurrency;
use App\Enums\ServerType;
use App\Enums\ServiceType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Package extends Model
{
    protected $fillable = [
        'name',
        'package_category_id',
        'service_type',
        'pricing_model',
        'default_server_id',
        'mikrotik_profile_keys',
        'duration_days',
        'data_limit_gb',
        'min_data_gb',
        'max_data_gb',
        'pasarguard_group_id',
        'pasarguard_hwid_limit',
        'pasarguard_expiry_activation',
        'remnawave_squads',
        'remnawave_traffic_strategy',
        'cisco_group_policy',
        'cisco_tunnel_group',
        'cisco_simultaneous_logins',
        'ocserv_group',
        'ocserv_max_sessions',
        'base_price',
        'currency',
        'is_active',
        'kyc_required',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'service_type' => ServiceType::class,
            'duration_days' => 'integer',
            'data_limit_gb' => 'decimal:2',
            'min_data_gb' => 'decimal:2',
            'max_data_gb' => 'decimal:2',
            'pasarguard_group_id' => 'integer',
            'pasarguard_hwid_limit' => 'integer',
            'pasarguard_expiry_activation' => PasarguardExpiryActivation::class,
            'remnawave_squads' => 'array',
            'cisco_simultaneous_logins' => 'integer',
            'ocserv_max_sessions' => 'integer',
            'mikrotik_profile_keys' => 'array',
            'base_price' => 'decimal:2',
            'currency' => MoneyCurrency::class,
            'is_active' => 'boolean',
            'kyc_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function moneyCurrency(): MoneyCurrency
    {
        try {
            $value = $this->getAttribute('currency');
        } catch (\Throwable) {
            return MoneyCurrency::IRT;
        }

        return $value instanceof MoneyCurrency
            ? $value
            : MoneyCurrency::normalize(is_string($value) ? $value : null);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PackageCategory::class, 'package_category_id');
    }

    public function defaultServer(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'default_server_id');
    }

    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class, 'package_server');
    }

    public function durations(): HasMany
    {
        return $this->hasMany(PackageDuration::class)->orderBy('sort_order');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_packages')
            ->withTimestamps()
            ->withPivot('assigned_by_user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isUnlimited(): bool
    {
        return $this->data_limit_gb === null && ! $this->isElastic();
    }

    /**
     * Tolerate NULL/legacy values in DB (pre-migration rows) without crashing enum cast.
     */
    protected function pricingModel(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): PackagePricingModel {
                if ($value instanceof PackagePricingModel) {
                    return $value;
                }

                return PackagePricingModel::tryFrom((string) $value) ?? PackagePricingModel::Fixed;
            },
            set: function (mixed $value): string {
                if ($value instanceof PackagePricingModel) {
                    return $value->value;
                }

                return (PackagePricingModel::tryFrom((string) $value) ?? PackagePricingModel::Fixed)->value;
            },
        );
    }

    public function isElastic(): bool
    {
        if (! Schema::hasColumn($this->getTable(), 'pricing_model')) {
            return $this->min_data_gb !== null || $this->max_data_gb !== null;
        }

        if ($this->pricing_model === PackagePricingModel::Elastic) {
            return true;
        }

        // Legacy rows: migration defaulted to "fixed" but min/max GB range is set.
        return $this->min_data_gb !== null && $this->max_data_gb !== null;
    }

    /**
     * Clamp a requested GB amount to the package min/max bounds.
     */
    public function clampDataGb(float $gb): float
    {
        $min = $this->min_data_gb !== null ? (float) $this->min_data_gb : 1.0;
        $max = $this->max_data_gb !== null ? (float) $this->max_data_gb : null;

        if ($gb < $min) {
            $gb = $min;
        }

        if ($max !== null && $gb > $max) {
            $gb = $max;
        }

        return $gb;
    }

    public function usesPasarguardServer(): bool
    {
        return $this->relationLoaded('servers')
            ? $this->servers->contains(fn (Server $server): bool => $server->isPasarguard())
            : $this->servers()->where('type', ServerType::Pasarguard)->exists();
    }

    public function usesRemnawaveServer(): bool
    {
        return $this->relationLoaded('servers')
            ? $this->servers->contains(fn (Server $server): bool => $server->isRemnawave())
            : $this->servers()->where('type', ServerType::Remnawave)->exists();
    }

    /**
     * @return list<string>
     */
    public function remnawaveSquadUuids(): array
    {
        $squads = $this->remnawave_squads;

        if (! is_array($squads)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $squads), fn (string $v): bool => $v !== ''));
    }

    public function remnawaveTrafficStrategy(): string
    {
        $value = strtoupper((string) ($this->remnawave_traffic_strategy ?? 'NO_RESET'));

        return in_array($value, ['NO_RESET', 'DAY', 'WEEK', 'MONTH'], true) ? $value : 'NO_RESET';
    }

    /**
     * @return list<string>
     */
    public function mikrotikProfileKeys(): array
    {
        $keys = $this->mikrotik_profile_keys;

        if (! is_array($keys)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $key): string => trim((string) $key),
            $keys
        ), static fn (string $key): bool => $key !== '')));
    }

    public function lowestEnabledPrice(): ?string
    {
        $min = $this->durations
            ->where('is_enabled', true)
            ->min('price');

        return $min !== null ? (string) $min : null;
    }
}
