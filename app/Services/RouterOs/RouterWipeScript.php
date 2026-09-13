<?php

namespace App\Services\RouterOs;

/**
 * One-shot RouterOS script for bulk removal on the router itself.
 * One API round-trip (add + run + cleanup) instead of hundreds of /print + /remove.
 */
class RouterWipeScript
{
    /** @var list<string> */
    private const COMMENT_MENUS = [
        '/ip/route',
        '/routing/rule',
        '/ip/firewall/mangle',
        '/ip/firewall/nat',
        '/ip/firewall/filter',
        '/ip/firewall/raw',
        '/ip/firewall/address-list',
        '/ip/address',
        '/ipv6/address',
        '/interface/gre',
        '/interface/gre6',
        '/interface/ipip',
        '/interface/ipipv6',
        '/interface/eoip',
        '/interface/vxlan',
        '/interface/l2tp-client',
        '/interface/l2tp-server',
        '/interface/l2tp-ether',
        '/interface/wireguard',
        '/interface/sstp-client',
        '/interface/sstp-server',
        '/interface/6to4',
        '/interface/list/member',
        '/ip/ipsec/policy',
        '/ip/ipsec/identity',
        '/ip/ipsec/peer',
        '/ppp/secret',
        '/ppp/profile',
        '/ip/pool',
        '/system/scheduler',
        '/system/script',
        '/routing/table',
    ];

    /** @var list<string> */
    private const FIREWALL_MENUS = [
        '/ip/firewall/mangle',
        '/ip/firewall/nat',
        '/ip/firewall/filter',
        '/ip/firewall/raw',
    ];

    /** @var list<string> */
    private const FIREWALL_FIELDS = [
        'comment',
        'in-interface',
        'out-interface',
        'src-address-list',
        'dst-address-list',
        'chain',
    ];

    /** @var list<string> */
    private const INTERFACE_TYPES = [
        'gre',
        'gre6',
        'ipip',
        'ipipv6',
        'eoip',
        'vxlan',
        'l2tp-client',
        'l2tp-server',
        'l2tp-ether',
        'wireguard',
        'sstp-client',
        '6to4',
    ];

    /**
     * @param  list<string>  $commentPatterns  Regex fragments for comment~"…"
     * @param  list<string>  $exactInterfaceNames
     */
    public static function forTunnelGroup(
        int $groupId,
        array $commentPatterns = [],
        array $exactInterfaceNames = [],
    ): string {
        $patterns = array_values(array_unique(array_filter([
            ...$commentPatterns,
            'vpnl:tg'.$groupId,
            'vpnl-tg'.$groupId,
        ])));

        $lines = [];

        foreach ($patterns as $pattern) {
            $escaped = self::escapeScriptString($pattern);

            foreach (self::COMMENT_MENUS as $menu) {
                $lines[] = ':do {'.$menu.' remove [find comment~"'.$escaped.'"]} on-error={}';
            }

            foreach (self::FIREWALL_MENUS as $menu) {
                foreach (self::FIREWALL_FIELDS as $field) {
                    $lines[] = ':do {'.$menu.' remove [find where ('.$field.'~"'.$escaped.'")]} on-error={}';
                }
            }

            foreach (self::INTERFACE_TYPES as $type) {
                $lines[] = ':do {/interface/'.$type.' remove [find where (name~"'.$escaped.'")]} on-error={}';
            }

            $lines[] = ':do {/ip/address remove [find where (interface~"'.$escaped.'")]} on-error={}';
            $lines[] = ':do {/ipv6/address remove [find where (interface~"'.$escaped.'")]} on-error={}';
        }

        foreach ($exactInterfaceNames as $name) {
            $escapedName = self::escapeScriptString($name);

            foreach (self::INTERFACE_TYPES as $type) {
                $lines[] = ':do {/interface/'.$type.' remove [find name="'.$escapedName.'"]} on-error={}';
            }

            $lines[] = ':do {/ip/address remove [find interface="'.$escapedName.'"]} on-error={}';
            $lines[] = ':do {/interface/list/member remove [find interface="'.$escapedName.'"]} on-error={}';
        }

        $lines[] = ':do {/ip/firewall/address-list remove [find list="TUNNELS"]} on-error={}';
        $lines[] = ':do {/ip/firewall/address-list remove [find list="NO_TUNNEL"]} on-error={}';
        $lines[] = ':do {/system/script remove [find name="vpnl-metrics"]} on-error={}';
        $lines[] = ':do {/system/scheduler remove [find name="vpnl-metrics"]} on-error={}';

        return implode("\n", $lines);
    }

    public static function source(string $commentPattern, bool $removeNameOrphans = true): string
    {
        $escaped = self::escapeScriptString($commentPattern);
        $lines = [];

        foreach (self::COMMENT_MENUS as $menu) {
            $lines[] = ':do {'.$menu.' remove [find comment~"'.$escaped.'"]} on-error={}';
        }

        if ($removeNameOrphans) {
            foreach (self::INTERFACE_TYPES as $type) {
                $lines[] = ':do {/interface/'.$type.' remove [find where name~"^vpnl-"]} on-error={}';
            }
        }

        return implode("\n", $lines);
    }

    private static function escapeScriptString(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
