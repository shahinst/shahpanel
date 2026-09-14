<?php

namespace App\Enums;

enum ServiceType: string
{
    case Ppp = 'ppp';
    case Wireguard = 'wireguard';
    case Openvpn = 'openvpn';
    case L2tp = 'l2tp';
    case SanaeiVmess = 'sanaei_vmess';
    case SanaeiVless = 'sanaei_vless';
    case SanaeiTrojan = 'sanaei_trojan';
    /** پکیج/اکانت متصل به پنل PasarGuard (نمایندگی یا ادمین). */
    case Pasarguard = 'pasarguard';
    /** پکیج/اکانت متصل به پنل Remnawave. */
    case Remnawave = 'remnawave';
    /** پکیج/اکانت اختصاصی Cisco AnyConnect روی ASA */
    case CiscoAnyconnect = 'cisco_anyconnect';
    /** پکیج/اکانت OpenConnect روی ocserv (همان اپ AnyConnect برای مشتری) */
    case Ocserv = 'ocserv';

    public function isMikrotik(): bool
    {
        return in_array($this, [
            self::Ppp,
            self::Wireguard,
            self::Openvpn,
            self::L2tp,
        ], true);
    }

    public function isSanaei(): bool
    {
        return in_array($this, [
            self::SanaeiVmess,
            self::SanaeiVless,
            self::SanaeiTrojan,
        ], true);
    }

    public function isPasarguard(): bool
    {
        return $this === self::Pasarguard;
    }

    public function isRemnawave(): bool
    {
        return $this === self::Remnawave;
    }

    public function isCiscoAnyconnect(): bool
    {
        return $this === self::CiscoAnyconnect;
    }

    public function isOcserv(): bool
    {
        return $this === self::Ocserv;
    }

    /** Cisco ASA یا ocserv — دسته AnyConnect در پنل. */
    public function isAnyconnectFamily(): bool
    {
        return $this->isCiscoAnyconnect() || $this->isOcserv();
    }

    /** V2ray-style panel account (Sanaei, PasarGuard or Remnawave). */
    public function isPanelV2ray(): bool
    {
        return $this->isSanaei() || $this->isPasarguard() || $this->isRemnawave();
    }

    public function accountCategory(): AccountCategory
    {
        return match ($this) {
            self::Wireguard => AccountCategory::Wireguard,
            self::SanaeiVmess, self::SanaeiVless, self::SanaeiTrojan, self::Pasarguard, self::Remnawave => AccountCategory::V2ray,
            self::CiscoAnyconnect, self::Ocserv => AccountCategory::Anyconnect,
            default => AccountCategory::Ppp,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Wireguard => 'WireGuard',
            self::Ppp => 'PPP',
            self::Openvpn => 'OpenVPN',
            self::L2tp => 'L2TP',
            self::SanaeiVmess => 'VMess',
            self::SanaeiVless => 'VLESS',
            self::SanaeiTrojan => 'Trojan',
            self::Pasarguard => __('packages.service_type_pasarguard'),
            self::Remnawave => __('packages.service_type_remnawave'),
            self::CiscoAnyconnect => __('packages.service_type_cisco_anyconnect'),
            self::Ocserv => __('packages.service_type_ocserv'),
        };
    }

    public function usernamePrefix(): string
    {
        return match ($this) {
            self::Wireguard => 'wg-',
            self::Ppp => 'ppp-',
            self::Openvpn => 'ovpn-',
            self::L2tp => 'l2tp-',
            self::SanaeiVmess => 'vm-',
            self::SanaeiVless => 'vl-',
            self::SanaeiTrojan => 'tr-',
            self::Pasarguard => 'pg-',
            self::Remnawave => 'rw-',
            self::CiscoAnyconnect => 'ac-',
            self::Ocserv => 'VPL',
        };
    }
}
