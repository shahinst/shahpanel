<?php

namespace App\Models;

use App\Enums\CommissionPayer;
use App\Enums\PaymentGatewayDriver;
use App\Enums\PaymentGatewayMode;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class PaymentGateway extends Model
{
    protected $fillable = [
        'driver',
        'display_name',
        'is_enabled',
        'mode',
        'config',
        'commission_percent',
        'commission_fixed',
        'commission_payer',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'driver' => PaymentGatewayDriver::class,
            'mode' => PaymentGatewayMode::class,
            'is_enabled' => 'boolean',
            'config' => 'array',
            'commission_percent' => 'decimal:4',
            'commission_fixed' => 'decimal:2',
            'commission_payer' => CommissionPayer::class,
        ];
    }

    public function gatewayPayments(): HasMany
    {
        return $this->hasMany(GatewayPayment::class);
    }

    public function isOperational(): bool
    {
        if (! $this->is_enabled || ! $this->driver->isImplemented()) {
            return false;
        }

        // A driver is only usable while a provider has registered it. Optional
        // drivers (e.g. NowPayments) are registered by their module — so when the
        // module is deactivated the gateway automatically becomes non-operational.
        return app(\App\Services\PaymentGateways\PaymentGatewayManager::class)->supports($this->driver);
    }

    public function configValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->config ?? [], $key, $default);
    }

    public function setEncryptedConfigValue(string $key, ?string $plain): void
    {
        $config = $this->config ?? [];

        if ($plain === null || trim($plain) === '') {
            unset($config[$key]);
        } else {
            $config[$key] = Crypt::encryptString(trim($plain));
        }

        $this->config = $config;
    }

    public function encryptedConfigValue(string $key): ?string
    {
        $stored = $this->configValue($key);

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return $stored;
        }
    }

    public function hasEncryptedConfigValue(string $key): bool
    {
        return $this->encryptedConfigValue($key) !== null;
    }

    public static function findByDriver(PaymentGatewayDriver $driver): ?self
    {
        return static::query()->where('driver', $driver->value)->first();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, self>
     */
    public static function enabledForTopUp()
    {
        return static::query()
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (self $gateway): bool => $gateway->isOperational());
    }

    /**
     * @return list<string>
     */
    public function enabledNowPaymentsPayCurrencies(): array
    {
        $enabled = $this->configValue('enabled_pay_currencies');

        if (is_array($enabled) && $enabled !== []) {
            return array_values(array_unique(array_filter(array_map(
                static fn ($code): string => strtolower(trim((string) $code)),
                $enabled,
            ))));
        }

        $legacy = $this->configValue('pay_currency');

        if (is_string($legacy) && trim($legacy) !== '') {
            return [strtolower(trim($legacy))];
        }

        return [];
    }

    public function allowsNowPaymentsPayCurrency(string $currency): bool
    {
        $currency = strtolower(trim($currency));

        return $currency !== '' && in_array($currency, $this->enabledNowPaymentsPayCurrencies(), true);
    }
}
