<?php

namespace Modules\ShahBot\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Key/value settings of the bot, kept in shahbot_settings. The bot token is
 * stored encrypted; everything else is plain text. Values are cached for the
 * request and briefly in the cache store, since every Telegram update reads
 * several of them.
 */
class BotSettings
{
    public const CACHE_KEY = 'shahbot:settings';

    /** Keys that are stored encrypted. */
    protected const SECRET = ['bot_token'];

    /** @var array<string, string|null>|null */
    protected ?array $values = null;

    public static function defaults(): array
    {
        return [
            // Connection
            'bot_token' => '',
            'bot_username' => '',
            'mode' => 'webhook',
            'webhook_secret' => '',
            'proxy' => '',
            'owner_user_id' => '',
            'admin_chat_ids' => '',

            // Agents
            'agency_enabled' => '0',
            'agency_text' => 'برای همکاری در فروش، توضیح کوتاهی دربارهٔ خودتان و میزان فروش‌تان بنویسید.',
            'agency_discount' => '10',
            'agent_bots_enabled' => '0',
            'bulk_max' => '20',

            // Store
            'sales_enabled' => '1',
            'closed_text' => 'فروش در حال حاضر متوقف است. کمی بعد دوباره سر بزنید.',
            'test_enabled' => '0',
            'test_duration_id' => '',
            'renew_enabled' => '1',
            'show_portal_link' => '1',

            // Wallet / card-to-card
            'topup_enabled' => '1',
            'topup_min' => '50000',
            'topup_max' => '20000000',
            'card_number' => '',
            'card_holder' => '',
            'card_bank' => '',
            'card_note' => '',

            // Online payments
            'pay_zarinpal' => '1',
            'pay_crypto' => '1',
            'pay_stars' => '0',
            'stars_rate' => '',

            // Referral
            'referral_enabled' => '0',
            'referral_percent' => '10',
            'referral_first_only' => '1',

            // Gates
            'channels' => '',
            'rules_text' => '',
            'require_phone' => '0',
            'iran_phone_only' => '1',

            // Reminders
            'reminder_enabled' => '1',
            'reminder_days' => '2',
            'low_traffic_percent' => '10',

            // Service operations
            'transfer_enabled' => '1',
            'location_enabled' => '1',
            'location_fee' => '0',
            'refund_enabled' => '1',

            // Lucky wheel
            'wheel_enabled' => '0',
            'wheel_cooldown_hours' => '24',
            'wheel_buyers_only' => '1',
            'wheel_prizes' => "پوچ|none|0|50\n۱۰٪ تخفیف|discount|10|25\n۲۰٬۰۰۰ تومان شارژ|wallet|20000|15\n۵۰٬۰۰۰ تومان شارژ|wallet|50000|8\n۲۰٪ تخفیف|discount|20|2",

            // Languages
            'languages' => "fa\nen\nru\nzh",
            'default_language' => 'fa',

            // Editors and mini app
            'texts' => '',
            'menu_layout' => '',
            'mini_app_enabled' => '1',

            // Texts
            'welcome_text' => "سلام {name} 👋\nبه ربات فروش {brand} خوش آمدید.\nاز منوی زیر یکی از گزینه‌ها را انتخاب کنید.",
            'support_text' => 'پیام خود را بنویسید و بفرستید؛ همکاران پشتیبانی در اولین فرصت پاسخ می‌دهند.',
            'about_text' => '',
        ];
    }

    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        $stored = [];

        try {
            $stored = Cache::remember(self::CACHE_KEY, 60, fn (): array => DB::table('shahbot_settings')->pluck('value', 'key')->all());
        } catch (Throwable) {
            $stored = [];
        }

        foreach (self::SECRET as $key) {
            if (! empty($stored[$key])) {
                try {
                    $stored[$key] = Crypt::decryptString($stored[$key]);
                } catch (Throwable) {
                    $stored[$key] = '';
                }
            }
        }

        return $this->values = array_merge(self::defaults(), array_filter($stored, fn ($v) => $v !== null));
    }

    public function get(string $key, ?string $default = null): string
    {
        $bot = app(BotContext::class)->bot();

        if ($bot !== null) {
            $overrides = $bot->overrides();

            if (array_key_exists($key, $overrides)) {
                return $overrides[$key];
            }
        }

        return (string) ($this->all()[$key] ?? $default ?? '');
    }

    /**
     * The main bot's own value, ignoring the bot in context.
     */
    public function main(string $key): string
    {
        return (string) ($this->all()[$key] ?? '');
    }

    public function bool(string $key): bool
    {
        return in_array($this->get($key), ['1', 'true', 'on', 'yes'], true);
    }

    public function int(string $key): int
    {
        return (int) western_digits($this->get($key));
    }

    /**
     * @return list<string>
     */
    public function lines(string $key): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $this->get($key)) ?: [])));
    }

    /**
     * @return list<int>
     */
    public function adminChatIds(): array
    {
        return array_values(array_unique(array_map('intval', array_filter(
            $this->lines('admin_chat_ids'),
            fn (string $id): bool => preg_match('/^-?\d+$/', $id) === 1
        ))));
    }

    public function isAdminChat(int|string $chatId): bool
    {
        return in_array((int) $chatId, $this->adminChatIds(), true);
    }

    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::defaults())) {
                continue;
            }

            $value = $value === null ? '' : (string) $value;

            if (in_array($key, self::SECRET, true) && $value !== '') {
                $value = Crypt::encryptString($value);
            }

            DB::table('shahbot_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        $this->flush();
    }

    public function webhookSecret(): string
    {
        $secret = $this->get('webhook_secret');

        if ($secret === '' && app(BotContext::class)->bot() === null) {
            $secret = Str::random(40);
            $this->set(['webhook_secret' => $secret]);
        }

        return $secret;
    }

    /**
     * A text the admin sets in Persian (welcome, support, closed, agency):
     * Persian users get the admin's wording, everyone else the translated
     * default of that text.
     */
    public function localized(string $key): string
    {
        $value = $this->get($key);

        if (app()->getLocale() === 'fa' && $value !== '') {
            return $value;
        }

        return (string) __('shahbot::bot.cfg_'.$key);
    }

    public function isConfigured(): bool
    {
        return $this->get('bot_token') !== '' && $this->int('owner_user_id') > 0;
    }

    public function flush(): void
    {
        $this->values = null;
        Cache::forget(self::CACHE_KEY);
    }
}
