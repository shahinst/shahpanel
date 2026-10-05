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
    // Protocols 3x-ui serves per client besides the three above. Each one is
    // a client of an inbound, sold and renewed exactly like VLESS; only the
    // config link differs, and that link is taken from the panel itself.
    case SanaeiShadowsocks = 'sanaei_shadowsocks';
    case SanaeiHysteria = 'sanaei_hysteria';
    case SanaeiTuic = 'sanaei_tuic';
    case SanaeiWireguard = 'sanaei_wireguard';
    case SanaeiAmneziawg = 'sanaei_amneziawg';
    case SanaeiMtproto = 'sanaei_mtproto';
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
            self::SanaeiShadowsocks,
            self::SanaeiHysteria,
            self::SanaeiTuic,
            self::SanaeiWireguard,
            self::SanaeiAmneziawg,
            self::SanaeiMtproto,
        ], true);
    }

    /**
     * The 3x-ui inbound protocol behind a Sanaei service type, null for
     * anything else.
     */
    public function sanaeiProtocol(): ?string
    {
        return match ($this) {
            self::SanaeiVmess => 'vmess',
            self::SanaeiVless => 'vless',
            self::SanaeiTrojan => 'trojan',
            self::SanaeiShadowsocks => 'shadowsocks',
            self::SanaeiHysteria => 'hysteria',
            self::SanaeiTuic => 'tuic',
            self::SanaeiWireguard => 'wireguard',
            self::SanaeiAmneziawg => 'amneziawg',
            self::SanaeiMtproto => 'mtproto',
            default => null,
        };
    }

    /** Sanaei service type for a 3x-ui inbound protocol, null when 3x-ui has no per-client form of it. */
    public static function fromSanaeiProtocol(string $protocol): ?self
    {
        $protocol = strtolower(trim($protocol));

        // 3x-ui names Hysteria 2 inbounds "hysteria" as well; older builds wrote "hysteria2".
        if ($protocol === 'hysteria2') {
            $protocol = 'hysteria';
        }

        foreach (self::cases() as $case) {
            if ($case->sanaeiProtocol() === $protocol) {
                return $case;
            }
        }

        return null;
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
        if ($this->isSanaei()) {
            return AccountCategory::V2ray;
        }

        return match ($this) {
            self::Wireguard => AccountCategory::Wireguard,
            self::Pasarguard, self::Remnawave => AccountCategory::V2ray,
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
            self::SanaeiShadowsocks => 'Shadowsocks',
            self::SanaeiHysteria => 'Hysteria2',
            self::SanaeiTuic => 'TUIC',
            self::SanaeiWireguard => 'WireGuard (3x-ui)',
            self::SanaeiAmneziawg => 'AmneziaWG',
            self::SanaeiMtproto => 'MTProto',
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
            self::SanaeiShadowsocks => 'ss-',
            self::SanaeiHysteria => 'hy-',
            self::SanaeiTuic => 'tu-',
            self::SanaeiWireguard => 'xw-',
            self::SanaeiAmneziawg => 'aw-',
            self::SanaeiMtproto => 'mt-',
            self::Pasarguard => 'pg-',
            self::Remnawave => 'rw-',
            self::CiscoAnyconnect => 'ac-',
            self::Ocserv => 'VPL',
        };
    }
}
