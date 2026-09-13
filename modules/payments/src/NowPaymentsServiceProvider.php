<?php

namespace Modules\NowPayments;

use App\Enums\PaymentGatewayDriver;
use App\Services\PaymentGateways\CardToCardGateway;
use App\Services\PaymentGateways\PaymentGatewayManager;
use App\Services\PaymentGateways\ZarinpalGateway;
use Illuminate\Support\ServiceProvider;
use Modules\NowPayments\Client\NowPaymentsCurrencyCatalog;
use Modules\NowPayments\Client\NowPaymentsSignatureVerifier;
use Modules\NowPayments\Gateway\NowPaymentsGateway;

/**
 * Service provider for the whole "payments" module. While this module is active
 * it owns the entire payment section: it registers every gateway driver
 * (Zarinpal, CardToCard, NowPayments), the payment webhooks, the NowPayments
 * admin view and services. Deactivating the module unregisters all of this, so
 * the panel's payment menus/pages/gateways all disappear.
 */
class NowPaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Provide the nowpayments.* config only while the module is active.
        $this->mergeConfigFrom(__DIR__.'/../config/nowpayments.php', 'payment_gateways');

        $this->app->singleton(NowPaymentsCurrencyCatalog::class);
        $this->app->singleton(NowPaymentsSignatureVerifier::class);
        $this->app->singleton(NowPaymentsService::class);
        $this->app->singleton(NowPaymentsGateway::class);

        // String aliases so the core (controllers) can resolve module services
        // lazily without a hard compile-time dependency on module classes.
        $this->app->alias(NowPaymentsCurrencyCatalog::class, 'nowpayments.catalog');
        $this->app->alias(NowPaymentsService::class, 'nowpayments.service');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'nowpayments');
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        // Register ALL payment-gateway drivers with the core manager. Because this
        // only runs while the module is active, every gateway becomes
        // non-operational (and hidden) whenever the payments module is disabled.
        $manager = $this->app->make(PaymentGatewayManager::class);

        $manager->register(
            PaymentGatewayDriver::Zarinpal,
            fn (): ZarinpalGateway => $this->app->make(ZarinpalGateway::class),
        );
        $manager->register(
            PaymentGatewayDriver::CardToCard,
            fn (): CardToCardGateway => $this->app->make(CardToCardGateway::class),
        );
        $manager->register(
            PaymentGatewayDriver::NowPayments,
            fn (): NowPaymentsGateway => $this->app->make(NowPaymentsGateway::class),
        );
    }
}
