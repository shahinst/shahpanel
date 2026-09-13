<?php

namespace App\Services\PaymentGateways\Zarinpal;

use App\Enums\PaymentGatewayMode;
use App\Models\PaymentGateway;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ZarinpalApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?array $responseBody = null,
    ) {
        parent::__construct($message);
    }
}

class ZarinpalClient
{
    public function __construct(
        protected string $merchantId,
        protected PaymentGatewayMode $mode,
    ) {}

    public static function forGateway(PaymentGateway $gateway): self
    {
        $merchantId = $gateway->encryptedConfigValue('merchant_id_enc');

        if ($merchantId === null || trim($merchantId) === '') {
            throw new ZarinpalApiException(__('payment_gateways.zarinpal_merchant_missing'));
        }

        return new self(trim($merchantId), $gateway->mode);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function requestPayment(array $payload): array
    {
        return $this->request('post', '/payment/request.json', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function verifyPayment(array $payload): array
    {
        return $this->request('post', '/payment/verify.json', $payload);
    }

    public function startPayUrl(string $authority): string
    {
        $base = $this->mode->isLive()
            ? (string) config('payment_gateways.zarinpal.live_start_pay_url', 'https://www.zarinpal.com/pg/StartPay/')
            : (string) config('payment_gateways.zarinpal.sandbox_start_pay_url', 'https://sandbox.zarinpal.com/pg/StartPay/');

        return rtrim($base, '/').'/'.urlencode($authority);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, array $payload = []): array
    {
        $body = array_merge(['merchant_id' => $this->merchantId], $payload);

        $response = Http::timeout((int) config('payment_gateways.zarinpal.timeout_seconds', 30))
            ->acceptJson()
            ->asJson()
            ->{$method}($this->baseUrl().$path, $body);

        return $this->parseResponse($response);
    }

    protected function baseUrl(): string
    {
        if ($this->mode->isLive()) {
            return rtrim((string) config('payment_gateways.zarinpal.live_base_url', 'https://api.zarinpal.com/pg/v4'), '/');
        }

        return rtrim((string) config('payment_gateways.zarinpal.sandbox_base_url', 'https://sandbox.zarinpal.com/pg/v4'), '/');
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseResponse(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw new ZarinpalApiException(
                __('payment_gateways.zarinpal_invalid_response'),
                $response->status(),
            );
        }

        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $errors = $body['errors'] ?? null;
        $code = (int) ($data['code'] ?? $body['code'] ?? 0);

        if (! $response->successful() || ($errors !== null && $errors !== [] && $code <= 0)) {
            $message = $this->extractErrorMessage($body);

            throw new ZarinpalApiException($message, $response->status(), $body);
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function extractErrorMessage(array $body): string
    {
        $errors = $body['errors'] ?? null;

        if (is_array($errors) && isset($errors['message'])) {
            return (string) $errors['message'];
        }

        if (is_array($errors) && isset($errors[0]['message'])) {
            return (string) $errors[0]['message'];
        }

        return (string) ($body['message'] ?? __('payment_gateways.zarinpal_request_failed'));
    }
}
