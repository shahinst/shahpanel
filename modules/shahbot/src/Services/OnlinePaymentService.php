<?php

namespace Modules\ShahBot\Services;

use App\Enums\GatewayPaymentStatus;
use App\Enums\PaymentGatewayDriver;
use App\Enums\TransactionType;
use App\Models\GatewayPayment;
use App\Models\PaymentGateway;
use App\Services\PaymentGateways\GatewayPaymentService;
use App\Services\PaymentGateways\PaymentGatewayException;
use App\Services\WalletService;
use App\Support\PaymentSettings;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\NowPayments\NowPaymentsService;
use Modules\ShahBot\Models\BotGatewayPayment;
use Modules\ShahBot\Models\BotPayment;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Support\BotSettings;
use Modules\ShahBot\Telegram\TelegramClient;
use Throwable;

/**
 * Wallet top-ups that need no admin: the panel's own online gateways
 * (ZarinPal, crypto through NowPayments) and Telegram Stars.
 *
 * Gateway payments run through GatewayPaymentService exactly as a top-up in
 * the client portal does, and credit the bot user's client wallet when the
 * gateway confirms. Stars are paid to the bot inside Telegram and credited at
 * the configured toman-per-star rate.
 */
class OnlinePaymentService
{
    public const CARD = 'card';

    public const ZARINPAL = 'zp';

    public const CRYPTO = 'cr';

    public const STARS = 'st';

    public function __construct(
        protected BotSettings $settings,
        protected BotUserService $users,
        protected WalletService $wallets,
        protected TelegramClient $telegram,
        protected BotNotifier $notifier,
    ) {}

    /**
     * Payment methods open right now, in menu order.
     *
     * @return list<string>
     */
    public function methods(): array
    {
        $methods = [];

        if ($this->gatewayReady(PaymentGatewayDriver::Zarinpal) && $this->settings->bool('pay_zarinpal')) {
            $methods[] = self::ZARINPAL;
        }

        if ($this->settings->bool('pay_crypto') && $this->cryptoCurrencies() !== []) {
            $methods[] = self::CRYPTO;
        }

        if ($this->settings->bool('pay_stars') && $this->starsRate() > 0) {
            $methods[] = self::STARS;
        }

        if ($this->settings->bool('topup_enabled') && $this->settings->get('card_number') !== '') {
            $methods[] = self::CARD;
        }

        return $methods;
    }

