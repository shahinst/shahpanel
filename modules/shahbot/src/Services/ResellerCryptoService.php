<?php

namespace Modules\ShahBot\Services;

use App\Support\PaymentSettings;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Models\BotPayment;
use Modules\ShahBot\Models\BotUser;
use Throwable;

/**
 * A reseller's own NowPayments account.
 *
 * The crypto lands in the reseller's wallet, not the panel's, so the panel
 * treats a finished payment exactly like a card receipt the reseller approved:
 * the amount moves from the reseller's panel wallet to the customer, and an
 * attached order is bought on the spot (PaymentService::approve). The panel
 * never touches the reseller's keys beyond creating invoices and checking the
 * signature of the callbacks that come back.
 *
 * Every bot gets its own callback URL carrying the bot's secret, so one
 * reseller's NowPayments can never confirm a payment in another's bot.
 */
class ResellerCryptoService
{
    public const API = 'https://api.nowpayments.io/v1';

    /** NowPayments' sandbox: its own account and keys, no real money. */
    public const SANDBOX_API = 'https://api-sandbox.nowpayments.io/v1';

    public function endpoint(BotInstance $bot): string
    {
        return (($bot->settings ?? [])['np_sandbox'] ?? '') === '1' ? self::SANDBOX_API : self::API;
    }

    public function __construct(protected PaymentService $payments) {}

    public function configured(?BotInstance $bot): bool
    {
        return $bot !== null && $this->apiKey($bot) !== '' && $this->ipnSecret($bot) !== '';
    }

    public function apiKey(BotInstance $bot): string
    {
        return $this->decrypt(($bot->settings ?? [])['np_api_key_enc'] ?? null);
    }

    public function ipnSecret(BotInstance $bot): string
    {
        return $this->decrypt(($bot->settings ?? [])['np_ipn_secret_enc'] ?? null);
    }

    public function callbackUrl(BotInstance $bot): string
    {
        return route('shahbot.np.ipn', ['bot' => $bot->id, 'secret' => $bot->webhook_secret]);
    }

    /**
     * @return array{payment: BotPayment, url: string, usd: string}
     */
    public function invoice(BotInstance $bot, BotUser $user, float $toman, array $order): array
    {
        if (! $this->configured($bot)) {
            throw new InvalidArgumentException(__('shahbot::bot.np_not_configured'));
        }

        $rate = (float) PaymentSettings::usdtTomanRate();

        if ($rate <= 0) {
            throw new InvalidArgumentException(__('shahbot::bot.np_rate_missing'));
        }

        $usd = number_format(max(1, $toman / $rate), 2, '.', '');
        $payment = $this->payments->startForOrder($user, (string) $toman, $order);
        $payment->forceFill(['method' => 'nowpayments', 'status' => BotPayment::PENDING])->save();

        $response = Http::timeout(20)->withHeaders(['x-api-key' => $this->apiKey($bot)])->post($this->endpoint($bot).'/invoice', [
            'price_amount' => (float) $usd,
            'price_currency' => 'usd',
            'order_id' => 'sb-'.$payment->id,
            'order_description' => 'Order #'.$payment->id,
            'ipn_callback_url' => $this->callbackUrl($bot),
        ]);

        $url = (string) $response->json('invoice_url');

        if (! $response->successful() || $url === '') {
            $payment->forceFill(['status' => BotPayment::CANCELLED, 'reject_reason' => mb_substr((string) $response->body(), 0, 250)])->save();

            throw new InvalidArgumentException(__('shahbot::bot.np_invoice_failed', ['error' => (string) ($response->json('message') ?? $response->status())]));
        }

        $payment->forceFill(['external_id' => (string) $response->json('id')])->save();

        return ['payment' => $payment, 'url' => $url, 'usd' => $usd];
    }

    /**
     * Handles a callback from the reseller's NowPayments. Returns false for a
     * callback that does not prove it came from them.
     */
    public function handleIpn(BotInstance $bot, string $rawBody, string $signature): bool
    {
        $payload = json_decode($rawBody, true);

        if (! is_array($payload) || ! $this->configured($bot) || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha512', json_encode($this->sortKeys($payload), JSON_UNESCAPED_SLASHES), $this->ipnSecret($bot));

        if (! hash_equals($expected, strtolower($signature))) {
            return false;
        }

        if (! preg_match('/^sb-(\d+)$/', (string) ($payload['order_id'] ?? ''), $m)) {
            return true;
        }

        $payment = BotPayment::query()->whereKey((int) $m[1])->where('method', 'nowpayments')->first();

        // The payment must belong to a user of this very bot.
        if ($payment === null || (int) $payment->botUser?->bot_id !== (int) $bot->id) {
            return true;
        }

        $status = (string) ($payload['payment_status'] ?? '');

        if ($status === 'finished' && $payment->status === BotPayment::PENDING) {
            $this->payments->approve($payment, 'nowpayments:'.($payload['payment_id'] ?? ''));
        } elseif (in_array($status, ['failed', 'expired', 'refunded'], true) && $payment->status === BotPayment::PENDING) {
            $payment->forceFill(['status' => BotPayment::REJECTED, 'reject_reason' => 'nowpayments: '.$status])->save();
        }

        return true;
    }

    protected function sortKeys(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortKeys($value);
            }
        }

        return $data;
    }

    protected function decrypt(?string $value): string
    {
        try {
            return $value ? Crypt::decryptString($value) : '';
        } catch (Throwable) {
            return '';
        }
    }
}
