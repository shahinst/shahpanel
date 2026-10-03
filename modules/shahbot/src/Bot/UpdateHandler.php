<?php

namespace Modules\ShahBot\Bot;

use App\Enums\AccountCategory;
use App\Enums\AccountStatus;
use App\Models\Account;
use App\Models\PackageDuration;
use App\Models\User;
use App\Services\PortalLinkService;
use App\Services\SanaeiPortalService;
use App\Services\SubscriptionFeedService;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Modules\ShahBot\Models\BotAgencyRequest;
use Modules\ShahBot\Models\BotOrder;
use Modules\ShahBot\Models\BotPayment;
use Modules\ShahBot\Models\BotReferralReward;
use Modules\ShahBot\Models\BotTicket;
use Modules\ShahBot\Models\BotTutorial;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Services\AgencyService;
use Modules\ShahBot\Services\BotNotifier;
use Modules\ShahBot\Services\BotUserService;
use Modules\ShahBot\Services\CodeService;
use Modules\ShahBot\Services\OnlinePaymentService;
use Modules\ShahBot\Services\PaymentService;
use Modules\ShahBot\Services\ResellerService;
use Modules\ShahBot\Services\ShopService;
use Modules\ShahBot\Services\TicketService;
use Modules\ShahBot\Support\BotSettings;
use Modules\ShahBot\Telegram\Keyboard;
use Modules\ShahBot\Telegram\TelegramClient;
use Throwable;

/**
 * Turns one Telegram update into the bot's answer. Text from the reply
 * keyboard opens a section; inline buttons drive each section; a pending
 * "step" on the user (waiting for an amount, a code, a receipt...) catches the
 * next free-text message.
 */
class UpdateHandler
{
    protected BotUser $user;

    protected int $chatId;

    public function __construct(
        protected BotSettings $settings,
        protected TelegramClient $tg,
        protected BotUserService $users,
        protected ShopService $shop,
        protected PaymentService $payments,
        protected CodeService $codes,
        protected TicketService $tickets,
        protected BotNotifier $notifier,
        protected OnlinePaymentService $online,
        protected AgencyService $agency,
        protected ResellerService $resellers,
    ) {}

    public function handle(array $update): void
    {
        App::setLocale('fa');

        if (isset($update['pre_checkout_query'])) {
            $this->online->answerPreCheckout($update['pre_checkout_query']);

            return;
        }

        if (isset($update['callback_query'])) {
            $this->onCallback($update['callback_query']);

            return;
        }

        $message = $update['message'] ?? null;

        if (is_array($message) && ($message['chat']['type'] ?? '') === 'private' && isset($message['from']['id'])) {
            $this->onMessage($message);
        }
    }

    // ------------------------------------------------------------------
    // Entry points
    // ------------------------------------------------------------------

    protected function onMessage(array $message): void
    {
        $this->chatId = (int) $message['chat']['id'];
        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
        $startParam = null;

        if (preg_match('/^\/start(?:@\w+)?(?:\s+(\S+))?/', $text, $m)) {
            $startParam = $m[1] ?? null;
        }

        $this->user = $this->users->register($message['from'], $startParam);

        // A finished Stars payment is credited whatever else is going on.
        if (isset($message['successful_payment'])) {
            $this->online->completeStars($this->user, $message['successful_payment']);

            return;
        }

        if (! $this->passesCommonGuards()) {
            return;
        }

        if ($this->user->wasJustCreated) {
            $this->onNewUser();
        }

        if (isset($message['contact'])) {
            $this->onContact($message['contact']);

            return;
        }

        if (! $this->passesGates()) {
            return;
        }

        if ($text === __('shahbot::bot.cancel') || $text === '/cancel') {
            $this->cancelStep();

            return;
        }

        if ($startParam !== null || str_starts_with($text, '/start')) {
            $this->user->setStep(null);
            $this->sendWelcome();

            return;
        }

        if ($this->routeMenu($text)) {
            return;
        }

        if ($this->user->step !== null) {
            $this->onStep($text, $message);

            return;
        }

        $this->reply(__('shahbot::bot.unknown'), $this->mainMenu());
    }

    protected function onCallback(array $callback): void
    {
        $this->chatId = (int) ($callback['message']['chat']['id'] ?? $callback['from']['id']);
        $messageId = (int) ($callback['message']['message_id'] ?? 0);
        $data = (string) ($callback['data'] ?? '');

        $this->user = $this->users->register($callback['from']);

        // join:check answers with its own alert below; a callback can be
        // answered only once.
        if ($data !== 'join:check') {
            $this->tg->answerCallback((string) $callback['id']);
        }

        if (! $this->passesCommonGuards()) {
            return;
        }

        if (str_starts_with($data, 'adm:')) {
            if ($this->settings->isAdminChat($this->user->telegram_id)) {
                $this->onAdminCallback($data, $messageId, $callback);
            }

            return;
        }

        if ($data === 'join:check') {
            if ($this->missingChannels() === []) {
                $this->tg->answerCallback((string) $callback['id']);
                $this->sendWelcome();
            } else {
                $this->tg->answerCallback((string) $callback['id'], __('shahbot::bot.join_still_missing'), true);
                $this->askToJoin();
            }

            return;
        }

        if ($data === 'rules:ok') {
            $this->user->forceFill(['rules_accepted_at' => now()])->save();
            $this->passesGates() && $this->sendWelcome();

            return;
        }

        if (! $this->passesGates()) {
            return;
        }

        try {
            $this->routeCallback($data, $messageId);
        } catch (InvalidArgumentException $e) {
            $this->reply('⚠️ '.e($e->getMessage()));
        }
    }

    // ------------------------------------------------------------------
    // Guards and gates
    // ------------------------------------------------------------------

    protected function passesCommonGuards(): bool
    {
        if ($this->user->is_blocked) {
            $this->reply(__('shahbot::bot.blocked'));

            return false;
        }

        if (! RateLimiter::attempt('shahbot:'.$this->user->telegram_id, 40, fn () => true, 60)) {
            return false;
        }

        if (! $this->settings->isConfigured()) {
            $this->reply(__('shahbot::bot.not_configured'));

            return false;
        }

        return true;
    }

