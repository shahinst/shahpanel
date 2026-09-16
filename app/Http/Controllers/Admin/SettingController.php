<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MoneyCurrency;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingController extends Controller
{
    /**
     * @var array<string, array{section: string, type: string}>
     */
    protected array $fields = [
        'site_name' => ['section' => 'general', 'type' => 'text'],
        'site_url' => ['section' => 'general', 'type' => 'url'],
        'timezone' => ['section' => 'general', 'type' => 'text'],
        'currency' => ['section' => 'general', 'type' => 'text'],
        'currency_label' => ['section' => 'general', 'type' => 'text'],
        'accent_color' => ['section' => 'general', 'type' => 'color'],
        'support_phone' => ['section' => 'payment', 'type' => 'text'],
        'support_telegram' => ['section' => 'payment', 'type' => 'text'],
        'default_payment_card' => ['section' => 'payment', 'type' => 'text'],
        'min_charge_amount' => ['section' => 'payment', 'type' => 'number'],
        'global_discount_enabled' => ['section' => 'payment', 'type' => 'boolean'],
        'global_discount_percent' => ['section' => 'payment', 'type' => 'number'],
        'global_discount_ends_at' => ['section' => 'payment', 'type' => 'datetime'],
            'sync_interval_minutes' => ['section' => 'system', 'type' => 'number'],
            'default_agent_daily_server_changes' => ['section' => 'system', 'type' => 'number'],
            'portal_enabled' => ['section' => 'system', 'type' => 'boolean'],
        'registration_enabled' => ['section' => 'system', 'type' => 'boolean'],
        'ticket_auto_close_days' => ['section' => 'system', 'type' => 'number'],
    ];

    /**
     * زبان‌هایی که پنل دارد؛ برای هرکدام یک ارز نمایشی جدا ذخیره می‌شود.
     *
     * @return list<string>
     */
    protected function supportedLocales(): array
    {
        return array_map('strval', array_keys((array) config('locales.supported', [])));
    }

    public function index(): View
    {
        $settings = collect(array_keys($this->fields))
            ->mapWithKeys(fn (string $key): array => [$key => Setting::getValue($key, '')])
            ->all();

        $sections = [
            'general' => __('settings.section_general'),
            'payment' => __('settings.section_payment'),
            'system' => __('settings.section_system'),
        ];

        // مقدار «مؤثر» را از خود enum می‌گیریم تا ترتیب تنظیم/کانفیگ/پیش‌فرض
        // فقط یک جا نوشته شده باشد.
        $displayCurrencies = [];

        foreach ($this->supportedLocales() as $locale) {
            $displayCurrencies[$locale] = MoneyCurrency::displayFor($locale)->value;
        }

        return view('admin.settings.index', [
            'settings' => $settings,
            'fields' => $this->fields,
            'sections' => $sections,
            'displayCurrencies' => $displayCurrencies,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [
            'site_name' => ['nullable', 'string', 'max:255'],
            'site_url' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'currency' => ['nullable', 'string', 'max:10'],
            'currency_label' => ['nullable', 'string', 'max:32'],
            'accent_color' => ['nullable', 'string', 'max:20'],
            'support_phone' => ['nullable', 'string', 'max:30'],
            'support_telegram' => ['nullable', 'string', 'max:100'],
            'default_payment_card' => ['nullable', 'string', 'max:30'],
            'min_charge_amount' => ['nullable', 'numeric', 'min:0'],
            'global_discount_enabled' => ['nullable', 'boolean'],
            'global_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'global_discount_ends_at' => ['nullable', 'date'],
            'sync_interval_minutes' => ['nullable', 'integer', 'min:1', 'max:60'],
            'default_agent_daily_server_changes' => ['nullable', 'integer', 'min:0', 'max:100'],
            'portal_enabled' => ['nullable', 'boolean'],
            'registration_enabled' => ['nullable', 'boolean'],
            'ticket_auto_close_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];

        foreach ($this->supportedLocales() as $locale) {
            $rules[MoneyCurrency::displaySettingKey($locale)] = [
                'nullable',
                Rule::in(array_column(MoneyCurrency::cases(), 'value')),
            ];
        }

        $validated = $request->validate($rules);

        foreach (array_keys($this->fields) as $key) {
            $meta = $this->fields[$key];

            if ($meta['type'] === 'boolean') {
                Setting::setValue($key, $request->boolean($key) ? '1' : '0');

                continue;
            }

            $value = $validated[$key] ?? null;
            Setting::setValue($key, $value !== null && $value !== '' ? (string) $value : null);
        }

        // فقط روی نمایش اثر دارد؛ MoneyCurrency::default() که کیف پول را از
        // دیتابیس انتخاب می‌کند از اینجا دست نمی‌خورد.
        foreach ($this->supportedLocales() as $locale) {
            $key = MoneyCurrency::displaySettingKey($locale);
            $value = $validated[$key] ?? null;
            Setting::setValue($key, $value !== null && $value !== '' ? (string) $value : null);
        }

        return redirect()
            ->route('admin.settings.index')
            ->with('success', __('app.saved'));
    }
}
