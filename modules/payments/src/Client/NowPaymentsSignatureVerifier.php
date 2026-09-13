<?php

namespace Modules\NowPayments\Client;

class NowPaymentsSignatureVerifier
{
    public function verify(string $rawBody, ?string $signature, string $ipnSecret): bool
    {
        if ($signature === null || trim($signature) === '') {
            return false;
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return false;
        }

        $sorted = $this->sortPayload($payload);
        $encoded = json_encode($sorted, JSON_UNESCAPED_SLASHES);
        $calculated = hash_hmac('sha512', (string) $encoded, trim($ipnSecret));

        return hash_equals(strtolower($calculated), strtolower(trim($signature)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function sortPayload(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sortPayload($value);
            }
        }

        return $payload;
    }
}
