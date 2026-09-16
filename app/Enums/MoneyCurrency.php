<?php

namespace App\Enums;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;

enum MoneyCurrency: string
{
    case IRT = 'IRT';
    case TRY = 'TRY';
    case USD = 'USD';
    case EUR = 'EUR';

    public function label(): string
    {
        return match ($this) {
            self::IRT => __('packages.toman'),
            self::TRY => __('backend.currency_try'),
            self::USD => __('backend.currency_usd'),
            self::EUR => __('backend.currency_eur'),
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::IRT => __('packages.toman'),
            self::TRY => '₺',
            self::USD => '$',
            self::EUR => '€',
        };
    }

    /**
     * Decimal places for display. IRT stays integer-looking; others keep cents.
     */
    public function displayDecimals(): int
    {
        return match ($this) {
            self::IRT => 0,
            default => 2,
        };
    }

    public static function default(): self
    {
        return self::tryFrom((string) config('shahpanel.currency', 'IRT')) ?? self::IRT;
    }

    /**
     * ارزی که وقتی مبلغی ارز مشخصی ندارد، فقط برای *نمایش* استفاده می‌شود.
     *
     * عمداً از default() جداست: آن یکی در User::wallet() و
     * WalletService::getOrCreateWallet() کیف پول را از دیتابیس انتخاب و
     * ایجاد می‌کند، پس اگر با زبان کاربر عوض شود، یک کاربر انگلیسی کیف پول
     * دیگری می‌بیند و شارژ در کیف اشتباه می‌نشیند. این یکی هیچ‌وقت به کوئری
     * نمی‌رسد و فقط نماد و تعداد رقم اعشار را تعیین می‌کند.
     */
    public static function displayDefault(): self
    {
        return self::displayFor(app()->getLocale());
    }

    /**
     * کلید تنظیماتی که مدیر برای هر زبان انتخاب می‌کند.
     */
    public static function displaySettingKey(string $locale): string
    {
        return 'display_currency_'.$locale;
    }

    /**
     * ارز *نمایشی* یک زبان مشخص، به ترتیب: تنظیم مدیر، سپس مقدار config آن
     * زبان، و در نهایت default().
     *
     * هر سه مرحله از tryFrom رد می‌شوند تا یک رکورد خراب یا خالی در جدول
     * settings فقط نادیده گرفته شود و صفحه را نیندازد.
     */
    public static function displayFor(string $locale): self
    {
        // format_money در هر صفحه ده‌ها بار صدا زده می‌شود؛ بدون این حافظه،
        // هر مبلغ یک کوئری settings می‌شد. مثل PortalPaths::all() فقط تا پایان
        // همین ریکوئست زنده است.
        static $cache = [];

        if (isset($cache[$locale])) {
            return $cache[$locale];
        }

        $resolved = self::tryFrom(strtoupper(trim(self::storedDisplayCode($locale))));

        if ($resolved === null) {
            $meta = (array) config('locales.supported.'.$locale, []);
            $resolved = self::tryFrom(strtoupper(trim((string) ($meta['currency'] ?? ''))));
        }

        return $cache[$locale] = $resolved ?? self::default();
    }

    /**
     * انتخاب مدیر از جدول settings، با همان محافظی که PortalPaths دارد:
     * پیش از نصب و تا وقتی جدول settings ساخته نشده نباید چیزی پرت شود، چون
     * صفحات نصب هم مبلغ فرمت می‌کنند.
     */
    private static function storedDisplayCode(string $locale): string
    {
        if (! function_exists('shahpanel_installed') || ! shahpanel_installed()) {
            return '';
        }

        try {
            if (! Schema::hasTable('settings')) {
                return '';
            }

            return (string) Setting::getValue(self::displaySettingKey($locale), '');
        } catch (\Throwable) {
            // بدون دیتابیس، همان مقدار config زبان معتبر است.
            return '';
        }
    }

    /**
     * @return list<self>
     */
    public static function sellable(): array
    {
        return [self::IRT, self::TRY, self::USD, self::EUR];
    }

    public static function normalize(?string $code): self
    {
        $code = strtoupper(trim((string) $code));

        return self::tryFrom($code) ?? self::default();
    }
}
