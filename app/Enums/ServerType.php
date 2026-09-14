<?php

namespace App\Enums;

enum ServerType: string
{
    case Mikrotik = 'mikrotik';
    case Sanaei = 'sanaei';
    case Pasarguard = 'pasarguard';
    case Remnawave = 'remnawave';
    case CiscoAnyconnect = 'cisco_anyconnect';
    /** ocserv با API مدیریتی JSON — جدا از Cisco ASA. */
    case Ocserv = 'ocserv';

    public function label(): string
    {
        return match ($this) {
            self::Mikrotik => 'MikroTik',
            self::Sanaei => 'Sanaei / 3x-ui',
            self::Pasarguard => 'PasarGuard',
            self::Remnawave => 'Remnawave',
            self::CiscoAnyconnect => 'Cisco AnyConnect',
            self::Ocserv => 'OpenConnect / ocserv',
        };
    }

    public function isPanelBacked(): bool
    {
        return in_array($this, [self::Sanaei, self::Pasarguard, self::Remnawave], true);
    }
}