    protected function gatewayReady(PaymentGatewayDriver $driver): bool
    {
        if (! module_active('payments')) {
            return false;
        }

        try {
            return (bool) PaymentGateway::findByDriver($driver)?->isOperational();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function cryptoCurrencies(): array
    {
        if (! $this->gatewayReady(PaymentGatewayDriver::NowPayments) || ! PaymentSettings::hasUsdtTomanRate()) {
            return [];
        }

        return array_slice(PaymentGateway::findByDriver(PaymentGatewayDriver::NowPayments)?->enabledNowPaymentsPayCurrencies() ?? [], 0, 12);
    }

    public function starsRate(): float
    {
        return (float) western_digits($this->settings->get('stars_rate'));
    }

    public function starsFor(float $toman): int
    {
        $rate = $this->starsRate();

        return $rate > 0 ? max(1, (int) ceil($toman / $rate)) : 0;
    }

    // ------------------------------------------------------------------
    // Gateways
    // ------------------------------------------------------------------

    public function startZarinpal(BotUser $user, float $toman): GatewayPayment
    {
        $this->assertMethod(self::ZARINPAL);

        return $this->link($user, fn () => app(GatewayPaymentService::class)
            ->createZarinpalTopUp($this->users->client($user), number_format($toman, 2, '.', '')));
    }

    public function startCrypto(BotUser $user, float $toman, string $currency): GatewayPayment
    {
        $this->assertMethod(self::CRYPTO);

        if (! in_array($currency, $this->cryptoCurrencies(), true)) {
            throw new InvalidArgumentException(__('shahbot::bot.pay_currency_invalid'));
        }

        $rate = (float) PaymentSettings::usdtTomanRate();
        $usdt = number_format($toman / max($rate, 1), 2, '.', '');

        return $this->link($user, fn () => app(NowPaymentsService::class)
            ->createTopUp($this->users->client($user), $usdt, $currency));
    }

    /**
     * @param  callable(): GatewayPayment  $create
     */
    protected function link(BotUser $user, callable $create): GatewayPayment
    {
        try {
            $payment = $create();
        } catch (PaymentGatewayException $e) {
            throw new InvalidArgumentException($e->getMessage(), previous: $e);
        }

        BotGatewayPayment::query()->create([
            'bot_user_id' => $user->id,
            'gateway_payment_id' => $payment->id,
        ]);

        if (blank($payment->invoice_url)) {
            throw new InvalidArgumentException(__('shahbot::bot.pay_link_missing'));
        }

        return $payment;
    }

    /**
     * Tells bot users about gateway payments that have finished since the last
     * run. Called every minute and from the return page.
     */
    public function notifyFinished(?int $gatewayPaymentId = null): int
    {
        $sent = 0;

        BotGatewayPayment::query()
            ->whereNull('notified_at')
            ->when($gatewayPaymentId !== null, fn ($q) => $q->where('gateway_payment_id', $gatewayPaymentId))
            ->with(['gatewayPayment', 'botUser'])
            ->limit(200)
            ->get()
            ->each(function (BotGatewayPayment $link) use (&$sent): void {
                $payment = $link->gatewayPayment;

                if ($payment === null) {
                    $link->update(['notified_at' => now()]);

                    return;
                }

                // Unfinished payments are given up on after two days.
                if (! $payment->status->isTerminal() && $link->created_at->gt(now()->subDays(2))) {
                    return;
                }

                // Claim it first so two runs never send the message twice.
                if (BotGatewayPayment::query()->whereKey($link->id)->whereNull('notified_at')->update(['notified_at' => now()]) === 0) {
                    return;
                }

                if ($payment->status === GatewayPaymentStatus::Completed) {
                    $this->notifier->user($link->botUser, fn () => __('shahbot::bot.payment_approved', [
                        'amount' => format_money($payment->net_toman, 'IRT'),
                        'balance' => format_money($this->users->balance($link->botUser)),
                    ]));
                    $sent++;
                } elseif ($payment->status->isTerminal()) {
                    $this->notifier->user($link->botUser, fn () => __('shahbot::bot.pay_failed', [
                        'amount' => format_money($payment->gross_toman, 'IRT'),
                    ]));
                    $sent++;
                }
            });

        return $sent;
    }

    // ------------------------------------------------------------------
    // Telegram Stars
    // ------------------------------------------------------------------

    public function startStars(BotUser $user, float $toman): BotPayment
    {
        $this->assertMethod(self::STARS);
        $stars = $this->starsFor($toman);

        $payment = BotPayment::query()->create([
            'bot_user_id' => $user->id,
            'amount' => number_format($toman, 2, '.', ''),
            'stars' => $stars,
            'method' => 'stars',
            'status' => BotPayment::AWAITING_RECEIPT,
        ]);

        $result = $this->telegram->call('sendInvoice', [
            'chat_id' => $user->telegram_id,
            'title' => __('shahbot::bot.stars_title'),
            'description' => __('shahbot::bot.stars_description', ['amount' => format_money($toman), 'stars' => $stars]),
            'payload' => 'stars:'.$payment->id,
            'currency' => 'XTR',
            'prices' => json_encode([['label' => __('shahbot::bot.stars_label'), 'amount' => $stars]]),
        ]);

        if (! ($result['ok'] ?? false)) {
            $payment->update(['status' => BotPayment::CANCELLED]);

            throw new InvalidArgumentException(__('shahbot::bot.pay_link_missing'));
        }

        return $payment;
    }

    /**
     * Telegram asks, right before charging, whether the order still stands.
     */
    public function answerPreCheckout(array $query): void
    {
        $payment = $this->starsPayment((string) ($query['invoice_payload'] ?? ''));
        $ok = $payment !== null
            && $payment->status === BotPayment::AWAITING_RECEIPT
            && ($query['currency'] ?? '') === 'XTR'
            && (int) ($query['total_amount'] ?? 0) === (int) $payment->stars
            && (int) $payment->botUser?->telegram_id === (int) ($query['from']['id'] ?? 0);

        $this->telegram->call('answerPreCheckoutQuery', [
            'pre_checkout_query_id' => (string) ($query['id'] ?? ''),
            'ok' => $ok ? 'true' : 'false',
            'error_message' => $ok ? null : __('shahbot::bot.pay_expired'),
        ], 10);
    }

    /**
     * The charge went through: credit the wallet once per Telegram charge id.
     */
    public function completeStars(BotUser $user, array $successful): ?BotPayment
    {
        $payment = $this->starsPayment((string) ($successful['invoice_payload'] ?? ''));
        $chargeId = (string) ($successful['telegram_payment_charge_id'] ?? '');

        if ($payment === null || $chargeId === '' || $payment->bot_user_id !== $user->id) {
            return null;
        }

        $done = DB::transaction(function () use ($payment, $chargeId, $user): ?BotPayment {
            $locked = BotPayment::query()->whereKey($payment->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status === BotPayment::APPROVED
                || BotPayment::query()->where('external_id', $chargeId)->exists()) {
                return null;
            }

            $locked->update([
                'status' => BotPayment::APPROVED,
                'external_id' => $chargeId,
                'reviewed_by' => 'telegram-stars',
                'reviewed_at' => now(),
            ]);

            $this->wallets->credit($this->users->client($user), number_format((float) $locked->amount, 2, '.', ''), TransactionType::Charge, [
                'description' => 'Bot Telegram Stars top-up #'.$locked->id.' ('.$locked->stars.' XTR)',
            ]);

            return $locked;
        });

        if ($done !== null) {
            $this->notifier->user($user, fn () => __('shahbot::bot.payment_approved', [
                'amount' => format_money($done->amount),
                'balance' => format_money($this->users->balance($user)),
            ]));
        }

        return $done;
    }

    protected function starsPayment(string $payload): ?BotPayment
    {
        if (! preg_match('/^stars:(\d+)$/', $payload, $m)) {
            return null;
        }

        return BotPayment::query()->with('botUser')->where('method', 'stars')->find((int) $m[1]);
    }

    protected function assertMethod(string $method): void
    {
        if (! in_array($method, $this->methods(), true)) {
            throw new InvalidArgumentException(__('shahbot::bot.pay_method_closed'));
        }
    }
}
