<?php

namespace App\Services\PaymentGateways;

use App\Enums\PaymentGatewayDriver;
use App\Models\GatewayPayment;
use App\Models\PaymentGateway;

class CardToCardGateway implements PaymentGatewayDriverInterface
{
    public function driver(): PaymentGatewayDriver
    {
        return PaymentGatewayDriver::CardToCard;
    }

    public function initiate(GatewayPayment $payment, PaymentGateway $gateway): GatewayInitiationResult
    {
        return new GatewayInitiationResult(
            externalPaymentId: null,
            externalInvoiceId: null,
            payAddress: null,
            payCurrency: 'IRT',
            payAmount: (string) $payment->gross_toman,
            invoiceUrl: null,
            rawResponse: [
                'card_number' => $gateway->configValue('card_number'),
                'card_holder' => $gateway->configValue('card_holder'),
                'bank_name' => $gateway->configValue('bank_name'),
            ],
        );
    }
}
