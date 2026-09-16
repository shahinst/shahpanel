<?php

namespace App\Services\PaymentGateways;

use App\Enums\PaymentGatewayDriver;
use App\Enums\UserRole;
use App\Models\GatewayPayment;
use App\Models\PaymentGateway;
use App\Services\PaymentGateways\Zarinpal\ZarinpalApiException;
use App\Services\PaymentGateways\Zarinpal\ZarinpalClient;

class ZarinpalGateway implements PaymentGatewayDriverInterface
{
    public function driver(): PaymentGatewayDriver
    {
        return PaymentGatewayDriver::Zarinpal;
    }

    public function initiate(GatewayPayment $payment, PaymentGateway $gateway): GatewayInitiationResult
    {
        $client = ZarinpalClient::forGateway($gateway);
        $amountRial = (int) bcmul((string) $payment->gross_toman, '10', 0);

        $payload = [
            'amount' => $amountRial,
            'currency' => 'IRR',
            'callback_url' => route('webhooks.zarinpal'),
            'description' => __('payment_gateways.zarinpal_order_description', [
                'amount' => persian_digits(number_format((float) $payment->gross_toman, 0)),
            ]),
            'metadata' => [
                'order_id' => $payment->uuid,
                'email' => $payment->user->email ?? '',
                'mobile' => $payment->user->mobile ?? '',
            ],
        ];

        try {
            $response = $client->requestPayment($payload);
        } catch (ZarinpalApiException $exception) {
            throw new PaymentGatewayException($exception->getMessage(), previous: $exception);
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $authority = (string) ($data['authority'] ?? '');
        $code = (int) ($data['code'] ?? 0);

        if ($authority === '' || ! in_array($code, [100, 101], true)) {
            throw new PaymentGatewayException(__('payment_gateways.zarinpal_request_failed'));
        }

        return new GatewayInitiationResult(
            externalPaymentId: $authority,
            externalInvoiceId: null,
            payAddress: null,
            payCurrency: 'IRR',
            payAmount: (string) $amountRial,
            invoiceUrl: $client->startPayUrl($authority),
            rawResponse: $response,
        );
    }

    protected function panelRouteName(GatewayPayment $payment): string
    {
        return match ($payment->user->role) {
            UserRole::Agent => 'agent.wallet.top-up',
            UserRole::Seller => 'seller.wallet.top-up',
            UserRole::Client => 'client.wallet.top-up',
            default => 'agent.wallet.top-up',
        };
    }
}
