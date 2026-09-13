<?php

namespace App\Services\PaymentGateways;

use App\Enums\PaymentGatewayDriver;

/**
 * Registry of payment-gateway drivers. Core drivers (Zarinpal, CardToCard) are
 * registered in AppServiceProvider; optional drivers (e.g. NowPayments) register
 * themselves from their module's service provider — so a driver is only
 * "supported" while its module/provider is active. Bound as a singleton so
 * registrations persist for the whole request.
 */
class PaymentGatewayManager
{
    /** @var array<string, callable(): PaymentGatewayDriverInterface> */
    protected array $factories = [];

    public function register(PaymentGatewayDriver|string $driver, callable $factory): void
    {
        $this->factories[$this->key($driver)] = $factory;
    }

    public function supports(PaymentGatewayDriver|string $driver): bool
    {
        return isset($this->factories[$this->key($driver)]);
    }

    public function driver(PaymentGatewayDriver $driver): PaymentGatewayDriverInterface
    {
        $factory = $this->factories[$this->key($driver)] ?? null;

        if ($factory === null) {
            throw new PaymentGatewayException(__('payment_gateways.gateway_not_available'));
        }

        return $factory();
    }

    private function key(PaymentGatewayDriver|string $driver): string
    {
        return $driver instanceof PaymentGatewayDriver ? $driver->value : $driver;
    }
}
