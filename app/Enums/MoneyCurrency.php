<?php

namespace App\Enums;

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
        $meta = (array) config('locales.supported.'.app()->getLocale(), []);
        $code = (string) ($meta['currency'] ?? '');

        return self::tryFrom(strtoupper($code)) ?? self::default();
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