    /**
     * Channel membership, rules and phone number, in that order. Admin chats
     * skip them so a misconfigured gate can never lock the owner out.
     */
    protected function passesGates(): bool
    {
        if ($this->settings->isAdminChat($this->user->telegram_id)) {
            return true;
        }

        if ($this->missingChannels() !== []) {
            $this->askToJoin();

            return false;
        }

        $rules = $this->settings->get('rules_text');

        if ($rules !== '' && $this->user->rules_accepted_at === null) {
            $this->reply(__('shahbot::bot.rules_title', ['rules' => e($rules)]), Keyboard::inline([[
                Keyboard::button(__('shahbot::bot.btn_accept_rules'), 'rules:ok'),
            ]]));

            return false;
        }

        if ($this->settings->bool('require_phone') && blank($this->user->phone)) {
            $this->reply(__('shahbot::bot.phone_required'), Keyboard::contact(__('shahbot::bot.btn_share_phone'), __('shahbot::bot.cancel')));

            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    protected function missingChannels(): array
    {
        $missing = [];

        foreach ($this->settings->lines('channels') as $channel) {
            $channel = str_starts_with($channel, '@') || str_starts_with($channel, '-') ? $channel : '@'.$channel;
            $key = 'shahbot:member:'.$this->user->telegram_id.':'.$channel;

            // A member stays a member for a while; asking Telegram on every
            // button press would slow every answer down.
            if (Cache::get($key)) {
                continue;
            }

            if ($this->tg->isChannelMember($channel, (int) $this->user->telegram_id)) {
                Cache::put($key, true, now()->addMinutes(10));
            } else {
                $missing[] = $channel;
            }
        }

        return $missing;
    }

    protected function askToJoin(): void
    {
        $buttons = [];

        foreach ($this->missingChannels() as $channel) {
            if (str_starts_with($channel, '@')) {
                $buttons[] = [Keyboard::url(__('shahbot::bot.btn_join', ['channel' => $channel]), 'https://t.me/'.ltrim($channel, '@'))];
            }
        }

        $buttons[] = [Keyboard::button(__('shahbot::bot.btn_joined'), 'join:check')];
        $this->reply(__('shahbot::bot.join_required'), Keyboard::inline($buttons));
    }

    protected function onContact(array $contact): void
    {
        if ((int) ($contact['user_id'] ?? 0) !== (int) $this->user->telegram_id) {
            $this->reply(__('shahbot::bot.phone_not_own'));

            return;
        }

        $phone = preg_replace('/[^\d+]/', '', (string) ($contact['phone_number'] ?? ''));
        $phone = str_starts_with($phone, '+') ? $phone : '+'.$phone;

        if ($this->settings->bool('iran_phone_only') && ! str_starts_with($phone, '+98')) {
            $this->reply(__('shahbot::bot.phone_iran_only'));

            return;
        }

        $this->user->forceFill(['phone' => $phone])->save();

        if ($this->user->client_user_id !== null) {
            $this->user->client?->forceFill(['phone' => $phone])->save();
        }

        $this->reply(__('shahbot::bot.phone_saved'), $this->mainMenu());

        if ($this->passesGates()) {
            $this->sendWelcome();
        }
    }

    protected function onNewUser(): void
    {
        $this->notifier->admins(__('shahbot::bot.admin_new_user', [
            'user' => e($this->user->displayName()),
            'id' => $this->user->telegram_id,
        ]));

        if ($this->user->referrer_id !== null && $this->settings->bool('referral_enabled')) {
            $this->notifier->user($this->user->referrer, __('shahbot::bot.referral_joined'));
        }
    }

    // ------------------------------------------------------------------
    // Main menu
    // ------------------------------------------------------------------

    protected function mainMenu(): array
    {
        $rows = [
            [__('shahbot::bot.menu_buy'), __('shahbot::bot.menu_services')],
            [__('shahbot::bot.menu_wallet'), __('shahbot::bot.menu_account')],
        ];

        $row = [];
        if ($this->settings->bool('test_enabled')) {
            $row[] = __('shahbot::bot.menu_test');
        }
        if ($this->settings->bool('referral_enabled')) {
            $row[] = __('shahbot::bot.menu_referral');
        }
        if ($row !== []) {
            $rows[] = $row;
        }

        if ($this->resellers->seller($this->user) !== null) {
            $rows[] = [__('shahbot::bot.menu_reseller')];
        } elseif ($this->agency->available($this->user)) {
            $rows[] = [__('shahbot::bot.menu_agency')];
        }

        $rows[] = [__('shahbot::bot.menu_gift'), __('shahbot::bot.menu_tutorials')];
        $rows[] = [__('shahbot::bot.menu_support')];

        if ($this->settings->isAdminChat($this->user->telegram_id)) {
            $rows[] = [__('shahbot::bot.menu_admin')];
        }

        return Keyboard::reply($rows);
    }

    protected function sendWelcome(): void
    {
        $text = strtr($this->settings->get('welcome_text') ?: BotSettings::defaults()['welcome_text'], [
            '{name}' => e($this->user->first_name ?: $this->user->displayName()),
            '{brand}' => e(app_display_name()),
        ]);

        $this->reply($text, $this->mainMenu());
    }

    protected function routeMenu(string $text): bool
    {
        $routes = [
            'menu_buy' => fn () => $this->showCategories(),
            'menu_services' => fn () => $this->showServices(),
            'menu_wallet' => fn () => $this->showWallet(),
            'menu_test' => fn () => $this->showTest(),
            'menu_account' => fn () => $this->showAccount(),
            'menu_referral' => fn () => $this->showReferral(),
            'menu_gift' => fn () => $this->askFor('gift_code', __('shahbot::bot.ask_gift')),
            'menu_tutorials' => fn () => $this->showTutorials(),
            'menu_support' => fn () => $this->askFor('support', e($this->settings->get('support_text'))),
            'menu_agency' => fn () => $this->agency->available($this->user)
                ? $this->askFor('agency_note', e($this->settings->get('agency_text')))
                : throw new InvalidArgumentException(__('shahbot::bot.agency_closed')),
            'menu_reseller' => fn () => $this->showReseller(),
            'menu_admin' => fn () => $this->settings->isAdminChat($this->user->telegram_id) ? $this->showAdmin() : $this->reply(__('shahbot::bot.unknown')),
        ];

        foreach ($routes as $key => $action) {
            if ($text === __('shahbot::bot.'.$key)) {
                $this->user->setStep(null);

                try {
                    $action();
                } catch (InvalidArgumentException $e) {
                    $this->reply('⚠️ '.e($e->getMessage()), $this->mainMenu());
                }

                return true;
            }
        }

        return false;
    }

    protected function askFor(string $step, string $prompt, array $data = []): void
    {
        $this->user->setStep($step, $data);
        $this->reply($prompt, Keyboard::reply([[__('shahbot::bot.cancel')]]));
    }

    protected function cancelStep(): void
    {
        if ($this->user->step === 'topup_receipt') {
            BotPayment::query()->whereKey((int) $this->user->stepValue('payment'))
                ->where('status', BotPayment::AWAITING_RECEIPT)
                ->update(['status' => BotPayment::CANCELLED]);
        }

        $this->user->setStep(null);
        $this->reply(__('shahbot::bot.cancelled'), $this->mainMenu());
    }

    // ------------------------------------------------------------------
    // Free-text steps
    // ------------------------------------------------------------------

    protected function onStep(string $text, array $message): void
    {
        $step = $this->user->step;

        try {
            match ($step) {
                'buy_gb' => $this->stepBuyGb($text),
                'buy_code' => $this->stepBuyCode($text),
                'topup_amount' => $this->chooseMethod($text),
                'topup_receipt' => $this->stepReceipt($message),
                'gift_code' => $this->stepGift($text),
                'support' => $this->stepSupport($text),
                'agency_note' => $this->stepAgency($text),
                'rs_gb' => $this->stepResellerGb($text),
                'admin_reply' => $this->stepAdminReply($text),
                default => $this->cancelStep(),
            };
        } catch (InvalidArgumentException $e) {
            $this->reply('⚠️ '.e($e->getMessage()));
        } catch (Throwable $e) {
            report($e);
            $this->user->setStep(null);
            $this->reply(__('shahbot::bot.error', ['message' => e($e->getMessage())]), $this->mainMenu());
        }
    }

    protected function stepBuyGb(string $text): void
    {
        $gb = (float) str_replace(',', '.', western_digits($text));

        if ($gb <= 0) {
            $this->reply(__('shahbot::bot.gb_invalid'));

            return;
        }

        $durationId = (int) $this->user->stepValue('duration');
        $this->user->setStep(null);
        $this->reply(__('shahbot::bot.home_hint'), $this->mainMenu());
        $this->showInvoice($durationId, $gb, null);
    }

    protected function stepBuyCode(string $text): void
    {
        $durationId = (int) $this->user->stepValue('duration');
        $gb = $this->user->stepValue('gb');

        // Validate before leaving the step, so a typo can simply be retried.
        $this->shop->quote($this->user, $durationId, $gb !== null ? (float) $gb : null, $text);

        $this->user->setStep(null);
        $this->reply(__('shahbot::bot.discount_applied'), $this->mainMenu());
        $this->showInvoice($durationId, $gb !== null ? (float) $gb : null, CodeService::normalize($text));
    }

    protected function stepReceipt(array $message): void
    {
        $payment = BotPayment::query()->find((int) $this->user->stepValue('payment'));

        if ($payment === null || $payment->bot_user_id !== $this->user->id) {
            $this->user->setStep(null);
            $this->reply(__('shahbot::bot.payment_closed'), $this->mainMenu());

            return;
        }

        $photo = $message['photo'] ?? null;
        $fileId = null;

        if (is_array($photo) && $photo !== []) {
            $fileId = (string) end($photo)['file_id'];
        } elseif (isset($message['document']['file_id']) && str_starts_with((string) ($message['document']['mime_type'] ?? ''), 'image/')) {
            $fileId = (string) $message['document']['file_id'];
        }

        if ($fileId === null) {
            $this->reply(__('shahbot::bot.receipt_expected'));

            return;
        }

        $this->payments->attachReceipt($payment, $fileId, $message['caption'] ?? null);
        $this->user->setStep(null);
        $this->reply(__('shahbot::bot.receipt_received', ['id' => $payment->id]), $this->mainMenu());
    }

    protected function stepGift(string $text): void
    {
        $amount = $this->codes->redeemGift($this->user, $text);
        $this->user->setStep(null);
        $this->reply(__('shahbot::bot.gift_redeemed', [
            'amount' => format_money($amount),
            'balance' => format_money($this->users->balance($this->user)),
        ]), $this->mainMenu());
    }

    protected function stepSupport(string $text): void
    {
        if ($text === '') {
            return;
        }

        $this->tickets->fromUser($this->user, $text);
        $this->user->setStep(null);
        $this->reply(__('shahbot::bot.support_sent'), $this->mainMenu());
    }

    protected function stepAgency(string $text): void
    {
        if (mb_strlen(trim($text)) < 3) {
            $this->reply(__('shahbot::bot.agency_note_short'));

            return;
        }

        $this->agency->request($this->user, $text);
        $this->user->setStep(null);
        $this->reply(__('shahbot::bot.agency_sent'), $this->mainMenu());
    }

    protected function stepResellerGb(string $text): void
    {
        $gb = (float) str_replace(',', '.', western_digits($text));

        if ($gb <= 0) {
            $this->reply(__('shahbot::bot.gb_invalid'));

            return;
        }

        $durationId = (int) $this->user->stepValue('duration');
        $this->user->setStep(null);
        $this->reply(__('shahbot::bot.home_hint'), $this->mainMenu());
        $this->showQuantities($durationId, (int) ceil($gb), null);
    }

    protected function stepAdminReply(string $text): void
    {
        $ticket = BotTicket::query()->find((int) $this->user->stepValue('ticket'));
        $this->user->setStep(null);

        if ($ticket === null || $text === '') {
            $this->reply(__('shahbot::bot.cancelled'), $this->mainMenu());

            return;
        }

        $delivered = $this->tickets->reply($ticket, $text, 'TG '.$this->user->displayName());
        $this->reply(__($delivered ? 'shahbot::bot.admin_reply_sent' : 'shahbot::bot.admin_reply_failed'), $this->mainMenu());
    }

    // ------------------------------------------------------------------
    // Inline buttons
    // ------------------------------------------------------------------

    protected function routeCallback(string $data, int $messageId): void
    {
        $parts = explode(':', $data);

        match ($parts[0]) {
            'buy' => $this->buyCallback($parts, $messageId),
            'svc' => $this->serviceCallback($parts, $messageId),
            'wal' => $this->walletCallback($parts, $messageId),
            'test' => $this->testCallback($messageId),
            'tut' => $this->tutorialCallback($parts, $messageId),
            'rs' => $this->resellerCallback($parts, $messageId),
            default => null,
        };
    }

    // --- Store ---------------------------------------------------------

    protected function showCategories(?int $messageId = null): void
    {
        if (! $this->settings->bool('sales_enabled')) {
            $this->reply(e($this->settings->get('closed_text') ?: __('shahbot::bot.sales_closed')));

            return;
        }

        $groups = $this->shop->groups($this->user);

        if ($groups->isEmpty()) {
            $this->say($messageId, __('shahbot::bot.buy_empty'));

            return;
        }

        if ($groups->count() === 1) {
            $this->showProducts(0, $messageId);

            return;
        }

        $buttons = [];
        foreach ($groups as $index => $group) {
            $buttons[] = [Keyboard::button('🗂 '.$group['label'].' ('.persian_digits($group['rows']->count()).')', 'buy:c:'.$index)];
        }

        $this->say($messageId, __('shahbot::bot.buy_choose_category'), Keyboard::inline($buttons));
    }

    protected function showProducts(int $index, ?int $messageId): void
    {
        $groups = $this->shop->groups($this->user);
        $group = $groups->get($index) ?? $groups->first();

        if ($group === null) {
            $this->say($messageId, __('shahbot::bot.buy_empty'));

            return;
        }

        $buttons = [];
        foreach ($group['rows'] as $row) {
            $buttons[] = [Keyboard::button($this->shop->rowLabel($row), 'buy:d:'.$row['duration']->id)];
        }

        if ($groups->count() > 1) {
            $buttons[] = [Keyboard::button(__('shahbot::bot.back'), 'buy:cats')];
        }

        $this->say($messageId, __('shahbot::bot.buy_choose_product', ['category' => e($group['label'])]), Keyboard::inline($buttons));
    }

    protected function buyCallback(array $parts, int $messageId): void
    {
        $action = $parts[1] ?? '';

        match ($action) {
            'cats' => $this->showCategories($messageId),
            'c' => $this->showProducts((int) ($parts[2] ?? 0), $messageId),
            'd' => $this->chooseProduct((int) ($parts[2] ?? 0), $messageId),
            'code' => $this->askFor('buy_code', __('shahbot::bot.ask_discount'), $this->pendingPurchase()),
            'nocode' => $this->showInvoice((int) $this->pendingPurchase()['duration'], $this->pendingGb(), null, $messageId),
            'pay' => $this->payPurchase($messageId),
            'top' => $this->chooseMethod((string) ($parts[2] ?? '0')),
            default => null,
        };
    }

    protected function chooseProduct(int $durationId, int $messageId): void
    {
        $row = $this->shop->row($this->user, $durationId);

        if ($row === null) {
            throw new InvalidArgumentException(__('shahbot::bot.product_unavailable'));
        }

        $package = $row['package'];

        if ($package->isElastic()) {
            $this->askFor('buy_gb', __('shahbot::bot.buy_ask_gb', [
                'name' => e($package->name),
                'min' => persian_digits((float) ($package->min_data_gb ?: 1)),
                'max' => persian_digits((float) ($package->max_data_gb ?: 1000)),
                'price' => format_money($row['display_price']),
            ]), ['duration' => $durationId]);

            return;
        }

        $this->showInvoice($durationId, null, null, $messageId);
    }

    /**
     * The order being prepared lives in the cache (keyed by user), not in the
     * callback data: Telegram caps callback data at 64 bytes.
     */
    protected function pendingPurchase(): array
    {
        return Cache::get('shahbot:cart:'.$this->user->id, ['duration' => 0, 'gb' => null, 'code' => null]);
    }

    protected function pendingGb(): ?float
    {
        $gb = $this->pendingPurchase()['gb'] ?? null;

        return $gb !== null ? (float) $gb : null;
    }

    protected function showInvoice(int $durationId, ?float $gb, ?string $code, ?int $messageId = null): void
    {
        $quote = $this->shop->quote($this->user, $durationId, $gb, $code);
        Cache::put('shahbot:cart:'.$this->user->id, ['duration' => $durationId, 'gb' => $quote['gb'], 'code' => $code], now()->addHour());

        $row = $quote['row'];
        $package = $row['package'];
        $balance = $this->users->balance($this->user);
        $volume = $quote['gb'] !== null
            ? persian_digits($quote['gb']).' GB'
            : ($package->isUnlimited() ? __('shahbot::bot.unlimited') : persian_digits((float) $package->data_limit_gb).' GB');

        $text = __('shahbot::bot.invoice', [
            'name' => e($package->name),
            'duration' => $row['duration']->tier->label(),
            'volume' => $volume,
            'total' => format_money($quote['total']),
            'discount_line' => (float) $quote['discount'] > 0
                ? __('shahbot::bot.invoice_discount_line', ['code' => e((string) $code), 'discount' => format_money($quote['discount'])])
                : '',
            'payable' => format_money($quote['payable']),
            'balance' => format_money($balance),
        ]);

        $rows = [];
        if ((float) $balance >= (float) $quote['payable']) {
            $rows[] = [Keyboard::button(__('shahbot::bot.btn_pay_wallet'), 'buy:pay')];
        } else {
            $diff = (string) ceil((float) $quote['payable'] - (float) $balance);
            $rows[] = [Keyboard::button(__('shahbot::bot.btn_topup_diff', ['amount' => format_money($diff)]), 'buy:top:'.$diff)];
        }

        $rows[] = [$code === null
            ? Keyboard::button(__('shahbot::bot.btn_discount'), 'buy:code')
            : Keyboard::button(__('shahbot::bot.btn_remove_discount'), 'buy:nocode')];
        $rows[] = [Keyboard::button(__('shahbot::bot.back'), 'buy:cats')];

        $this->say($messageId, $text, Keyboard::inline($rows));
    }

    protected function payPurchase(int $messageId): void
    {
        $cart = $this->pendingPurchase();

        if ((int) $cart['duration'] === 0) {
            throw new InvalidArgumentException(__('shahbot::bot.product_unavailable'));
        }

        // Lock per user so a double tap cannot buy twice.
        $lock = Cache::lock('shahbot:buy:'.$this->user->id, 120);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->say($messageId, __('shahbot::bot.buying'));
            $order = $this->shop->purchase($this->user, (int) $cart['duration'], $this->pendingGb(), $cart['code']);
            Cache::forget('shahbot:cart:'.$this->user->id);

            $this->reply(__('shahbot::bot.bought', ['details' => $this->serviceText($order->account)]), $this->serviceKeyboard($order->account));
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $this->reply(__('shahbot::bot.error', ['message' => e($e->getMessage())]));
        } finally {
            $lock->release();
        }
    }

    // --- Test account -----------------------------------------------------

    protected function showTest(): void
    {
        if (! $this->settings->bool('test_enabled')) {
            throw new InvalidArgumentException(__('shahbot::bot.test_disabled'));
        }

        if ($this->user->test_used_at !== null) {
            throw new InvalidArgumentException(__('shahbot::bot.test_used'));
        }

        $duration = PackageDuration::query()->with('package')->find($this->settings->int('test_duration_id'));

        if ($duration?->package === null) {
            throw new InvalidArgumentException(__('shahbot::bot.test_disabled'));
        }

        $this->reply(__('shahbot::bot.test_confirm', [
            'name' => e($duration->package->name),
            'duration' => $duration->tier->label(),
        ]), Keyboard::inline([[Keyboard::button(__('shahbot::bot.btn_get_test'), 'test:ok')]]));
    }

    protected function testCallback(int $messageId): void
    {
        $lock = Cache::lock('shahbot:buy:'.$this->user->id, 120);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->say($messageId, __('shahbot::bot.buying'));
            $order = $this->shop->trial($this->user);
            $this->reply(__('shahbot::bot.bought', ['details' => $this->serviceText($order->account)]), $this->serviceKeyboard($order->account));
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $this->reply(__('shahbot::bot.error', ['message' => e($e->getMessage())]));
        } finally {
            $lock->release();
        }
    }

