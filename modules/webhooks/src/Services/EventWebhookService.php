<?php

namespace Modules\Webhooks\Services;

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Modules\Webhooks\Jobs\DeliverEventWebhook;

/**
 * Turns account changes into events and queues their delivery.
 *
 * Each request carries the event name and an HMAC-SHA256 of the raw body made
 * with the shared secret, so the receiver can prove it came from this panel:
 *
 *   X-ShahPanel-Event: account.renewed
 *   X-ShahPanel-Signature: sha256=<hex hmac of the body>
 *
 * Delivery is queued after the database commit: a rolled-back purchase never
 * announces an account that does not exist.
 */
class EventWebhookService
{
    public const EVENTS = ['account.created', 'account.renewed', 'account.expired', 'account.status_changed', 'account.deleted'];

    public const LOG_KEY = 'webhooks:log';

    public static function url(): string
    {
        return (string) Setting::getValue('webhooks_url', '');
    }

    public static function secret(): string
    {
        $stored = (string) Setting::getValue('webhooks_secret_enc', '');

        return $stored === '' ? '' : (string) rescue(fn () => Crypt::decryptString($stored), '', false);
    }

    /**
     * @return list<string>
     */
    public static function enabledEvents(): array
    {
        return array_values(array_intersect(self::EVENTS, explode(',', (string) Setting::getValue('webhooks_events', implode(',', self::EVENTS)))));
    }

    public static function save(string $url, array $events, bool $newSecret): void
    {
        Setting::setValue('webhooks_url', $url);
        Setting::setValue('webhooks_events', implode(',', array_values(array_intersect(self::EVENTS, $events))));

        if ($newSecret || self::secret() === '') {
            Setting::setValue('webhooks_secret_enc', Crypt::encryptString(Str::random(48)));
        }
    }

    public function accountCreated(Account $account): void
    {
        $this->emit('account.created', $account);
    }

    public function accountUpdated(Account $account): void
    {
        if ($account->wasChanged('expiry_at') && $this->movedLater($account)) {
            $this->emit('account.renewed', $account);
        }

        if (! $account->wasChanged('status')) {
            return;
        }

        $this->emit($account->status === AccountStatus::Expired ? 'account.expired' : 'account.status_changed', $account, [
            'previous_status' => $this->statusValue($account->getOriginal('status')),
        ]);
    }

    public function accountDeleted(Account $account): void
    {
        $this->emit('account.deleted', $account);
    }

    public function emit(string $event, Account $account, array $extra = []): void
    {
        if (self::url() === '' || ! in_array($event, self::enabledEvents(), true)) {
            return;
        }

        DeliverEventWebhook::dispatch($this->payload($event, $account, $extra))->afterCommit();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(string $event, Account $account, array $extra = []): array
    {
        return [
            'id' => (string) Str::uuid(),
            'event' => $event,
            'occurred_at' => now()->toIso8601String(),
            'account' => array_merge([
                'id' => $account->id,
                'remote_username' => $account->remote_username,
                'display_label' => $account->display_label,
                'client_email' => $account->client_email,
                'service_type' => $this->statusValue($account->service_type),
                'status' => $this->statusValue($account->status),
                'server_id' => $account->server_id,
                'package_id' => $account->package_id,
                'owner_seller_id' => $account->owner_seller_id,
                'owner_agent_id' => $account->owner_agent_id,
                'client_user_id' => $account->client_user_id,
                'data_limit_bytes' => $account->data_limit_bytes,
                'data_used_bytes' => $account->data_used_bytes,
                'expiry_at' => $account->expiry_at?->toIso8601String(),
            ], $extra),
        ];
    }

    public static function sign(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    public static function log(array $entry): void
    {
        $log = Cache::get(self::LOG_KEY, []);
        array_unshift($log, $entry + ['at' => time()]);
        Cache::put(self::LOG_KEY, array_slice($log, 0, 30), now()->addDays(7));
    }

    protected function movedLater(Account $account): bool
    {
        $before = $account->getOriginal('expiry_at');

        return $account->expiry_at !== null && ($before === null || $account->expiry_at->gt($before));
    }

    protected function statusValue(mixed $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : ($value === null ? null : (string) $value);
    }
}
