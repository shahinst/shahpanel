<?php

namespace App\Services\Kyc;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class ApiIrClient
{
    public function __construct(protected string $apiKey) {}

    /**
     * @return array{success: bool, code: int|string|null, message: ?string, data: mixed}
     */
    public function echo(string $name = 'VPNPanel'): array
    {
        return $this->post('api/Sandbox/Echo', ['name' => $name]);
    }

    /**
     * @return array{success: bool, code: int|string|null, message: ?string, data: mixed}
     */
    public function shahkar(string $nationalCode, string $mobile): array
    {
        return $this->post('api/sw1/Shahkar', [
            'nationalCode' => $nationalCode,
            'mobile' => $mobile,
        ]);
    }

    /**
     * @return array{success: bool, code: int|string|null, message: ?string, data: mixed}
     */
    public function personInfo(string $nationalCode, string $birthDate): array
    {
        return $this->post('api/sw1/PersonInfo', [
            'nationalCode' => $nationalCode,
            'birthDate' => $birthDate,
        ]);
    }

    /**
     * Best-effort credit lookup. api.ir does not document a public credit endpoint,
     * so we probe configured paths and accept several payload shapes.
     */
    public function creditToman(): ?float
    {
        foreach ((array) config('kyc.api_ir.credit_paths', []) as $path) {
            foreach (['GET', 'POST'] as $method) {
                try {
                    $response = $method === 'GET'
                        ? $this->http()->get($this->url((string) $path))
                        : $this->http()->post($this->url((string) $path), new \stdClass());

                    if ($response->status() === 404) {
                        continue;
                    }

                    $payload = $this->decode($response);
                    $credit = $this->extractCreditAmount($payload);
                    if ($credit !== null) {
                        return $credit;
                    }
                } catch (ApiIrException) {
                    continue;
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        // Free Echo may still carry remaining credit in wrappers/headers on some accounts.
        try {
            $echo = $this->echo('credit-probe');
            $credit = $this->extractCreditAmount($echo);
            if ($credit !== null) {
                return $credit;
            }
        } catch (\Throwable) {
            // ignore
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{success: bool, code: int|string|null, message: ?string, data: mixed}
     */
    protected function post(string $path, array $body): array
    {
        try {
            $response = $this->http()->post($this->url($path), $body);
        } catch (ConnectionException $exception) {
            throw new ApiIrException('ارتباط با api.ir برقرار نشد: '.$exception->getMessage(), null, null);
        }

        return $this->decode($response);
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl((string) config('kyc.api_ir.base_url'))
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('kyc.api_ir.timeout_seconds', 45))
            ->connectTimeout((int) config('kyc.api_ir.connect_timeout_seconds', 15));
    }

    protected function url(string $path): string
    {
        return ltrim($path, '/');
    }

    /**
     * @return array{success: bool, code: int|string|null, message: ?string, data: mixed}
     */
    protected function decode(Response $response): array
    {
        $json = $response->json();
        if (! is_array($json)) {
            throw new ApiIrException(
                'پاسخ نامعتبر از api.ir (HTTP '.$response->status().')',
                $response->status(),
                $response->body()
            );
        }

        if ($response->failed() && ! array_key_exists('success', $json)) {
            $message = (string) ($json['message'] ?? $json['title'] ?? 'خطای api.ir');
            throw new ApiIrException($message, $response->status(), $json);
        }

        $success = (bool) ($json['success'] ?? $response->successful());
        if (! $success && $response->failed()) {
            throw new ApiIrException(
                (string) ($json['message'] ?? 'درخواست api.ir ناموفق بود'),
                $response->status(),
                $json
            );
        }

        // Capture credit-like headers when present.
        foreach (['X-Remain-Credit', 'X-Credit', 'X-Wallet-Balance', 'Remain-Credit'] as $header) {
            $headerValue = $response->header($header);
            if ($headerValue !== '' && is_numeric($headerValue)) {
                $json['_header_credit'] = (float) $headerValue;
            }
        }

        return [
            'success' => $success,
            'code' => $json['code'] ?? null,
            'message' => isset($json['message']) ? (is_string($json['message']) ? $json['message'] : null) : null,
            'data' => $json['data'] ?? null,
            '_raw' => $json,
        ];
    }

    protected function extractCreditAmount(mixed $payload): ?float
    {
        $candidates = [];
        $this->collectNumericCandidates($payload, $candidates, '');

        foreach ($candidates as $key => $value) {
            $normalizedKey = strtolower((string) $key);
            if (preg_match('/credit|balance|remain|wallet|شارژ|موجودی|مانده/', $normalizedKey)) {
                if ($value >= 0 && $value < 1_000_000_000_000) {
                    return (float) $value;
                }
            }
        }

        if (isset($payload['_header_credit']) && is_numeric($payload['_header_credit'])) {
            return (float) $payload['_header_credit'];
        }

        // Parse free-text messages like "مانده اعتبار: 125000"
        $messages = [];
        if (is_array($payload)) {
            foreach (['message', 'Message', 'error', 'Error'] as $msgKey) {
                if (isset($payload[$msgKey]) && is_string($payload[$msgKey])) {
                    $messages[] = $payload[$msgKey];
                }
            }
            if (isset($payload['_raw']) && is_array($payload['_raw'])) {
                foreach (['message', 'Message'] as $msgKey) {
                    if (isset($payload['_raw'][$msgKey]) && is_string($payload['_raw'][$msgKey])) {
                        $messages[] = $payload['_raw'][$msgKey];
                    }
                }
            }
        }

        foreach ($messages as $message) {
            if (preg_match_all('/(?:مانده|موجودی|شارژ|remain(?:ing)?|credit|balance)\D{0,12}([0-9۰-۹,]{3,})/ui', $message, $m)) {
                foreach ($m[1] as $raw) {
                    $normalized = preg_replace('/[^\d]/', '', strtr($raw, [
                        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                    ]));
                    if ($normalized !== null && $normalized !== '' && is_numeric($normalized)) {
                        return (float) $normalized;
                    }
                }
            }
        }

        if (is_array($payload) && isset($payload['_raw'])) {
            return $this->extractCreditAmount($payload['_raw']);
        }

        return null;
    }

    /**
     * @param  array<string, float>  $out
     */
    protected function collectNumericCandidates(mixed $node, array &$out, string $prefix): void
    {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_numeric($value)) {
                $out[$path] = (float) $value;
            } elseif (is_array($value)) {
                $this->collectNumericCandidates($value, $out, $path);
            }
        }
    }
}
