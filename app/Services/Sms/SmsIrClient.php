<?php

namespace App\Services\Sms;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class SmsIrClient
{
    public function __construct(protected string $apiKey) {}

    /**
     * @return array{status: int, message: string, data: mixed}
     */
    public function getCredit(): array
    {
        return $this->decode($this->request('GET', 'credit'));
    }

    /**
     * @return list<int|string>
     */
    public function getLines(): array
    {
        $payload = $this->decode($this->request('GET', 'line'));
        $data = $payload['data'] ?? [];

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * @param  list<string>  $mobiles
     * @return array{packId: ?string, messageIds: list<int|null>, cost: float|null}
     */
    /**
     * @param  list<array{name: string, value: string}>  $parameters
     * @return array{messageId: int|null, cost: float|null}
     */
    public function sendVerify(string $mobile, int $templateId, array $parameters): array
    {
        $body = [
            'mobile' => $mobile,
            'templateId' => $templateId,
            'parameters' => array_values(array_map(
                static fn (array $parameter): array => [
                    'name' => $parameter['name'],
                    'value' => (string) $parameter['value'],
                ],
                $parameters,
            )),
        ];

        $payload = $this->decode($this->request('POST', 'send/verify', $body));
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return [
            'messageId' => isset($data['messageId']) ? (int) $data['messageId'] : null,
            'cost' => isset($data['cost']) ? (float) $data['cost'] : null,
        ];
    }

    /**
     * @return list<array{id: int, title: string, text: ?string}>
     */
    public function getVerifyTemplates(): array
    {
        foreach ((array) config('sms.sms_ir.template_list_paths', []) as $path) {
            try {
                $payload = $this->decode($this->request('GET', (string) $path));
                $normalized = $this->normalizeVerifyTemplates($payload['data'] ?? null);

                if ($normalized !== []) {
                    return $normalized;
                }
            } catch (SmsIrApiException) {
                continue;
            }
        }

        return [];
    }

    /**
     * @return list<array{id: int, title: string, text: ?string}>
     */
    protected function normalizeVerifyTemplates(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }

        $items = array_is_list($data) ? $data : [$data];
        $templates = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $item['id']
                ?? $item['templateId']
                ?? $item['TemplateId']
                ?? $item['ID']
                ?? null;

            if (! is_numeric($id)) {
                continue;
            }

            $title = (string) ($item['title']
                ?? $item['name']
                ?? $item['Title']
                ?? $item['Name']
                ?? $id);

            $text = $item['text']
                ?? $item['message']
                ?? $item['MessageText']
                ?? $item['templateText']
                ?? $item['TemplateText']
                ?? null;

            $templates[] = [
                'id' => (int) $id,
                'title' => $title,
                'text' => is_string($text) ? $text : null,
            ];
        }

        return $templates;
    }

    public function sendBulk(int|string $lineNumber, string $messageText, array $mobiles, ?int $sendDateTime = null): array
    {
        $body = [
            'lineNumber' => is_numeric($lineNumber) ? (int) $lineNumber : $lineNumber,
            'messageText' => $messageText,
            'mobiles' => array_values($mobiles),
        ];

        if ($sendDateTime !== null) {
            $body['sendDateTime'] = $sendDateTime;
        }

        $payload = $this->decode($this->request('POST', 'send/bulk', $body));
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return [
            'packId' => isset($data['packId']) ? (string) $data['packId'] : null,
            'messageIds' => is_array($data['messageIds'] ?? null) ? $data['messageIds'] : [],
            'cost' => isset($data['cost']) ? (float) $data['cost'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status: int, message: string, data: mixed}
     */
    protected function decode(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json)) {
            throw new SmsIrApiException(__('services.smsir_invalid_response', ['status' => $response->status()]));
        }

        $status = (int) ($json['status'] ?? 0);
        $message = (string) ($json['message'] ?? '');

        if ($response->failed()) {
            throw new SmsIrApiException($message !== '' ? $message : __('services.http_error', ['status' => $response->status()]));
        }

        if ($status !== 1) {
            throw new SmsIrApiException($message !== '' ? $message : __('services.smsir_error', ['status' => $status]));
        }

        return [
            'status' => $status,
            'message' => $message,
            'data' => $json['data'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function request(string $method, string $path, array $body = []): Response
    {
        $url = config('sms.sms_ir.base_url').'/'.ltrim($path, '/');

        try {
            $pending = $this->http();
            $response = match (strtoupper($method)) {
                'GET' => $pending->get($url),
                'POST' => $pending->post($url, $body),
                'DELETE' => $pending->delete($url, $body),
                default => throw new SmsIrApiException(__('services.http_method_unsupported_plain')),
            };
        } catch (ConnectionException $exception) {
            throw new SmsIrApiException(__('services.smsir_connect_failed', ['error' => $exception->getMessage()]), 0, $exception);
        }

        return $response;
    }

    protected function http(): PendingRequest
    {
        return Http::timeout((int) config('sms.sms_ir.timeout_seconds', 30))
            ->connectTimeout((int) config('sms.sms_ir.connect_timeout_seconds', 15))
            ->acceptJson()
            ->withHeaders([
                'X-API-KEY' => $this->apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ]);
    }
}