    // --- My services -----------------------------------------------------

    protected function showServices(?int $messageId = null): void
    {
        $accounts = $this->shop->accounts($this->user);

        if ($accounts->isEmpty()) {
            $this->say($messageId, __('shahbot::bot.services_empty'));

            return;
        }

        $buttons = [];
        foreach ($accounts->take(40) as $account) {
            $buttons[] = [Keyboard::button($this->statusIcon($account).' '.$this->accountName($account), 'svc:v:'.$account->id)];
        }

        $this->say($messageId, __('shahbot::bot.services_title'), Keyboard::inline($buttons));
    }

    protected function serviceCallback(array $parts, int $messageId): void
    {
        $action = $parts[1] ?? '';

        if ($action === 'list') {
            $this->showServices($messageId);

            return;
        }

        $account = Account::query()->with(['package', 'server', 'packageDuration'])->find((int) ($parts[2] ?? 0));

        if ($account === null) {
            throw new InvalidArgumentException(__('shahbot::bot.service_not_found'));
        }

        $this->shop->assertOwnsAccount($this->user, $account);

        match ($action) {
            'v' => $this->say($messageId, $this->serviceText($account), $this->serviceKeyboard($account)),
            'sub' => $this->sendSubscription($account),
            'ar' => $this->toggleAutoRenew($account, $messageId),
            'nl' => $this->say($messageId, __('shahbot::bot.new_link_confirm'), Keyboard::inline([[
                Keyboard::button(__('shahbot::bot.btn_confirm'), 'svc:nlok:'.$account->id),
                Keyboard::button(__('shahbot::bot.back'), 'svc:v:'.$account->id),
            ]])),
            'nlok' => $this->newLink($account),
            'rn' => $this->showRenewOptions($account, $messageId),
            'rnd' => $this->confirmRenew($account, (int) ($parts[3] ?? 0), $messageId),
            'rnok' => $this->doRenew($account, (int) ($parts[3] ?? 0), $messageId),
            default => null,
        };
    }

