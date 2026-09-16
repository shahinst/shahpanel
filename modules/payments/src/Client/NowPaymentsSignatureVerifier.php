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
        $expected = strtolower(trim($signature));

        // NowPayments signs with Node's JSON.stringify, which writes non-ASCII as
        // raw UTF-8, while PHP's json_encode escapes it to \uXXXX unless told not
        // to. Any IPN carrying a Persian order description therefore failed to
        // verify and the customer's wallet was never credited. Their own docs are
        // self-inconsistent here (the Node sample emits UTF-8, the Python sample
        // escapes), so both encodings are accepted. That costs nothing: forging
        // either one still requires the IPN secret.
        foreach ([JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES] as $flags) {
            $calculated = hash_hmac('sha512', (string) json_encode($sorted, $flags), trim($ipnSecret));

            if (hash_equals(strtolower($calculated), $expected)) {
                return true;
            }
        }

        return false;
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
