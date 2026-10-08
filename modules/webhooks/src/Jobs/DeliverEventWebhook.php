<?php

namespace Modules\Webhooks\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Modules\Webhooks\Services\EventWebhookService;

/**
 * Posts one event. A receiver that is down gets it again later; only a 2xx
 * answer counts as delivered. The event id lets the receiver drop repeats.
 */
class DeliverEventWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 6;

    public function __construct(public array $payload) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(): void
    {
        $url = EventWebhookService::url();

        if ($url === '') {
            return;
        }

        $body = json_encode($this->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'ShahPanel-Webhooks',
                    'X-ShahPanel-Event' => $this->payload['event'],
                    'X-ShahPanel-Delivery' => $this->payload['id'],
                    'X-ShahPanel-Signature' => EventWebhookService::sign($body, EventWebhookService::secret()),
                ])
                ->withBody($body, 'application/json')
                ->post($url);
            $status = $response->status();
        } catch (\Throwable $exception) {
            $status = 0;
        }

        $ok = $status >= 200 && $status < 300;
        EventWebhookService::log(['event' => $this->payload['event'], 'status' => $status, 'ok' => $ok, 'attempt' => $this->attempts()]);

        if (! $ok) {
            $this->release($this->backoff()[min($this->attempts() - 1, 4)]);
        }
    }
}
