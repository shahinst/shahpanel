<?php

namespace App\Enums;

enum AccountCategory: string
{
    case Wireguard = 'wireguard';
    case Ppp = 'ppp';
    case V2ray = 'v2ray';
    case Anyconnect = 'anyconnect';

    public function label(): string
    {
        return match ($this) {
            self::Wireguard => __('menu.accounts_wireguard'),
            self::Ppp => __('menu.accounts_ppp'),
            self::V2ray => __('menu.accounts_v2ray'),
            self::Anyconnect => __('menu.accounts_anyconnect'),
        };
    }

    /**
     * @return list<ServiceType>
     */
    public function serviceTypes(): array
    {
        return array_values(array_filter(
            ServiceType::cases(),
            fn (ServiceType $type) => $type->accountCategory() === $this
        ));
    }

    /**
     * @return list<string>
     */
    public function serviceTypeValues(): array
    {
        return array_map(fn (ServiceType $type) => $type->value, $this->serviceTypes());
    }

    /**
     * Panel/server types that host accounts in this category.
     *
     * @return list<ServerType>
     */
    public function serverTypes(): array
    {
        return match ($this) {
            self::Wireguard, self::Ppp => [ServerType::Mikrotik],
            self::V2ray => [ServerType::Sanaei, ServerType::Pasarguard, ServerType::Remnawave],
            self::Anyconnect => [ServerType::CiscoAnyconnect],
        };
    }

    /**
     * @return list<string>
     */
    public function serverTypeValues(): array
    {
        return array_map(fn (ServerType $type) => $type->value, $this->serverTypes());
    }

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? self::Wireguard;
    }
}
