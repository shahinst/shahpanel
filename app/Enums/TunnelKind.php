<?php

namespace App\Enums;

enum TunnelKind: string
{
    case L2tpV2 = 'l2tpv2';
    case L2tpV3Ip = 'l2tpv3_ip';
    case L2tpV3Udp = 'l2tpv3_udp';
    case Gre = 'gre';
    case Gre6 = 'gre6';
    case Ipip = 'ipip';
    case Eoip = 'eoip';
    case Vxlan = 'vxlan';
    case Sixto4 = '6to4';

    public function label(): string
    {
        return match ($this) {
            self::L2tpV2 => 'L2TPv2',
            self::L2tpV3Ip => 'L2TPv3 (IP)',
            self::L2tpV3Udp => 'L2TPv3 (UDP)',
            self::Gre => 'GRE',
            self::Gre6 => 'GRE6 (IPv6)',
            self::Ipip => 'IPIP',
            self::Eoip => 'EoIP',
            self::Vxlan => 'VXLAN',
            self::Sixto4 => '6to4',
        };
    }

    /**
     * Encapsulation overhead in bytes on top of the outer IPv4/IPv6 header,
     * WITHOUT IPsec (see ipsecOverhead()).
     */
    public function overheadBytes(): int
    {
        return match ($this) {
            self::Gre => 24,        // 20 outer IP + 4 GRE
            self::Gre6 => 44,       // 40 outer IPv6 + 4 GRE
            self::Ipip => 20,       // 20 outer IP
            self::Eoip => 42,       // 20 IP + 8 GRE(EoIP) + 14 inner ethernet
            self::Vxlan => 50,      // 20 IP + 8 UDP + 8 VXLAN + 14 inner ethernet
            self::L2tpV2 => 40,     // 20 IP + 8 UDP + 8 L2TP + 4 PPP
            self::L2tpV3Ip => 28,   // 20 IP + 8 L2TPv3 session header
            self::L2tpV3Udp => 36,  // 20 IP + 8 UDP + 8 L2TPv3
            self::Sixto4 => 20,     // 20 outer IPv4 (IPv6-in-IPv4, protocol 41)
        };
    }

    /** Extra overhead when the tunnel is wrapped in IPsec transport mode. */
    public static function ipsecOverheadBytes(): int
    {
        return 56; // ESP + padding + auth (conservative for AES-CBC/SHA1)
    }

    public function isL2tp(): bool
    {
        return in_array($this, [self::L2tpV2, self::L2tpV3Ip, self::L2tpV3Udp], true);
    }

    /** Whether the kind rides on UDP (relevant for port hopping). */
    public function usesUdp(): bool
    {
        return in_array($this, [self::L2tpV2, self::L2tpV3Udp, self::Vxlan], true);
    }

    /** Ordered L2TP fallback ladder for DPI evasion switches. */
    public static function l2tpLadder(): array
    {
        return [self::L2tpV3Udp, self::L2tpV3Ip, self::L2tpV2];
    }
}
