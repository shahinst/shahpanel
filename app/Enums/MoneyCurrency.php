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
