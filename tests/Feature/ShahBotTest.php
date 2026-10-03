<?php

namespace Tests\Feature;

use App\Enums\AccountBillingContext;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\GatewayPayment;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Services\AccountService;
use App\Services\ServerSelectionService;
use App\Services\UserPackagePricingService;
use App\Services\WalletService;
use App\Support\GatewayReturnUrls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Modules\ShahBot\Bot\UpdateHandler;
use Modules\ShahBot\Models\BotAgencyRequest;
use Modules\ShahBot\Models\BotCode;
use Modules\ShahBot\Models\BotGatewayPayment;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Models\BotOrder;
use Modules\ShahBot\Models\BotPayment;
use Modules\ShahBot\Models\BotReferralReward;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Services\BotUserService;
use Modules\ShahBot\Services\CodeService;
use Modules\ShahBot\Services\OnlinePaymentService;
use Modules\ShahBot\Services\PaymentService;
use Modules\ShahBot\Services\ShopService;
use Modules\ShahBot\ShahBotServiceProvider;
use Modules\ShahBot\Support\BotSettings;
use Modules\ShahBot\Support\BotTexts;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class ShahBotTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        // The module is not active in the test install; boot it by hand.
        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'Modules\\ShahBot\\')) {
                $file = base_path('modules/shahbot/src/'.str_replace('\\', '/', substr($class, strlen('Modules\\ShahBot\\'))).'.php');
                if (is_file($file)) {
                    require_once $file;
                }
            }
        });
        $this->app->register(ShahBotServiceProvider::class);
        $this->app['router']->getRoutes()->refreshNameLookups();

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $this->owner = $this->makeAgent();
        app(BotSettings::class)->set([
            'bot_token' => '123456:'.str_repeat('a', 35),
            'owner_user_id' => (string) $this->owner->id,
            'admin_chat_ids' => '999',
            'webhook_secret' => str_repeat('s', 40),
            'card_number' => '6037991234567890',
        ]);
    }

    protected function botUser(int $telegramId = 1001, array $attributes = []): BotUser
    {
        return BotUser::query()->create(array_merge(['telegram_id' => $telegramId, 'first_name' => 'Ali'], $attributes));
    }

    protected function balance(User $user): string
    {
        return number_format((float) app(WalletService::class)->getOrCreateWallet($user)->fresh()->balance, 2, '.', '');
    }

    /**
     * A package the owner can sell, with the remote account creation stubbed.
     */
    protected function sellablePackage(): array
    {
        $this->makeAdmin();
        [$package, $duration] = $this->makePackage();
        $server = $this->makeServer();
        $package->servers()->attach($server->id);
        $this->assignPackage($this->owner, $package);
        \DB::table('user_package_duration_prices')->insert([
            'user_id' => $this->owner->id,
            'package_duration_id' => $duration->id,
            'wholesale_price' => 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accounts = Mockery::mock(AccountService::class)->makePartial();
        $accounts->shouldReceive('createAccount')->andReturnUsing(
            fn (User $owner, $pkg, $srv, $dur, array $data, AccountBillingContext $ctx = AccountBillingContext::Staff) => $this->makeAccount($owner, $srv, [
                'client_user_id' => $data['client_user_id'] ?? null,
                'package_id' => $pkg->id,
                'package_duration_id' => $dur->id,
                'expiry_at' => now()->addDays(30),
            ])
        );
        $this->app->instance(AccountService::class, $accounts);

        $selection = Mockery::mock(ServerSelectionService::class);
        $selection->shouldReceive('pickLeastBusyForPackage')->andReturn($server);
        $this->app->instance(ServerSelectionService::class, $selection);

        return [$package, $duration];
    }

    public function test_webhook_requires_the_secret_and_answers_start(): void
    {
        $this->postJson('/shahbot/webhook/'.str_repeat('x', 40), [])->assertNotFound();
        $this->postJson('/shahbot/webhook/'.str_repeat('s', 40), [])->assertNotFound();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', str_repeat('s', 40))
            ->postJson('/shahbot/webhook/'.str_repeat('s', 40), [
                'update_id' => 1,
                'message' => [
                    'message_id' => 5,
                    'chat' => ['id' => 2002, 'type' => 'private'],
                    'from' => ['id' => 2002, 'first_name' => 'Sara'],
                    'text' => '/start ref_1001',
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('shahbot_users', ['telegram_id' => 2002]);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '2002'
            && str_contains((string) $request['text'], 'Sara'));
    }

    public function test_receipt_approval_moves_money_from_owner_to_user(): void
    {
        app(WalletService::class)->credit($this->owner, '500000.00', TransactionType::Charge);
        $user = $this->botUser();

        $payments = app(PaymentService::class);
        $payment = $payments->start($user, '۲۰۰٬۰۰۰');
        $payments->attachReceipt($payment, 'file-123', 'paid');
        $payments->approve($payment->fresh(), 'tester');

        $client = app(BotUserService::class)->client($user->fresh());
        $this->assertSame('200000.00', $this->balance($client));
        $this->assertSame('300000.00', $this->balance($this->owner));
        $this->assertSame(BotPayment::APPROVED, $payment->fresh()->status);

        // A second approval of the same receipt must not pay twice.
        $this->expectException(\InvalidArgumentException::class);
        $payments->approve($payment->fresh(), 'tester');
    }

    public function test_purchase_with_discount_and_referral_reward(): void
    {
        [, $duration] = $this->sellablePackage();
        app(WalletService::class)->credit($this->owner, '10000.00', TransactionType::Charge);
        app(BotSettings::class)->set(['referral_enabled' => '1', 'referral_percent' => '10']);

        $referrer = $this->botUser(1001);
        $user = $this->botUser(1002, ['referrer_id' => $referrer->id]);
        $client = app(BotUserService::class)->client($user);
        app(WalletService::class)->credit($client, '900.00', TransactionType::Charge);

        BotCode::query()->create(['kind' => 'discount', 'code' => 'OFF20', 'value_type' => 'percent', 'value' => 20]);

        $quote = app(ShopService::class)->quote($user, $duration->id, null, 'off20');
        $this->assertSame('1000.00', $quote['total']);
        $this->assertSame('800.00', $quote['payable']);

        $order = app(ShopService::class)->purchase($user, $duration->id, null, 'OFF20');

        $this->assertNotNull($order->account_id);
        $this->assertSame((int) $client->id, (int) Account::query()->find($order->account_id)->client_user_id);
        $this->assertSame('800.00', number_format((float) $order->amount, 2, '.', ''));
        $this->assertSame('100.00', $this->balance($client));
        $this->assertSame(1, BotCode::query()->where('code', 'OFF20')->value('used_count'));

        // The inviter earns 10% of what was paid.
        $this->assertSame('80.00', $this->balance(app(BotUserService::class)->client($referrer->fresh())));
        $this->assertSame(1, BotReferralReward::query()->count());

        // The same code cannot be used twice by one user.
        $this->expectException(\InvalidArgumentException::class);
        app(ShopService::class)->quote($user, $duration->id, null, 'OFF20');
    }

    public function test_purchase_fails_cleanly_without_balance(): void
    {
        [, $duration] = $this->sellablePackage();
        $user = $this->botUser();

        try {
            app(ShopService::class)->purchase($user, $duration->id);
            $this->fail('A purchase without balance must be refused.');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame(0, BotOrder::query()->count());
        $this->assertSame(0, Account::query()->count());
    }

    public function test_gift_code_is_redeemed_once(): void
    {
        $user = $this->botUser();
        BotCode::query()->create(['kind' => 'gift', 'code' => 'GIFT50', 'value' => 50, 'max_uses' => 10]);

        $this->assertSame('50.00', app(CodeService::class)->redeemGift($user, ' gift50 '));
        $this->assertSame('50.00', $this->balance(app(BotUserService::class)->client($user->fresh())));
        $this->assertSame('-50.00', $this->balance($this->owner));

        $this->expectException(\InvalidArgumentException::class);
        app(CodeService::class)->redeemGift($user, 'GIFT50');
    }

    public function test_module_is_listed_but_off_until_activated(): void
    {
        $this->assertDatabaseHas('modules', ['slug' => 'shahbot', 'status' => 'installed']);
        $this->assertArrayHasKey('shahbot', config('admin_sections.sections'));
    }

    public function test_admin_pages_render(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->botUser();
        BotPayment::query()->create(['bot_user_id' => $user->id, 'amount' => 1000, 'status' => BotPayment::PENDING]);

        foreach ([
            'admin.shahbot.index',
            'admin.shahbot.orders',
            'admin.shahbot.users.index',
            'admin.shahbot.payments.index',
            'admin.shahbot.codes.index',
            'admin.shahbot.broadcasts.index',
            'admin.shahbot.tickets.index',
            'admin.shahbot.tutorials.index',
            'admin.shahbot.agents.index',
        ] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }

        foreach (['connection', 'store', 'wallet', 'online', 'agents', 'marketing', 'gates', 'texts'] as $tab) {
            $this->actingAs($admin)->get(route('admin.shahbot.settings', ['tab' => $tab]))->assertOk();
        }

        $this->actingAs($admin)->get(route('admin.shahbot.users.show', $user))->assertOk();

        // An agent's own bot page, and saving a token for it.
        app(BotSettings::class)->set(['agent_bots_enabled' => '1']);
        $agent = $this->makeAgent();
        $this->actingAs($agent)->get(route('agent.shahbot.my-bot'))->assertOk();
        $this->actingAs($agent)->post(route('agent.shahbot.my-bot.update'), ['bot_token' => '777777:'.str_repeat('c', 35), 'admin_chat_ids' => '1'])->assertRedirect();
        $this->assertSame('777777:'.str_repeat('c', 35), BotInstance::query()->where('owner_user_id', $agent->id)->firstOrFail()->token());
        // The main bot's token cannot be claimed.
        $this->actingAs($this->makeSeller())->post(route('seller.shahbot.my-bot.update'), ['bot_token' => '123456:'.str_repeat('a', 35)])->assertSessionHasErrors('bot_token');
    }

    public function test_settings_save_keeps_the_token_secret(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->post(route('admin.shahbot.settings.update'), [
            'tab' => 'store',
            '_bool_sales_enabled' => '1',
            'closed_text' => 'closed',
        ])->assertRedirect();

        $settings = app(BotSettings::class);
        $settings->flush();
        $this->assertFalse($settings->bool('sales_enabled'));
        $this->assertSame('123456:'.str_repeat('a', 35), $settings->get('bot_token'));
        $this->assertStringNotContainsString(str_repeat('a', 35), (string) \DB::table('shahbot_settings')->where('key', 'bot_token')->value('value'));
    }

    protected function update(array $payload): void
    {
        app(UpdateHandler::class)->handle($payload);
    }

    protected function text(int $from, string $text): void
    {
        $this->update(['message' => [
            'message_id' => random_int(1, 9999),
            'chat' => ['id' => $from, 'type' => 'private'],
            'from' => ['id' => $from, 'first_name' => 'U'.$from],
            'text' => $text,
        ]]);
    }

    protected function press(int $from, string $data): void
    {
        $this->update(['callback_query' => [
            'id' => (string) random_int(1, 99999),
            'from' => ['id' => $from, 'first_name' => 'U'.$from],
            'message' => ['message_id' => 77, 'chat' => ['id' => $from, 'type' => 'private']],
            'data' => $data,
        ]]);
    }

    public function test_full_bot_flow_top_up_then_buy(): void
    {
        [, $duration] = $this->sellablePackage();
        app(WalletService::class)->credit($this->owner, '100000.00', TransactionType::Charge);
        app(BotSettings::class)->set(['topup_min' => '1000', 'topup_max' => '50000']);

        $this->text(3003, '/start');
        $this->text(3003, __('shahbot::bot.menu_wallet', [], 'fa'));

        // Top-up of 5000 by card, receipt photo, admin approves in Telegram.
        $this->press(3003, 'wal:amt:5000');
        $user = BotUser::query()->where('telegram_id', 3003)->firstOrFail();
        $this->assertSame('topup_receipt', $user->step);

        $this->update(['message' => [
            'message_id' => 9,
            'chat' => ['id' => 3003, 'type' => 'private'],
            'from' => ['id' => 3003, 'first_name' => 'U3003'],
            'photo' => [['file_id' => 'small'], ['file_id' => 'big']],
        ]]);
        $payment = BotPayment::query()->where('bot_user_id', $user->id)->firstOrFail();
        $this->assertSame(BotPayment::PENDING, $payment->status);
        $this->assertSame('big', $payment->receipt_file_id);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/sendPhoto') && (string) $r['chat_id'] === '999');

        // A non-admin cannot approve.
        $this->press(3003, 'adm:pay:ok:'.$payment->id);
        $this->assertSame(BotPayment::PENDING, $payment->fresh()->status);

        $this->press(999, 'adm:pay:ok:'.$payment->id);
        $this->assertSame(BotPayment::APPROVED, $payment->fresh()->status);

        // Buy from the store with the wallet.
        $this->text(3003, __('shahbot::bot.menu_buy', [], 'fa'));
        $this->press(3003, 'buy:d:'.$duration->id);
        $this->press(3003, 'buy:pay');

        $order = BotOrder::query()->where('bot_user_id', $user->id)->where('type', 'buy')->first();
        $this->assertNotNull($order);
        $this->assertSame('4000.00', $this->balance(app(BotUserService::class)->client($user->fresh())));

        // The service shows up under "my services" and only for its owner.
        $this->press(3003, 'svc:v:'.$order->account_id);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/editMessageText') && str_contains((string) $r['text'], (string) $order->account->remote_username));

        $this->text(4004, '/start');
        $this->press(4004, 'svc:ar:'.$order->account_id);
        $this->assertFalse((bool) $order->account->fresh()->auto_renew);
    }

    public function test_blocked_user_and_required_channel(): void
    {
        $this->botUser(5005, ['is_blocked' => true]);
        $this->text(5005, '/start');
        Http::assertSent(fn ($r) => (string) ($r['chat_id'] ?? '') === '5005' && str_contains((string) $r['text'], '⛔'));

        app(BotSettings::class)->set(['channels' => '@shahchan']);
        Http::fake([
            'api.telegram.org/*/getChatMember' => Http::response(['ok' => true, 'result' => ['status' => 'left']]),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        $this->text(6006, __('shahbot::bot.menu_buy', [], 'fa'));
        Http::assertSent(fn ($r) => (string) ($r['chat_id'] ?? '') === '6006' && str_contains((string) ($r['reply_markup'] ?? ''), 'join:check'));
    }

    public function test_telegram_stars_top_up_is_credited_once(): void
    {
        app(BotSettings::class)->set(['pay_stars' => '1', 'stars_rate' => '1000', 'topup_min' => '1000']);
        $this->text(7007, '/start');

        // Card and Stars are both open, so the bot asks which one.
        $this->press(7007, 'wal:amt:5000');
        Http::assertSent(fn ($r) => str_contains((string) ($r['reply_markup'] ?? ''), 'wal:pay:st:5000'));

        $this->press(7007, 'wal:pay:st:5000');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/sendInvoice') && $r['currency'] === 'XTR'
            && str_contains((string) $r['prices'], '"amount":5'));

        $payment = BotPayment::query()->where('method', 'stars')->firstOrFail();
        $this->assertSame(5, $payment->stars);

        $this->update(['pre_checkout_query' => [
            'id' => 'pcq1', 'from' => ['id' => 7007], 'currency' => 'XTR', 'total_amount' => 5, 'invoice_payload' => 'stars:'.$payment->id,
        ]]);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/answerPreCheckoutQuery') && $r['ok'] === 'true');

        $paid = ['message' => [
            'message_id' => 50, 'chat' => ['id' => 7007, 'type' => 'private'], 'from' => ['id' => 7007, 'first_name' => 'S'],
            'successful_payment' => ['currency' => 'XTR', 'total_amount' => 5, 'invoice_payload' => 'stars:'.$payment->id, 'telegram_payment_charge_id' => 'ch_1'],
        ]];
        $this->update($paid);
        $this->update($paid);

        $user = BotUser::query()->where('telegram_id', 7007)->firstOrFail();
        $this->assertSame('5000.00', $this->balance(app(BotUserService::class)->client($user)));
        $this->assertSame(BotPayment::APPROVED, $payment->fresh()->status);

        // A forged amount is refused at checkout.
        $this->update(['pre_checkout_query' => [
            'id' => 'pcq2', 'from' => ['id' => 7007], 'currency' => 'XTR', 'total_amount' => 1, 'invoice_payload' => 'stars:'.$payment->id,
        ]]);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/answerPreCheckoutQuery') && $r['pre_checkout_query_id'] === 'pcq2' && $r['ok'] === 'false');
    }

    public function test_gateway_payment_returns_to_the_bot_and_notifies(): void
    {
        $user = $this->botUser(8008);
        $client = app(BotUserService::class)->client($user);
        $gateway = PaymentGateway::query()->firstOrCreate(['driver' => 'zarinpal'], ['display_name' => 'ZarinPal']);
        $payment = GatewayPayment::query()->forceCreate([
            'uuid' => (string) Str::uuid(),
            'user_id' => $client->id,
            'payment_gateway_id' => $gateway->id,
            'driver' => 'zarinpal',
            'status' => 'completed',
            'gross_toman' => 100000,
            'net_toman' => 100000,
            'commission_payer' => 'user',
        ]);
        BotGatewayPayment::query()->create(['bot_user_id' => $user->id, 'gateway_payment_id' => $payment->id]);

        // The panel's gateways send a bot client to the bot's own page.
        $this->assertSame(route('shahbot.pay.return', $payment->uuid), GatewayReturnUrls::for($payment, 'success'));

        $this->get(route('shahbot.pay.return', $payment->uuid))->assertOk()->assertSee('✅');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/sendMessage') && (string) $r['chat_id'] === '8008');

        // Already told: the minute job stays quiet.
        $this->assertSame(0, app(OnlinePaymentService::class)->notifyFinished());
        $this->get(route('shahbot.pay.return', Str::uuid()))->assertNotFound();
    }

    public function test_agent_bot_has_its_own_users_and_token(): void
    {
        app(BotSettings::class)->set(['agent_bots_enabled' => '1']);
        $agent = $this->makeAgent();
        $bot = new BotInstance(['owner_user_id' => $agent->id, 'webhook_secret' => str_repeat('a', 40), 'is_active' => true]);
        $bot->setToken('654321:'.str_repeat('b', 35));
        $bot->settings = ['admin_chat_ids' => '555'];
        $bot->save();

        $payload = ['update_id' => 2, 'message' => [
            'message_id' => 1, 'chat' => ['id' => 9009, 'type' => 'private'], 'from' => ['id' => 9009, 'first_name' => 'Agent fan'], 'text' => '/start',
        ]];

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', str_repeat('a', 40))
            ->postJson('/shahbot/webhook/'.str_repeat('a', 40), $payload)->assertOk();

        $user = BotUser::query()->where('telegram_id', 9009)->firstOrFail();
        $this->assertSame($bot->id, $user->bot_id);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/bot654321:') && str_contains($r->url(), '/sendMessage'));

        // Its users are the agent's clients.
        $this->assertSame($agent->id, app(BotUserService::class)->client($user)->parent_id);

        // The same Telegram user is a separate user of the main bot.
        $this->text(9009, '/start');
        $this->assertSame(2, BotUser::query()->where('telegram_id', 9009)->count());

        // A disabled agent bot answers nothing.
        $bot->update(['is_active' => false]);
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', str_repeat('a', 40))
            ->postJson('/shahbot/webhook/'.str_repeat('a', 40), $payload)->assertNotFound();
    }

    public function test_agency_request_approval_and_bulk_buy(): void
    {
        [, $duration] = $this->sellablePackage();
        app(BotSettings::class)->set(['agency_enabled' => '1', 'agency_discount' => '10']);

        $this->text(4040, '/start');
        $this->text(4040, __('shahbot::bot.menu_agency', [], 'fa'));
        $this->text(4040, 'I sell a lot of VPNs');

        $request = BotAgencyRequest::query()->firstOrFail();
        Http::assertSent(fn ($r) => (string) ($r['chat_id'] ?? '') === '999' && str_contains((string) ($r['reply_markup'] ?? ''), 'adm:ag:ok:'.$request->id));

        $this->press(999, 'adm:ag:ok:'.$request->id);

        $user = BotUser::query()->where('telegram_id', 4040)->firstOrFail();
        $seller = $user->reseller;
        $this->assertNotNull($seller);
        $this->assertSame(UserRole::Seller, $seller->role);
        $this->assertSame($this->owner->id, $seller->parent_id);
        $this->assertSame('approved', $request->fresh()->status);

        // Seller price: store price 1000 less 10%, but never below the agent's 1000.
        $this->assertSame('1000.00', app(UserPackagePricingService::class)->wholesalePriceFor($seller, $duration));

        app(WalletService::class)->credit($seller, '5000.00', TransactionType::Charge);
        $this->press(4040, 'rs:ok:'.$duration->id.':2:0');

        $this->assertSame(2, BotOrder::query()->where('bot_user_id', $user->id)->where('type', 'bulk')->count());
        $this->assertSame(2, Account::query()->where('owner_seller_id', $seller->id)->count());
    }

    public function test_text_and_keyboard_editor(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin)->get(route('admin.shahbot.editor'))->assertOk();

        $this->actingAs($admin)->post(route('admin.shahbot.editor.texts'), ['texts' => [
            'menu_wallet' => '💰 Wallet!',
            'menu_buy' => __('shahbot::bot.menu_buy', [], 'fa'), // unchanged: not stored
        ]])->assertRedirect();
        $this->assertSame(['menu_wallet' => '💰 Wallet!'], app(BotTexts::class)->overrides());

        $this->actingAs($admin)->post(route('admin.shahbot.editor.keyboard'), ['layout' => [
            'buy' => ['row' => 1, 'pos' => 2, 'on' => 1],
            'services' => ['row' => 1, 'pos' => 1, 'on' => 1],
            'wallet' => ['row' => 2, 'pos' => 1, 'on' => 1],
            'gift' => ['row' => 3, 'pos' => 1],
        ]])->assertRedirect();

        $this->text(1212, '/start');
        Http::assertSent(function ($r) {
            $markup = json_decode((string) ($r['reply_markup'] ?? ''), true);
            $rows = $markup['keyboard'] ?? null;

            return is_array($rows)
                && $rows[0][0]['text'] === __('shahbot::bot.menu_services', [], 'fa')
                && $rows[1][0]['text'] === '💰 Wallet!'
                && ! str_contains(json_encode($rows, JSON_UNESCAPED_UNICODE), __('shahbot::bot.menu_gift', [], 'fa'));
        });

        // The renamed button still opens the wallet.
        $this->text(1212, '💰 Wallet!');
        Http::assertSent(fn ($r) => str_contains((string) ($r['reply_markup'] ?? ''), 'wal:hist'));
    }

    public function test_mini_app_needs_a_valid_telegram_signature(): void
    {
        [, $duration] = $this->sellablePackage();
        $user = $this->botUser(1313);
        $client = app(BotUserService::class)->client($user);
        $this->makeAccount($this->owner, $this->makeServer(), ['client_user_id' => $client->id, 'expiry_at' => now()->addDays(5), 'data_limit_bytes' => 1000, 'data_used_bytes' => 250]);

        $this->get(route('shahbot.app', ['bot' => 0]))->assertOk()->assertSee('telegram-web-app.js', false);

        $token = '123456:'.str_repeat('a', 35);
        $fields = ['auth_date' => (string) time(), 'query_id' => 'q1', 'user' => json_encode(['id' => 1313, 'first_name' => 'Ali'])];
        ksort($fields);
        $check = implode("\n", array_map(fn ($k, $v) => $k.'='.$v, array_keys($fields), $fields));
        $fields['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', $token, 'WebAppData', true));
        $initData = http_build_query($fields);

        $this->postJson(route('shahbot.app.me', ['bot' => 0]), ['initData' => $initData])
            ->assertOk()
            ->assertJsonPath('registered', true)
            ->assertJsonPath('services.0.percent', 25);

        $fields['hash'] = str_repeat('0', 64);
        $this->postJson(route('shahbot.app.me', ['bot' => 0]), ['initData' => http_build_query($fields)])->assertUnauthorized();
    }
}
