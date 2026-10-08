<?php

namespace Modules\Webhooks\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Modules\Webhooks\Jobs\DeliverEventWebhook;
use Modules\Webhooks\Services\EventWebhookService;

class WebhooksController extends Controller
{
    public function index(): View
    {
        return view('webhooks::index', [
            'url' => EventWebhookService::url(),
            'secret' => EventWebhookService::secret(),
            'enabled' => EventWebhookService::enabledEvents(),
            'log' => Cache::get(EventWebhookService::LOG_KEY, []),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // Only https: the body names customers and the receiver is outside the panel.
            'url' => ['nullable', 'url:https', 'max:500'],
            'events' => ['array'],
            'events.*' => ['string', 'in:'.implode(',', EventWebhookService::EVENTS)],
        ]);

        EventWebhookService::save((string) ($validated['url'] ?? ''), $validated['events'] ?? [], $request->boolean('new_secret'));

        return back()->with('success', __('app.saved'));
    }

    public function test(): RedirectResponse
    {
        if (EventWebhookService::url() === '') {
            return back()->withErrors(['url' => __('webhooks::webhooks.no_url')]);
        }

        DeliverEventWebhook::dispatchSync([
            'id' => (string) Str::uuid(),
            'event' => 'ping',
            'occurred_at' => now()->toIso8601String(),
        ]);

        $last = Cache::get(EventWebhookService::LOG_KEY, [])[0] ?? null;

        return ($last['ok'] ?? false)
            ? back()->with('success', __('webhooks::webhooks.test_ok', ['status' => $last['status']]))
            : back()->withErrors(['url' => __('webhooks::webhooks.test_failed', ['status' => $last['status'] ?? 0])]);
    }
}
