<?php

namespace Modules\ShahBot\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Models\PackageDuration;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\ShahBot\Services\WebhookService;
use Modules\ShahBot\Support\BotSettings;

class SettingsController extends Controller
{
    protected const BOOLEANS = [
        'sales_enabled', 'test_enabled', 'renew_enabled', 'show_portal_link', 'topup_enabled',
        'referral_enabled', 'referral_first_only', 'require_phone', 'iran_phone_only', 'reminder_enabled',
    ];

    public function edit(BotSettings $settings, WebhookService $webhooks): View
    {
        return view('shahbot::settings', [
            'settings' => $settings,
            'owners' => User::query()->whereIn('role', [UserRole::Agent, UserRole::Seller])->orderBy('username')->get(['id', 'username', 'full_name', 'role']),
            'testDurations' => PackageDuration::query()
                ->with('package')
                ->where('is_enabled', true)
                ->whereIn('tier', ['1h', '6h', '12h', '1d'])
                ->get()
                ->filter(fn (PackageDuration $d) => $d->package !== null),
            'webhookUrl' => $webhooks->webhookUrl(),
            // Asks Telegram, so only on the tab that shows it.
            'webhookInfo' => request('tab', 'connection') === 'connection' ? rescue(fn () => $webhooks->info(), [], false) : [],
            'tab' => request('tab', 'connection'),
        ]);
    }

    public function update(Request $request, BotSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'bot_token' => ['nullable', 'string', 'regex:/^\d{5,}:[A-Za-z0-9_-]{30,}$/'],
            'mode' => ['nullable', 'in:webhook,polling'],
            'proxy' => ['nullable', 'string', 'max:255', 'regex:/^(https?|socks5h?):\/\//'],
            'owner_user_id' => ['nullable', 'integer'],
            'admin_chat_ids' => ['nullable', 'string', 'max:1000'],
            'closed_text' => ['nullable', 'string', 'max:1000'],
            'test_duration_id' => ['nullable', 'integer'],
            'topup_min' => ['nullable', 'numeric', 'min:0'],
            'topup_max' => ['nullable', 'numeric', 'min:0'],
            'card_number' => ['nullable', 'string', 'max:40'],
            'card_holder' => ['nullable', 'string', 'max:100'],
            'card_bank' => ['nullable', 'string', 'max:100'],
            'card_note' => ['nullable', 'string', 'max:500'],
            'referral_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'channels' => ['nullable', 'string', 'max:1000'],
            'rules_text' => ['nullable', 'string', 'max:3500'],
            'reminder_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'low_traffic_percent' => ['nullable', 'integer', 'min:0', 'max:90'],
            'welcome_text' => ['nullable', 'string', 'max:3500'],
            'support_text' => ['nullable', 'string', 'max:1000'],
            'tab' => ['nullable', 'string'],
        ]);

        if (! empty($data['owner_user_id'])) {
            $owner = User::query()->find($data['owner_user_id']);

            if ($owner === null || ! in_array($owner->role, [UserRole::Agent, UserRole::Seller], true)) {
                return back()->withErrors(['owner_user_id' => __('shahbot::admin.owner_invalid')])->withInput();
            }
        }

        $tab = $data['tab'] ?? 'connection';
        unset($data['tab']);

        // An empty token field keeps the stored one: it is never echoed back.
        if (blank($data['bot_token'] ?? null)) {
            unset($data['bot_token']);
        }

        // Only the fields of the submitted tab are present in the request.
        $values = array_filter($data, fn ($key) => $request->has($key), ARRAY_FILTER_USE_KEY);

        foreach (self::BOOLEANS as $key) {
            if ($request->has('_bool_'.$key)) {
                $values[$key] = $request->boolean($key) ? '1' : '0';
            }
        }

        $settings->set($values);

        return redirect()->route('admin.shahbot.settings', ['tab' => $tab])->with('success', __('shahbot::admin.saved'));
    }

    public function connect(WebhookService $webhooks): RedirectResponse
    {
        $result = rescue(fn () => $webhooks->connect(), ['ok' => false, 'message' => __('shahbot::admin.token_missing')], false);

        return back()->with(
            $result['ok'] ? 'success' : 'error',
            $result['ok'] ? __('shahbot::admin.connected') : __('shahbot::admin.connect_failed', ['error' => $result['message']])
        );
    }
}
