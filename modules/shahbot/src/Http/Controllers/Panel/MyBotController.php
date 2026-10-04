<?php

namespace Modules\ShahBot\Http\Controllers\Panel;

use App\Enums\UserRole;
use App\Services\ClientDisplayPricingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Models\BotOrder;
use Modules\ShahBot\Models\BotPackage;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Services\BroadcastService;
use Modules\ShahBot\Services\WebhookService;
use Modules\ShahBot\Support\BotAccess;
use Modules\ShahBot\Support\BotContext;
use Modules\ShahBot\Support\BotSettings;

/**
 * "My sales bot" for agents and sellers: their own bot token, admins, card
 * and texts. Everything else (store, gateways, rules) is the main bot's.
 */
class MyBotController extends Controller
{
    public function edit(Request $request, BotSettings $settings, WebhookService $webhooks): View
    {
        $user = $request->user();
        abort_unless(in_array($user->role, [UserRole::Agent, UserRole::Seller], true), 403);

        $bot = BotInstance::query()->where('owner_user_id', $user->id)->first();

        return view('shahbot::panel.my-bot', [
            'panel' => $user->role === UserRole::Agent ? 'agent' : 'seller',
            'enabled' => app(BotAccess::class)->allows($request->user()),
            'bot' => $bot,
            'values' => (array) ($bot?->settings ?? []),
            'stats' => $bot ? [
                'users' => BotUser::query()->where('bot_id', $bot->id)->count(),
                'sales' => (string) BotOrder::query()->whereIn('type', ['buy', 'renew'])
                    ->whereIn('bot_user_id', BotUser::query()->where('bot_id', $bot->id)->select('id'))->sum('amount'),
            ] : null,
            'webhookInfo' => $bot && $bot->token() !== '' ? rescue(fn () => app(BotContext::class)->run($bot, fn () => $webhooks->info()), [], false) : [],
        ]);
    }