    protected function accountName(Account $account): string
    {
        return (string) ($account->display_label ?: $account->remote_username);
    }

    protected function statusIcon(Account $account): string
    {
        return match ($account->status) {
            AccountStatus::Active => '🟢',
            AccountStatus::Pending => '🟡',
            AccountStatus::Expired => '🔴',
            AccountStatus::Exhausted => '🟠',
            default => '⚪️',
        };
    }

    protected function serviceText(?Account $account): string
    {
        if ($account === null) {
            return '';
        }

        $account->refresh()->loadMissing(['package', 'server']);
        $remaining = '';

        if ($account->expiry_at !== null) {
            $days = (int) floor(now()->diffInHours($account->expiry_at, false) / 24);
            $remaining = $account->expiry_at->isPast()
                ? __('shahbot::bot.expired_ago')
                : __('shahbot::bot.remaining_days', ['days' => persian_digits(max(0, $days))]);
        }

        return __('shahbot::bot.service_details', [
            'name' => e($this->accountName($account)),
            'status' => __('shahbot::bot.status_'.$account->status->value),
            'server' => e((string) ($account->server?->name ?? '—')),
            'package' => e((string) ($account->package?->name ?? '—')),
            'used' => persian_digits(format_data_size((int) $account->data_used_bytes)),
            'limit' => $account->isUnlimited() ? __('shahbot::bot.unlimited') : persian_digits(format_data_size((int) $account->data_limit_bytes)),
            'expiry' => $account->expiry_at ? jalali_date($account->expiry_at, 'Y/m/d H:i') : __('shahbot::bot.unlimited'),
            'remaining' => $remaining,
            'auto' => __($account->auto_renew ? 'shahbot::bot.on' : 'shahbot::bot.off'),
        ]);
    }

