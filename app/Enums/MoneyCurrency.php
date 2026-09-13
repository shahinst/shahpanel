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
            self::IRT => 'تومان',
            self::TRY => 'لیر ترکیه',
            self::USD => 'دلار',
            self::EUR => 'یورو',
        };
    }

    public function symbol(): string
    {
        return match ($this) {
            self::IRT => 'تومان',
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
        return self::tryFrom((string) config('vpnpanel.currency', 'IRT')) ?? self::IRT;
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
