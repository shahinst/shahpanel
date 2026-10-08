<?php

namespace Modules\ShahBot\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\ShahBot\Models\BotCode;
use Modules\ShahBot\Services\BroadcastService;
use Modules\ShahBot\Services\CodeService;

class CodeController extends Controller
{
    public function index(Request $request): View
    {
        $kind = $request->query('kind') === BotCode::GIFT ? BotCode::GIFT : BotCode::DISCOUNT;

        return view('shahbot::codes', [
            'kind' => $kind,
            'codes' => BotCode::query()->where('kind', $kind)->latest('id')->paginate(30)->withQueryString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['code' => CodeService::normalize((string) ($request->input('code') ?: Str::upper(Str::random(8))))]);

        $data = $request->validate([
            'kind' => ['required', Rule::in([BotCode::DISCOUNT, BotCode::GIFT])],
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('shahbot_codes', 'code')],
            'value_type' => ['nullable', Rule::in(['fixed', 'percent'])],
            'value' => ['required', 'numeric', 'min:0.01'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'string'],
            'first_purchase_only' => ['nullable', 'boolean'],
            // A flash sale is a discount code with a deadline and a stock, announced to the bot's users.
            'flash' => ['nullable', 'boolean'],
            'flash_audience' => ['nullable', Rule::in(['all', 'customers', 'no_service'])],
        ]);

        if (($data['flash'] ?? false) && ($data['kind'] !== BotCode::DISCOUNT || empty($data['max_uses']) || blank($data['expires_at'] ?? null))) {
            return back()->withErrors(['flash' => __('shahbot::admin.flash_needs_limits')])->withInput();
        }

        if ($data['kind'] === BotCode::GIFT) {
            $data['value_type'] = 'fixed';
        }

        if (($data['value_type'] ?? 'fixed') === 'percent' && (float) $data['value'] > 100) {
            return back()->withErrors(['value' => __('shahbot::admin.percent_max')])->withInput();
        }

        $code = BotCode::query()->create([
            'kind' => $data['kind'],
            'code' => $data['code'],
            'value_type' => $data['value_type'] ?? 'fixed',
            'value' => $data['value'],
            'min_amount' => $data['min_amount'] ?? null,
            'max_uses' => $data['max_uses'] ?? null,
            'first_purchase_only' => (bool) ($data['first_purchase_only'] ?? false),
            'expires_at' => filled($data['expires_at'] ?? null) ? parse_jalali_date($data['expires_at'], true) : null,
            'is_active' => true,
        ]);

        if ($data['flash'] ?? false) {
            if ($code->expires_at === null || $code->expires_at->isPast()) {
                $code->delete();

                return back()->withErrors(['expires_at' => __('shahbot::admin.flash_needs_limits')])->withInput();
            }

            app(BroadcastService::class)->create(__('shahbot::admin.flash_text', [
                'value' => $code->value_type === 'percent' ? persian_digits((int) $code->value).'٪' : format_money($code->value),
                'code' => $code->code,
                'count' => persian_digits((int) $code->max_uses),
                'date' => jalali_date($code->expires_at, 'Y/m/d H:i'),
            ]), $data['flash_audience'] ?? 'all', (string) $request->user()?->username);
        }

        return redirect()->route('admin.shahbot.codes.index', ['kind' => $data['kind']])->with('success', __('shahbot::admin.saved'));
    }

    public function toggle(BotCode $code): RedirectResponse
    {
        $code->update(['is_active' => ! $code->is_active]);

        return back()->with('success', __('shahbot::admin.saved'));
    }

    public function destroy(BotCode $code): RedirectResponse
    {
        $code->delete();

        return back()->with('success', __('shahbot::admin.deleted'));
    }
}
