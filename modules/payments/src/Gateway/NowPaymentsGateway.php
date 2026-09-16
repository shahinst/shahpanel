<?php

namespace Modules\NowPayments\Gateway;

use App\Enums\PaymentGatewayDriver;
use App\Enums\UserRole;
use App\Models\GatewayPayment;
use App\Models\PaymentGateway;
use App\Services\PaymentGateways\GatewayInitiationResult;
use App\Services\PaymentGateways\PaymentGatewayDriverInterface;
use App\Services\PaymentGateways\PaymentGatewayException;
use Modules\NowPayments\Client\NowPaymentsApiException;
use Modules\NowPayments\Client\NowPaymentsClient;

class NowPaymentsGateway implements PaymentGatewayDriverInterface
{
    public function driver(): PaymentGatewayDriver
    {
        return PaymentGatewayDriver::NowPayments;
    }

    public function initiate(GatewayPayment $payment, PaymentGateway $gateway): GatewayInitiationResult
    {
        $client = NowPaymentsClient::forGateway($gateway);
        $payCurrency = strtolower(trim((string) ($payment->pay_currency ?? '')));

        if ($payCurrency === '' || ! $gateway->allowsNowPaymentsPayCurrency($payCurrency)) {
            throw new PaymentGatewayException(__('payment_gateways.pay_currency_not_allowed'));
        }

        $payload = [
            'price_amount' => (float) $payment->amount_usdt,
            'price_currency' => 'usd',
            'pay_currency' => $payCurrency,
            'order_id' => $payment->uuid,
            'order_description' => __('payment_gateways.nowpayments_order_description', [
                'amount' => persian_digits(number_format((float) $payment->amount_usdt, 2)),
            ]),
            'ipn_callback_url' => route('webhooks.nowpayments'),
            'success_url' => route($this->panelRouteName($payment).'.return', [
                'gatewayPayment' => $payment->uuid,
                'status' => 'success',
            ]),
            'cancel_url' => route($this->panelRouteName($payment).'.return', [
                'gatewayPayment' => $payment->uuid,
                'status' => 'cancel',
            ]),
        ];

        // Freezing the rate for 10 minutes is what protects the invoiced USD
        // value from drifting before the customer pays, so it belongs on live
        // invoices -- it used to be set only in sandbox, which is backwards.
        $payload['is_fixed_rate'] = true;
        $payload['is_fee_paid_by_user'] = false;

        try {
            $response = $client->createInvoice($payload);
        } catch (NowPaymentsApiException $exception) {
            throw new PaymentGatewayException($exception->getMessage(), previous: $exception);
        }

        return new GatewayInitiationResult(
            externalPaymentId: isset($response['payment_id']) ? (string) $response['payment_id'] : null,
            externalInvoiceId: isset($response['id']) ? (string) $response['id'] : null,
            payAddress: isset($response['pay_address']) ? (string) $response['pay_address'] : null,
            payCurrency: isset($response['pay_currency']) ? (string) $response['pay_currency'] : $payCurrency,
            payAmount: isset($response['pay_amount']) ? (string) $response['pay_amount'] : null,
            invoiceUrl: isset($response['invoice_url']) ? (string) $response['invoice_url'] : null,
            rawResponse: $response,
        );
    }

    protected function panelRouteName(GatewayPayment $payment): string
    {
        $user = $payment->user;

        return match ($user->role) {
            UserRole::Agent => 'agent.wallet.top-up',
            UserRole::Seller => 'seller.wallet.top-up',
            UserRole::Client => 'client.wallet.top-up',
            default => 'agent.wallet.top-up',
        };
    }
}
