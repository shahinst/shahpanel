<?php

namespace Modules\NowPayments\Client;

use App\Models\PaymentGateway;
use Illuminate\Support\Facades\Cache;

class NowPaymentsCurrencyCatalog
{
    /**
     * @return list<array{code: string, name: string, enabled: bool}>
     */
    public function fetchAvailable(PaymentGateway $gateway, bool $forceRefresh = false): array
    {
        $cacheKey = 'nowpayments_currencies.'.$gateway->id.'.'.$gateway->mode->value;

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, (int) config('payment_gateways.nowpayments.currencies_cache_seconds', 3600), function () use ($gateway): array {
            $client = NowPaymentsClient::forGateway($gateway);

            try {
                return $this->normalizeFullCurrencies($client->getFullCurrencies());
            } catch (NowPaymentsApiException) {
                return $this->normalizeBasicCurrencies($client->getCurrencies());
            }
        });
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    public function userOptions(PaymentGateway $gateway): array
    {
        $enabledCodes = $gateway->enabledNowPaymentsPayCurrencies();

        if ($enabledCodes === []) {
            return [];
        }

        $available = [];

        if ($gateway->hasEncryptedConfigValue('api_key_enc')) {
            try {
                foreach ($this->fetchAvailable($gateway) as $currency) {
                    $available[$currency['code']] = $currency['name'];
                }
            } catch (\Throwable) {
                // Labels fall back to uppercase codes.
            }
        }

        $options = [];

        foreach ($enabledCodes as $code) {
            $options[] = [
                'code' => $code,
                'label' => $available[$code] ?? strtoupper($code),
            ];
        }

        return $options;
    }

    /**
     * @return array{estimated_amount: string, currency: string, amount_usd: string}|null
     */
    public function estimateUsdToCrypto(PaymentGateway $gateway, string $amountUsd, string $payCurrency): ?array
    {
        if (! $gateway->allowsNowPaymentsPayCurrency($payCurrency)) {
            return null;
        }

        try {
            $client = NowPaymentsClient::forGateway($gateway);
            $response = $client->estimate((float) $amountUsd, 'usd', strtolower($payCurrency));
            $estimated = $response['estimated_amount'] ?? null;

            if ($estimated === null || $estimated === '') {
                return null;
            }

            return [
                'estimated_amount' => (string) $estimated,
                'currency' => strtolower($payCurrency),
                'amount_usd' => number_format((float) $amountUsd, 8, '.', ''),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array{code: string, name: string, enabled: bool}>
     */
    protected function normalizeFullCurrencies(array $body): array
    {
        $items = $body['currencies'] ?? $body;

        if (! is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (is_string($item)) {
                $code = strtolower(trim($item));
                if ($code !== '') {
                    $normalized[$code] = ['code' => $code, 'name' => strtoupper($code), 'enabled' => true];
                }

                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            $code = strtolower(trim((string) ($item['code'] ?? $item['currency'] ?? '')));

            if ($code === '') {
                continue;
            }

            $name = trim((string) ($item['name'] ?? $item['title'] ?? strtoupper($code)));
            $enabled = (bool) ($item['enabled'] ?? $item['enable'] ?? $item['is_available'] ?? true);

            $normalized[$code] = [
                'code' => $code,
                'name' => $name !== '' ? $name : strtoupper($code),
                'enabled' => $enabled,
            ];
        }

        ksort($normalized);

        return array_values($normalized);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<array{code: string, name: string, enabled: bool}>
     */
    protected function normalizeBasicCurrencies(array $body): array
    {
        $items = $body['currencies'] ?? $body;
        $normalized = [];

        if (! is_array($items)) {
            return [];
        }

        foreach ($items as $item) {
            if (! is_string($item)) {
                continue;
            }

            $code = strtolower(trim($item));

            if ($code === '') {
                continue;
            }

            $normalized[$code] = [
                'code' => $code,
                'name' => strtoupper($code),
                'enabled' => true,
            ];
        }

        ksort($normalized);

        return array_values($normalized);
    }
}
