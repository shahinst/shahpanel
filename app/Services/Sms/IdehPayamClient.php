<?php

namespace App\Services\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * IdehPayam (ایده پیام) REST web service.
 *
 *   POST {base}/sms/send
 *   headers: username, password, Content-Type: application/json
 *   body:    {"from": "<line>", "recipients": ["09..."], "message": "...", "type": 0}
 *
 * The base URL is a setting because the provider publishes it as a bare IP
 * that has changed before.
 */
final class IdehPayamClient
{
    public function __construct(
        protected string $baseUrl,
        protected string $username,
        protected string $password,
    ) {}

    /**
     * @param  list<string>  $recipients
     * @return array{ids: list<string>, raw: mixed}
     */
    public function send(string $from, array $recipients, string $message, int $type = 0): array
    {
        $payload = $this->decode($this->post('sms/send', [
            'from' => $from,
            'recipients' => array_values($recipients),
            'message' => $message,
            'type' => $type,
        ]));

        return [
            'ids' => $this->extractIds($payload),
            'raw' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function post(string $path, array $body): Response
    {
        try {
            return Http::timeout((int) config('sms.idehpayam.timeout_seconds', 30))
                ->connectTimeout((int) config('sms.idehpayam.connect_timeout_seconds', 15))
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'username' => $this->username,
                    'password' => $this->password,
                ])
                ->post(rtrim($this->baseUrl, '/').'/'.ltrim($path, '/'), $body);
        } catch (ConnectionException $exception) {
            throw new SmsApiException(__('sms.idehpayam_connect_failed', ['error' => $exception->getMessage()]), 0, $exception);
        }
    }

    protected function decode(Response $response): mixed
    {
        $json = $response->json();
        $message = is_array($json) ? $this->errorText($json) : null;

        if ($response->failed()) {
            throw new SmsApiException($message ?? __('services.http_error', ['status' => $response->status()]));
        }

        // The service answers 200 with a status flag when it refuses a send
        // (wrong credentials, no credit, bad line), so the body decides.
        if (is_array($json)) {
            $status = $json['status'] ?? $json['success'] ?? $json['isSuccess'] ?? null;

            if ($status === false || (is_numeric($status) && (int) $status < 0) || (is_string($status) && in_array(strtolower($status), ['error', 'failed', 'false'], true))) {
                throw new SmsApiException($message ?? __('sms.idehpayam_rejected'));
            }
        }

        return $json ?? $response->body();
    }

    /**
     * @param  array<string, mixed>  $json
     */
    protected function errorText(array $json): ?string
    {
        foreach (['message', 'Message', 'error', 'errorMessage', 'detail'] as $key) {
            if (is_string($json[$key] ?? null) && trim($json[$key]) !== '') {
                return trim($json[$key]);
            }
        }

        return null;
    }

    /**
     * Message ids, from whichever shape the response uses.
     *
     * @return list<string>
     */
    protected function extractIds(mixed $payload): array
    {
        if (is_scalar($payload) && trim((string) $payload) !== '') {
            return [trim((string) $payload)];
        }

        if (! is_array($payload)) {
            return [];
        }

        foreach (['data', 'result', 'ids', 'messageIds', 'recIds'] as $key) {
            if (array_key_exists($key, $payload)) {
                return $this->extractIds($payload[$key]);
            }
        }

        if (array_is_list($payload)) {
            return array_values(array_map('strval', array_filter($payload, 'is_scalar')));
        }

        foreach (['id', 'messageId', 'recId'] as $key) {
            if (is_scalar($payload[$key] ?? null)) {
                return [(string) $payload[$key]];
            }
        }

        return [];
    }
}