    public function update(Request $request, BotSettings $settings): RedirectResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, [UserRole::Agent, UserRole::Seller], true), 403);
        abort_unless(app(BotAccess::class)->allows($request->user()), 403);

        $data = $request->validate([
            'bot_token' => ['nullable', 'string', 'regex:/^\d{5,}:[A-Za-z0-9_-]{30,}$/'],
            'admin_chat_ids' => ['nullable', 'string', 'max:500'],
            'card_number' => ['nullable', 'string', 'max:40'],
            'card_holder' => ['nullable', 'string', 'max:100'],
            'card_bank' => ['nullable', 'string', 'max:100'],
            'card_note' => ['nullable', 'string', 'max:500'],
            'welcome_text' => ['nullable', 'string', 'max:3500'],
            'support_text' => ['nullable', 'string', 'max:1000'],
            'channels' => ['nullable', 'string', 'max:500'],
            'rules_text' => ['nullable', 'string', 'max:3500'],
            'np_api_key' => ['nullable', 'string', 'max:200'],
            'np_ipn_secret' => ['nullable', 'string', 'max:200'],
            'brand_name' => ['nullable', 'string', 'max:80'],
            'about_text' => ['nullable', 'string', 'max:3500'],
            'contact_text' => ['nullable', 'string', 'max:1000'],
            'faq_text' => ['nullable', 'string', 'max:3500'],
        ]);

        $bot = BotInstance::query()->firstOrNew(['owner_user_id' => $user->id]);

        if (! $bot->exists) {
            $bot->webhook_secret = Str::random(40);
            $bot->is_active = true;
        }

        if (filled($data['bot_token'] ?? null)) {
            // One token, one bot: the main bot's or another agent's must not be taken over.
            $taken = $data['bot_token'] === $settings->main('bot_token')
                || BotInstance::query()->where('id', '!=', (int) $bot->id)->get()->contains(fn (BotInstance $b) => $b->token() === $data['bot_token']);

            if ($taken) {
                return back()->withErrors(['bot_token' => __('shahbot::admin.token_in_use')])->withInput();
            }

            $bot->setToken($data['bot_token']);
        }

        unset($data['bot_token']);
        $previous = (array) ($bot->settings ?? []);
        // Keys are kept encrypted and an empty field leaves the saved one in
        // place, so the form never has to show a secret back.
        $secrets = [];
        foreach (['np_api_key', 'np_ipn_secret'] as $field) {
            $value = trim((string) $request->input($field, ''));
            $secrets[$field.'_enc'] = $value !== '' ? \Illuminate\Support\Facades\Crypt::encryptString($value) : ($previous[$field.'_enc'] ?? null);
        }
        if ($request->boolean('np_clear')) {
            $secrets = ['np_api_key_enc' => null, 'np_ipn_secret_enc' => null];
        }
        $bot->settings = array_merge(array_map(fn ($v) => (string) ($v ?? ''), $data), array_filter($secrets, fn ($v) => $v !== null));
        $bot->save();

        return back()->with('success', __('shahbot::admin.saved'));
    }

    /**
     * The tariffs this bot sells, and at what price.
     *
     * The list is built from the owner's own catalog, so an agent can only ever
     * pick from what the panel already lets them sell. Servers and inbounds are
     * not part of it and are never shown -- a reseller sells a tariff, not a
     * machine.
     */
    public function plans(Request $request, BotSettings $settings, ClientDisplayPricingService $pricing): View
    {
        $user = $request->user();
        abort_unless(in_array($user->role, [UserRole::Agent, UserRole::Seller], true), 403);

        $bot = BotInstance::query()->where('owner_user_id', $user->id)->first();
        $own = $bot === null
            ? collect()
            : BotPackage::query()->where('bot_id', $bot->id)->get()->keyBy('package_duration_id');

        return view('shahbot::panel.my-plans', [
            'panel' => $user->role === UserRole::Agent ? 'agent' : 'seller',
            'enabled' => app(BotAccess::class)->allows($request->user()),
            'bot' => $bot,
            'rows' => $pricing->managementCatalogForOwner($user)
                ->filter(fn (array $row): bool => ! $row['duration']->tier->isTest())
                ->values(),
            'own' => $own,
        ]);
    }

    public function savePlans(Request $request, BotSettings $settings, ClientDisplayPricingService $pricing): RedirectResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, [UserRole::Agent, UserRole::Seller], true), 403);
        abort_unless(app(BotAccess::class)->allows($request->user()), 403);

        $bot = BotInstance::query()->where('owner_user_id', $user->id)->first();

        if ($bot === null) {
            return back()->with('error', __('shahbot::admin.my_bot_needed_first'));
        }

        $data = $request->validate([
            'enabled' => ['nullable', 'array'],
            'enabled.*' => ['nullable', 'boolean'],
            'prices' => ['nullable', 'array'],
            'prices.*' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
        ]);

        // Only tariffs the owner really has. Anything else in the request is
        // ignored rather than rejected, so a stale form does not block a save.
        $allowed = $pricing->managementCatalogForOwner($user)
            ->filter(fn (array $row): bool => ! $row['duration']->tier->isTest())
            ->keyBy(fn (array $row): int => (int) $row['duration']->id);

        $tooCheap = [];

        foreach ($allowed as $durationId => $row) {
            $price = $data['prices'][$durationId] ?? null;
            $price = ($price === null || $price === '') ? null : number_format((float) $price, 2, '.', '');

            // A price under the owner's own means the panel charges them more
            // than the customer pays, and every sale eats their wallet. Refused
            // here and again when the store reads the row.
            if ($price !== null && bccomp($price, (string) $row['display_price'], 2) < 0) {
                $tooCheap[] = $row['package']->name.' · '.$row['duration']->tier->label();

                continue;
            }

            BotPackage::query()->updateOrCreate(
                ['bot_id' => $bot->id, 'package_duration_id' => (int) $durationId],
                [
                    'is_enabled' => (bool) ($data['enabled'][$durationId] ?? false),
                    'display_price' => $price,
                ],
            );
        }

        // Rows for tariffs the owner no longer has would silently narrow the
        // store if the panel gave the tariff back later.
        BotPackage::query()->where('bot_id', $bot->id)
            ->whereNotIn('package_duration_id', $allowed->keys()->all())
            ->delete();

        if ($tooCheap !== []) {
            return back()->with('warning', __('shahbot::admin.plan_price_too_low', [
                'plans' => implode('، ', array_slice($tooCheap, 0, 5)),
            ]));
        }

        return back()->with('success', __('shahbot::admin.saved'));
    }

    /**
     * پیام همگانی به کاربران همین ربات.
     *
     * شناسهٔ ربات از مالکِ لاگین‌کرده گرفته می‌شود، نه از درخواست، تا کسی
     * نتواند با دست‌کاری فرم پیامش را به کاربران رباتِ دیگری بفرستد. خودِ
     * سرویس از قبل بر اساس bot_id مخاطب را جدا می‌کند و ارسال را هم در
     * context همان ربات انجام می‌دهد.
     */
    public function broadcast(Request $request, BotSettings $settings, BroadcastService $broadcasts): RedirectResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, [UserRole::Agent, UserRole::Seller], true), 403);
        abort_unless(app(BotAccess::class)->allows($request->user()), 403);

        $bot = BotInstance::query()->where('owner_user_id', $user->id)->first();

        if ($bot === null || ! $bot->is_active) {
            return back()->with('error', __('shahbot::admin.my_bot_needed_first'));
        }

        $data = $request->validate([
            'text' => ['required', 'string', 'max:3500'],
            'audience' => ['required', 'in:all,customers,no_service'],
        ]);

        $broadcasts->create(e($data['text']), $data['audience'], $user->username, (int) $bot->id);

        return back()->with('success', __('shahbot::admin.broadcast_queued'));
    }

    public function connect(Request $request, BotSettings $settings, WebhookService $webhooks): RedirectResponse
    {
        $bot = BotInstance::query()->where('owner_user_id', $request->user()->id)->first();
        abort_unless($bot !== null && $bot->is_active && app(BotAccess::class)->allows($request->user()), 403);

        if ($bot->token() === '') {
            return back()->with('error', __('shahbot::admin.token_missing'));
        }

        $result = rescue(fn () => $webhooks->connectBot($bot), ['ok' => false, 'message' => 'error'], false);

        return back()->with(
            $result['ok'] ? 'success' : 'error',
            $result['ok'] ? __('shahbot::admin.connected').' '.$result['message'] : __('shahbot::admin.connect_failed', ['error' => $result['message']])
        );
    }
}
