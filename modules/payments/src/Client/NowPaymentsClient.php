<?php

namespace Modules\NowPayments\Client;

use App\Enums\PaymentGatewayMode;
use App\Models\PaymentGateway;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class NowPaymentsClient
{
    public function __construct(
        protected string $apiKey,
        protected PaymentGatewayMode $mode,
    ) {}

    public static function forGateway(PaymentGateway $gateway): self
    {
        $apiKey = $gateway->encryptedConfigValue('api_key_enc');

        if ($apiKey === null || $apiKey === '') {
            throw new NowPaymentsApiException(__('payment_gateways.nowpayments_api_key_missing'));
        }

        return new self($apiKey, $gateway->mode);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createInvoice(array $payload): array
    {
        return $this->request('post', '/invoice', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPaymentStatus(string $paymentId): array
    {
        return $this->request('get', '/payment/'.urlencode($paymentId));
    }

    /**
     * @return array<string, mixed>
     */
    public function getCurrencies(): array
    {
        return $this->request('get', '/currencies');
    }

    /**
     * @return array<string, mixed>
     */
    public function getFullCurrencies(): array
    {
        return $this->request('get', '/full-currencies');
    }

    /**
     * @return array<string, mixed>
     */
    public function estimate(float $amount, string $currencyFrom, string $currencyTo): array
    {
        return $this->request('get', '/estimate', [
            'amount' => $amount,
            'currency_from' => strtolower($currencyFrom),
            'currency_to' => strtolower($currencyTo),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, array $payload = []): array
    {
        $url = $this->baseUrl().$path;

        if ($method === 'get' && $payload !== []) {
            $url .= '?'.http_build_query($payload);
            $payload = [];
        }

        $response = Http::timeout((int) config('payment_gateways.nowpayments.timeout_seconds', 30))
            ->acceptJson()
            ->withHeaders([
                'x-api-key' => $this->apiKey,
            ])
            ->{$method}($url, $payload);

        return $this->parseResponse($response);
    }

    protected function baseUrl(): string
    {
        if ($this->mode->isLive()) {
            return rtrim((string) config('payment_gateways.nowpayments.live_base_url', 'https://api.nowpayments.io/v1'), '/');
        }

        return rtrim((string) config('payment_gateways.nowpayments.sandbox_base_url', 'https://api-sandbox.nowpayments.io/v1'), '/');
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseResponse(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw new NowPaymentsApiException(
                __('payment_gateways.nowpayments_invalid_response'),
                $response->status(),
            );
        }

        if (! $response->successful()) {
            $message = (string) ($body['message'] ?? $body['error'] ?? __('payment_gateways.nowpayments_request_failed'));

            throw new NowPaymentsApiException($message, $response->status(), $body);
        }

        return $body;
    }
}