    protected function serviceKeyboard(?Account $account): ?array
    {
        if ($account === null) {
            return null;
        }

        $rows = [[Keyboard::button(__('shahbot::bot.btn_sub'), 'svc:sub:'.$account->id)]];

        if ($this->settings->bool('renew_enabled') && ! $account->packageDuration?->tier->isTest()) {
            $rows[] = [
                Keyboard::button(__('shahbot::bot.btn_renew'), 'svc:rn:'.$account->id),
                Keyboard::button(__('shahbot::bot.btn_auto_renew', ['state' => __($account->auto_renew ? 'shahbot::bot.on' : 'shahbot::bot.off')]), 'svc:ar:'.$account->id),
            ];
        }

        $row = [];
        if ($account->service_type->accountCategory() === AccountCategory::V2ray) {
            $row[] = Keyboard::button(__('shahbot::bot.btn_new_link'), 'svc:nl:'.$account->id);
        }
        if ($this->settings->bool('show_portal_link') && str_starts_with((string) config('app.url'), 'https://')) {
            try {
                $row[] = Keyboard::url(__('shahbot::bot.btn_portal'), app(PortalLinkService::class)->ensure($account));
            } catch (Throwable) {
            }
        }
        $rows[] = $row;

        $rows[] = [
            Keyboard::button(__('shahbot::bot.btn_refresh'), 'svc:v:'.$account->id),
            Keyboard::button(__('shahbot::bot.back'), 'svc:list'),
        ];

        return Keyboard::inline($rows);
    }

    protected function sendSubscription(Account $account): void
    {
        if ($account->service_type->accountCategory() !== AccountCategory::V2ray) {
            $this->reply(__('shahbot::bot.sub_credentials', [
                'name' => e($this->accountName($account)),
                'username' => e((string) $account->remote_username),
                'password' => e((string) ($account->remote_password_enc ?? '—')),
                'host' => e((string) ($account->server?->client_host ?: $account->server?->host ?: '—')),
            ]));

            return;
        }

        $url = app(SubscriptionFeedService::class)->urlFor($account);
        $caption = __('shahbot::bot.sub_caption', ['name' => e($this->accountName($account)), 'url' => e($url)]);

        try {
            $png = base64_decode(app(SanaeiPortalService::class)->qrBase64($url));
            $result = $this->tg->sendPhotoBytes($this->chatId, $png, 'qr.png', $caption);

            if ($result['ok'] ?? false) {
                return;
            }
        } catch (Throwable) {
        }

        $this->reply($caption);
    }

    protected function toggleAutoRenew(Account $account, int $messageId): void
    {
        $account->forceFill(['auto_renew' => ! $account->auto_renew])->save();
        $this->say($messageId, $this->serviceText($account), $this->serviceKeyboard($account));
    }

    protected function newLink(Account $account): void
    {
        app(SubscriptionFeedService::class)->issue($account);
        $this->reply(__('shahbot::bot.new_link_done'));
        $this->sendSubscription($account->fresh());
    }

    protected function showRenewOptions(Account $account, int $messageId): void
    {
        $options = $this->shop->renewalOptions($this->user, $account);

        if ($options->isEmpty()) {
            throw new InvalidArgumentException(__('shahbot::bot.renew_none'));
        }

        $buttons = $options->map(fn (array $o) => [Keyboard::button(
            $o['duration']->tier->label().' · '.format_money($o['price']),
            'svc:rnd:'.$account->id.':'.$o['duration']->id
        )])->all();
        $buttons[] = [Keyboard::button(__('shahbot::bot.back'), 'svc:v:'.$account->id)];

        $this->say($messageId, __('shahbot::bot.renew_choose', ['name' => e($this->accountName($account))]), Keyboard::inline($buttons));
    }

    protected function confirmRenew(Account $account, int $durationId, int $messageId): void
    {
        $option = $this->shop->renewalOptions($this->user, $account)->first(fn (array $o) => (int) $o['duration']->id === $durationId);

        if ($option === null) {
            throw new InvalidArgumentException(__('shahbot::bot.product_unavailable'));
        }

        $balance = $this->users->balance($this->user);
        $rows = [];

        if ((float) $balance >= (float) $option['price']) {
            $rows[] = [Keyboard::button(__('shahbot::bot.btn_confirm'), 'svc:rnok:'.$account->id.':'.$durationId)];
        } else {
            $diff = (string) ceil((float) $option['price'] - (float) $balance);
            $rows[] = [Keyboard::button(__('shahbot::bot.btn_topup_diff', ['amount' => format_money($diff)]), 'buy:top:'.$diff)];
        }
        $rows[] = [Keyboard::button(__('shahbot::bot.back'), 'svc:rn:'.$account->id)];

        $this->say($messageId, __('shahbot::bot.renew_confirm', [
            'name' => e($this->accountName($account)),
            'duration' => $option['duration']->tier->label(),
            'price' => format_money($option['price']),
            'balance' => format_money($balance),
        ]), Keyboard::inline($rows));
    }

