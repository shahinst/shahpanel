<?php

namespace App\Services\PaymentGateways;

class GatewayInitiationResult
{
    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public readonly ?string $externalPaymentId,
        public readonly ?string $externalInvoiceId,
        public readonly ?string $payAddress,
        public readonly ?string $payCurrency,
        public readonly ?string $payAmount,
        public readonly ?string $invoiceUrl,
        public readonly array $rawResponse = [],
    ) {}
}
