<?php

namespace Modules\ShahBot\Http\Controllers\Panel;

use App\Enums\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Models\BotOrder;
use Modules\ShahBot\Models\BotUser;
use Modules\ShahBot\Services\WebhookService;
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
            'enabled' => $settings->bool('agent_bots_enabled'),
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
        abort_unless($settings->bool('agent_bots_enabled'), 403);

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
        $bot->settings = array_map(fn ($v) => (string) ($v ?? ''), $data);
        $bot->save();

        return back()->with('success', __('shahbot::admin.saved'));
    }

    public function connect(Request $request, BotSettings $settings, WebhookService $webhooks): RedirectResponse
    {
        $bot = BotInstance::query()->where('owner_user_id', $request->user()->id)->first();
        abort_unless($bot !== null && $bot->is_active && $settings->bool('agent_bots_enabled'), 403);

        if ($bot->token() === '') {
            return back()->with('error', __('shahbot::admin.token_missing'));
        }

        $result = rescue(fn () => $webhooks->connectBot($bot), ['ok' => false, 'message' => 'error'], false);

        return back()->with(
            $result['ok'] ? 'success' : 'error',
            $result['ok'] ? __('shahbot::admin.connected') : __('shahbot::admin.connect_failed', ['error' => $result['message']])
        );
    }
}