    protected function doRenew(Account $account, int $durationId, int $messageId): void
    {
        $lock = Cache::lock('shahbot:buy:'.$this->user->id, 120);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->say($messageId, __('shahbot::bot.buying'));
            $this->shop->renew($this->user, $account, $durationId);
            $this->reply(__('shahbot::bot.renewed', ['details' => $this->serviceText($account)]), $this->serviceKeyboard($account->fresh()));
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $this->reply(__('shahbot::bot.error', ['message' => e($e->getMessage())]));
        } finally {
            $lock->release();
        }
    }

    // --- Wallet -----------------------------------------------------------

    protected function showWallet(?int $messageId = null): void
    {
        $balance = $this->users->balance($this->user);
        $rows = [];

        if ($this->online->methods() !== []) {
            $min = max(1, (float) $this->settings->get('topup_min'));
            $amounts = array_values(array_unique(array_filter([$min, $min * 2, $min * 5, $min * 10],
                fn ($a) => (float) $this->settings->get('topup_max') <= 0 || $a <= (float) $this->settings->get('topup_max'))));

            $rows = Keyboard::grid(array_map(fn ($a) => Keyboard::button(format_money($a), 'wal:amt:'.(int) $a), $amounts), 2);
            $rows[] = [Keyboard::button(__('shahbot::bot.btn_custom_amount'), 'wal:custom')];
        }

        $rows[] = [Keyboard::button(__('shahbot::bot.btn_history'), 'wal:hist')];

        $this->say($messageId, __('shahbot::bot.wallet', ['balance' => format_money($balance)]), Keyboard::inline($rows));
    }

    protected function walletCallback(array $parts, int $messageId): void
    {
        match ($parts[1] ?? '') {
            'amt' => $this->chooseMethod((string) ($parts[2] ?? '0')),
            'pay' => $this->startMethod((string) ($parts[2] ?? ''), (string) ($parts[3] ?? '0')),
            'cur' => $this->payCrypto((string) ($parts[2] ?? ''), (string) ($parts[3] ?? '0')),
            'custom' => $this->askFor('topup_amount', __('shahbot::bot.ask_amount', [
                'min' => format_money($this->settings->get('topup_min')),
                'max' => format_money($this->settings->get('topup_max')),
            ])),
            'hist' => $this->showHistory($messageId),
            'back' => $this->showWallet($messageId),
            'cancel' => $this->cancelPayment((int) ($parts[2] ?? 0), $messageId),
            default => null,
        };
    }

    /**
     * After the amount: pick how to pay, or go straight on when only one
     * method is open.
     */
    protected function chooseMethod(string $amount): void
    {
        $value = $this->payments->validAmount($amount);
        $methods = $this->online->methods();

        if ($methods === []) {
            $this->user->setStep(null);
            throw new InvalidArgumentException(__('shahbot::bot.topup_disabled'));
        }

        if (count($methods) === 1) {
            $this->startMethod($methods[0], (string) (int) $value);

            return;
        }

        $this->user->setStep(null);
        $labels = [
            OnlinePaymentService::ZARINPAL => __('shahbot::bot.pay_zarinpal'),
            OnlinePaymentService::CRYPTO => __('shahbot::bot.pay_crypto'),
            OnlinePaymentService::STARS => __('shahbot::bot.pay_stars', ['stars' => persian_digits($this->online->starsFor($value))]),
            OnlinePaymentService::CARD => __('shahbot::bot.pay_card'),
        ];
        $buttons = array_map(fn (string $m) => [Keyboard::button($labels[$m], 'wal:pay:'.$m.':'.(int) $value)], $methods);

        $this->reply(__('shahbot::bot.pay_choose', ['amount' => format_money($value)]), Keyboard::inline($buttons));
    }

    protected function startMethod(string $method, string $amount): void
    {
        $value = $this->payments->validAmount($amount);

        match ($method) {
            OnlinePaymentService::ZARINPAL => $this->sendPayLink($this->online->startZarinpal($this->user, $value)->invoice_url, $value),
            OnlinePaymentService::CRYPTO => $this->chooseCurrency($value),
            OnlinePaymentService::STARS => $this->online->startStars($this->user, $value),
            OnlinePaymentService::CARD => $this->startTopup((string) (int) $value),
            default => throw new InvalidArgumentException(__('shahbot::bot.pay_method_closed')),
        };
    }

    protected function chooseCurrency(float $amount): void
    {
        $buttons = array_map(
            fn (string $code) => Keyboard::button(strtoupper($code), 'wal:cur:'.$code.':'.(int) $amount),
            $this->online->cryptoCurrencies()
        );

        $this->reply(__('shahbot::bot.pay_currency', ['amount' => format_money($amount)]), Keyboard::inline(Keyboard::grid($buttons, 3)));
    }

    protected function payCrypto(string $currency, string $amount): void
    {
        $value = $this->payments->validAmount($amount);
        $this->sendPayLink($this->online->startCrypto($this->user, $value, $currency)->invoice_url, $value);
    }

    protected function sendPayLink(?string $url, float $amount): void
    {
        $this->user->setStep(null);
        $this->reply(__('shahbot::bot.pay_link', ['amount' => format_money($amount)]), Keyboard::inline([[
            Keyboard::url(__('shahbot::bot.btn_pay_now'), (string) $url),
        ]]));
        $this->reply(__('shahbot::bot.home_hint'), $this->mainMenu());
    }

    protected function startTopup(string $amount): void
    {
        if ($this->settings->get('card_number') === '') {
            $this->user->setStep(null);
            throw new InvalidArgumentException(__('shahbot::bot.card_missing'));
        }

        $payment = $this->payments->start($this->user, $amount);
        $this->user->setStep('topup_receipt', ['payment' => $payment->id]);

        $note = $this->settings->get('card_note');
        $this->reply(__('shahbot::bot.card_info', [
            'amount' => format_money($payment->amount),
            'card' => e(trim(chunk_split(preg_replace('/\D/', '', $this->settings->get('card_number')), 4, ' '))),
            'holder' => e($this->settings->get('card_holder') ?: '—'),
            'bank' => e($this->settings->get('card_bank')),
            'note' => $note !== '' ? "\n".e($note)."\n" : '',
        ]), Keyboard::reply([[__('shahbot::bot.cancel')]]));
    }

    protected function cancelPayment(int $paymentId, int $messageId): void
    {
        BotPayment::query()->whereKey($paymentId)->where('bot_user_id', $this->user->id)
            ->where('status', BotPayment::AWAITING_RECEIPT)
            ->update(['status' => BotPayment::CANCELLED]);
        $this->user->setStep(null);
        $this->reply(__('shahbot::bot.cancelled'), $this->mainMenu());
    }

    protected function showHistory(int $messageId): void
    {
        $payments = BotPayment::query()->where('bot_user_id', $this->user->id)->latest('id')->limit(10)->get();

        if ($payments->isEmpty()) {
            $this->say($messageId, __('shahbot::bot.history_empty'));

            return;
        }

        $rows = $payments->map(fn (BotPayment $p) => '#'.persian_digits($p->id).' · '.format_money($p->amount).' · '
            .__('shahbot::bot.pay_status_'.$p->status).' · '.jalali_date($p->created_at, 'Y/m/d'))->implode("\n");

        $this->say($messageId, __('shahbot::bot.history_title', ['rows' => $rows]), Keyboard::inline([[
            Keyboard::button(__('shahbot::bot.back'), 'wal:back'),
        ]]));
    }

    // --- Account, referral, tutorials ------------------------------------

    protected function showAccount(): void
    {
        $this->reply(__('shahbot::bot.account', [
            'id' => $this->user->telegram_id,
            'name' => e($this->user->displayName()),
            'phone' => e($this->user->phone ?: '—'),
            'balance' => format_money($this->users->balance($this->user)),
            'services' => persian_digits($this->shop->accounts($this->user)->count()),
            'orders' => persian_digits(BotOrder::query()->where('bot_user_id', $this->user->id)->whereIn('type', ['buy', 'renew'])->count()),
            'referrals' => persian_digits($this->user->referrals()->count()),
            'joined' => jalali_date($this->user->created_at, 'Y/m/d'),
        ]));
    }

    protected function showReferral(): void
    {
        if (! $this->settings->bool('referral_enabled')) {
            throw new InvalidArgumentException(__('shahbot::bot.referral_disabled'));
        }

        $username = $this->settings->get('bot_username');
        $link = $username !== ''
            ? 'https://t.me/'.ltrim($username, '@').'?start=ref_'.$this->user->telegram_id
            : '/start ref_'.$this->user->telegram_id;

        $this->reply(__('shahbot::bot.referral', [
            'rule' => __($this->settings->bool('referral_first_only') ? 'shahbot::bot.referral_rule_first' : 'shahbot::bot.referral_rule_all'),
            'percent' => persian_digits($this->settings->get('referral_percent')),
            'link' => e($link),
            'count' => persian_digits($this->user->referrals()->count()),
            'earned' => format_money(BotReferralReward::query()->where('referrer_id', $this->user->id)->sum('amount')),
        ]));
    }

    protected function showTutorials(?int $messageId = null): void
    {
        $tutorials = BotTutorial::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();

        if ($tutorials->isEmpty()) {
            $this->say($messageId, __('shahbot::bot.tutorials_empty'));

            return;
        }

        $buttons = $tutorials->map(fn (BotTutorial $t) => [Keyboard::button('📘 '.$t->title, 'tut:'.$t->id)])->all();
        $this->say($messageId, __('shahbot::bot.tutorials_title'), Keyboard::inline($buttons));
    }

    protected function tutorialCallback(array $parts, int $messageId): void
    {
        $tutorial = BotTutorial::query()->where('is_active', true)->find((int) ($parts[1] ?? 0));

        if ($tutorial === null) {
            $this->showTutorials($messageId);

            return;
        }

        $rows = [];
        if ($tutorial->url) {
            $rows[] = [Keyboard::url(__('shahbot::bot.btn_open_link'), $tutorial->url)];
        }
        $rows[] = [Keyboard::button(__('shahbot::bot.back'), 'tut:0')];

        $this->say($messageId, '<b>'.e($tutorial->title)."</b>\n\n".e((string) $tutorial->body), Keyboard::inline($rows));
    }

    // --- Admin inside the bot --------------------------------------------

    protected function showAdmin(?int $messageId = null): void
    {
        $sales = fn ($from) => BotOrder::query()->whereIn('type', ['buy', 'renew'])->where('created_at', '>=', $from);

        $text = __('shahbot::bot.admin_menu', [
            'users' => persian_digits(BotUser::query()->count()),
            'today' => persian_digits(BotUser::query()->where('created_at', '>=', today())->count()),
            'sales_today' => format_money($sales(today())->sum('amount')),
            'count_today' => persian_digits($sales(today())->count()),
            'sales_month' => format_money($sales(now()->subDays(30))->sum('amount')),
            'pending' => persian_digits(BotPayment::query()->where('status', BotPayment::PENDING)->count()),
            'tickets' => persian_digits(BotTicket::query()->where('status', BotTicket::OPEN)->count()),
        ]);

        $rows = [[Keyboard::button(__('shahbot::bot.btn_pending_receipts'), 'adm:pend')]];

        if (str_starts_with((string) config('app.url'), 'https://') && Route::has('admin.shahbot.index')) {
            $rows[] = [Keyboard::url(__('shahbot::bot.btn_open_panel'), route('admin.shahbot.index'))];
        }

        $this->say($messageId, $text, Keyboard::inline($rows));
    }

    protected function onAdminCallback(string $data, int $messageId, array $callback): void
    {
        App::setLocale('fa');
        $parts = explode(':', $data);
        $reviewer = 'TG '.$this->user->displayName();

        try {
            match ($parts[1] ?? '') {
                'pend' => $this->adminPending(),
                'pay' => $this->adminPayment($parts[2] ?? '', (int) ($parts[3] ?? 0), $reviewer),
                'tk' => $this->adminTicket($parts[2] ?? '', (int) ($parts[3] ?? 0)),
                'ag' => $this->adminAgency($parts[2] ?? '', (int) ($parts[3] ?? 0), $reviewer),
                default => $this->showAdmin($messageId),
            };
        } catch (InvalidArgumentException $e) {
            $this->reply('⚠️ '.e($e->getMessage()));
        }
    }

    protected function adminPending(): void
    {
        $pending = BotPayment::query()->with('botUser')->where('status', BotPayment::PENDING)->oldest('id')->limit(10)->get();

        if ($pending->isEmpty()) {
            $this->reply(__('shahbot::bot.admin_no_pending'));

            return;
        }

        foreach ($pending as $payment) {
            $caption = __('shahbot::bot.admin_new_receipt', [
                'id' => $payment->id,
                'user' => e($payment->botUser->displayName()),
                'tg' => $payment->botUser->telegram_id,
                'amount' => format_money($payment->amount),
                'note' => e((string) $payment->receipt_note),
            ]);
            $keyboard = Keyboard::inline([[
                Keyboard::button(__('shahbot::bot.btn_approve'), 'adm:pay:ok:'.$payment->id),
                Keyboard::button(__('shahbot::bot.btn_reject'), 'adm:pay:no:'.$payment->id),
            ]]);

            $payment->receipt_file_id
                ? $this->tg->sendPhotoId($this->chatId, $payment->receipt_file_id, $caption, $keyboard)
                : $this->reply($caption, $keyboard);
        }
    }

    protected function adminPayment(string $action, int $paymentId, string $reviewer): void
    {
        $payment = BotPayment::query()->find($paymentId);

        if ($payment === null) {
            return;
        }

        if ($action === 'ok') {
            $this->payments->approve($payment, $reviewer);
            $this->notifier->admins(__('shahbot::bot.admin_approved', ['id' => $paymentId, 'by' => e($reviewer)]));
        } elseif ($action === 'no') {
            $this->payments->reject($payment, $reviewer);
            $this->notifier->admins(__('shahbot::bot.admin_rejected', ['id' => $paymentId, 'by' => e($reviewer)]));
        }
    }

    protected function adminTicket(string $action, int $ticketId): void
    {
        $ticket = BotTicket::query()->find($ticketId);

        if ($ticket === null) {
            return;
        }

        if ($action === 'reply') {
            $this->askFor('admin_reply', __('shahbot::bot.admin_ask_reply', ['id' => $ticketId]), ['ticket' => $ticketId]);
        } elseif ($action === 'close') {
            $this->tickets->close($ticket);
            $this->reply(__('shahbot::bot.admin_ticket_closed', ['id' => $ticketId]));
        }
    }

    protected function adminAgency(string $action, int $requestId, string $reviewer): void
    {
        $request = BotAgencyRequest::query()->find($requestId);

        if ($request === null) {
            return;
        }

        if ($action === 'ok') {
            $this->agency->approve($request, $reviewer);
            $this->notifier->admins(__('shahbot::bot.admin_agency_approved', ['id' => $requestId, 'by' => e($reviewer)]));
        } elseif ($action === 'no') {
            $this->agency->reject($request, $reviewer);
            $this->notifier->admins(__('shahbot::bot.admin_agency_rejected', ['id' => $requestId, 'by' => e($reviewer)]));
        }
    }

    // --- Reseller -----------------------------------------------------------

    protected function showReseller(?int $messageId = null): void
    {
        $seller = $this->resellers->requireSeller($this->user);
        $rows = [
            [Keyboard::button(__('shahbot::bot.btn_bulk_buy'), 'rs:buy')],
            [Keyboard::button(__('shahbot::bot.btn_my_accounts'), 'rs:acc')],
        ];

        if (str_starts_with((string) config('app.url'), 'https://')) {
            $rows[] = [Keyboard::url(__('shahbot::bot.btn_open_panel'), route('login'))];
        }

        $this->say($messageId, __('shahbot::bot.reseller_menu', [
            'username' => e($seller->username),
            'balance' => format_money($this->resellers->balance($seller)),
            'accounts' => persian_digits(Account::query()->where('owner_seller_id', $seller->id)->count()),
        ]), Keyboard::inline($rows));
    }

    protected function resellerCallback(array $parts, int $messageId): void
    {
        $seller = $this->resellers->requireSeller($this->user);

        match ($parts[1] ?? '') {
            'home' => $this->showReseller($messageId),
            'buy' => $this->showResellerCatalog($seller, $messageId),
            'd' => $this->chooseResellerProduct($seller, (int) ($parts[2] ?? 0), $messageId),
            'q' => $this->confirmBulk($seller, (int) ($parts[2] ?? 0), (int) ($parts[3] ?? 0), (int) ($parts[4] ?? 0), $messageId),
            'ok' => $this->doBulk((int) ($parts[2] ?? 0), (int) ($parts[3] ?? 0), (int) ($parts[4] ?? 0), $messageId),
            'acc' => $this->showResellerAccounts($seller, $messageId),
            default => null,
        };
    }

    protected function showResellerCatalog(User $seller, int $messageId): void
    {
        $catalog = $this->resellers->catalog($seller);

        if ($catalog->isEmpty()) {
            throw new InvalidArgumentException(__('shahbot::bot.buy_empty'));
        }

        $buttons = $catalog->take(40)->map(fn (array $row) => [Keyboard::button(
            $row['duration']->package->name.' · '.$row['duration']->tier->label().' · '
                .($row['duration']->package->isElastic() ? __('shahbot::bot.per_gb', ['price' => format_money($row['price'])]) : format_money($row['price'])),
            'rs:d:'.$row['duration']->id
        )])->all();
        $buttons[] = [Keyboard::button(__('shahbot::bot.back'), 'rs:home')];

        $this->say($messageId, __('shahbot::bot.bulk_choose'), Keyboard::inline($buttons));
    }

    protected function chooseResellerProduct(User $seller, int $durationId, int $messageId): void
    {
        $row = $this->resellers->catalog($seller)->first(fn (array $r) => (int) $r['duration']->id === $durationId);

        if ($row === null) {
            throw new InvalidArgumentException(__('shahbot::bot.product_unavailable'));
        }

        $package = $row['duration']->package;

        if ($package->isElastic()) {
            $this->askFor('rs_gb', __('shahbot::bot.buy_ask_gb', [
                'name' => e($package->name),
                'min' => persian_digits((float) ($package->min_data_gb ?: 1)),
                'max' => persian_digits((float) ($package->max_data_gb ?: 1000)),
                'price' => format_money($row['price']),
            ]), ['duration' => $durationId]);

            return;
        }

        $this->showQuantities($durationId, 0, $messageId);
    }

    protected function showQuantities(int $durationId, int $gb, ?int $messageId): void
    {
        $max = max(1, (int) $this->settings->get('bulk_max'));
        $options = array_values(array_unique(array_filter([1, 2, 3, 5, 10, 20, 50], fn ($n) => $n <= $max)));
        $buttons = array_map(fn ($n) => Keyboard::button(persian_digits($n), 'rs:q:'.$durationId.':'.$n.':'.$gb), $options);
        $rows = Keyboard::grid($buttons, 4);
        $rows[] = [Keyboard::button(__('shahbot::bot.back'), 'rs:buy')];

        $this->say($messageId, __('shahbot::bot.bulk_quantity', ['max' => persian_digits($max)]), Keyboard::inline($rows));
    }

    protected function confirmBulk(User $seller, int $durationId, int $quantity, int $gb, int $messageId): void
    {
        $row = $this->resellers->catalog($seller)->first(fn (array $r) => (int) $r['duration']->id === $durationId);

        if ($row === null) {
            throw new InvalidArgumentException(__('shahbot::bot.product_unavailable'));
        }

        $unit = $this->resellers->unitPrice($seller, $row['duration'], $gb > 0 ? (float) $gb : null) ?? '0';
        $total = (float) $unit * $quantity;
        $balance = $this->resellers->balance($seller);

        $this->say($messageId, __('shahbot::bot.bulk_confirm', [
            'name' => e($row['duration']->package->name.' · '.$row['duration']->tier->label().($gb > 0 ? ' · '.persian_digits($gb).' GB' : '')),
            'qty' => persian_digits($quantity),
            'unit' => format_money($unit),
            'total' => format_money($total),
            'balance' => format_money($balance),
        ]), Keyboard::inline([
            (float) $balance >= $total
                ? [Keyboard::button(__('shahbot::bot.btn_confirm'), 'rs:ok:'.$durationId.':'.$quantity.':'.$gb)]
                : [],
            [Keyboard::button(__('shahbot::bot.back'), 'rs:buy')],
        ]));
    }

    protected function doBulk(int $durationId, int $quantity, int $gb, int $messageId): void
    {
        $lock = Cache::lock('shahbot:buy:'.$this->user->id, 600);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->say($messageId, __('shahbot::bot.bulk_working', ['qty' => persian_digits($quantity)]));
            $result = $this->resellers->bulkBuy($this->user, $durationId, $quantity, $gb > 0 ? (float) $gb : null);
            $this->sendAccountList($result['accounts'], __('shahbot::bot.bulk_done', [
                'done' => persian_digits(count($result['accounts'])),
                'qty' => persian_digits($quantity),
            ]));

            if ($result['error'] !== null) {
                $this->reply(__('shahbot::bot.bulk_stopped', ['error' => e($result['error'])]));
            }
        } finally {
            $lock->release();
        }
    }

    protected function showResellerAccounts(User $seller, int $messageId): void
    {
        $accounts = $this->resellers->accounts($seller, 15);

        if ($accounts->isEmpty()) {
            $this->say($messageId, __('shahbot::bot.services_empty'), Keyboard::inline([[Keyboard::button(__('shahbot::bot.back'), 'rs:home')]]));

            return;
        }

        $this->sendAccountList($accounts->all(), __('shahbot::bot.reseller_accounts_title'));
    }

    /**
     * @param  list<Account>  $accounts
     */
    protected function sendAccountList(array $accounts, string $title): void
    {
        $feed = app(SubscriptionFeedService::class);
        $lines = [];
        $plain = [];

        foreach ($accounts as $account) {
            $link = $account->service_type->accountCategory() === AccountCategory::V2ray
                ? $feed->urlFor($account)
                : trim($account->remote_username.' / '.($account->remote_password_enc ?? ''));
            $lines[] = '🔹 <b>'.e($this->accountName($account)).'</b>'."\n".'<code>'.e($link).'</code>';
            $plain[] = $this->accountName($account)."\t".$link;
        }

        if (count($lines) <= 5) {
            $this->reply($title."\n\n".implode("\n\n", $lines));

            return;
        }

        $this->reply($title);
        $this->tg->sendDocumentBytes($this->chatId, implode("\n", $plain)."\n", 'accounts-'.now()->format('Ymd-His').'.txt');
    }

    // ------------------------------------------------------------------
    // Output helpers
    // ------------------------------------------------------------------

    protected function reply(string $text, ?array $keyboard = null): void
    {
        $this->tg->sendMessage($this->chatId, $text, $keyboard);
    }

    /**
     * Edits the message the button sat on when there is one, so menus change
     * in place instead of piling up.
     */
    protected function say(?int $messageId, string $text, ?array $keyboard = null): void
    {
        if ($messageId) {
            $this->tg->editMessage($this->chatId, $messageId, $text, $keyboard);
        } else {
            $this->reply($text, $keyboard);
        }
    }
}
