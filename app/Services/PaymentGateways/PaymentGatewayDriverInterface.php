<?php

namespace App\Services\PaymentGateways;

use App\Enums\PaymentGatewayDriver;

interface PaymentGatewayDriverInterface
{
    public function driver(): PaymentGatewayDriver;

    public function initiate(\App\Models\GatewayPayment $payment, \App\Models\PaymentGateway $gateway): GatewayInitiationResult;
}
