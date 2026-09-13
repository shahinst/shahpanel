<?php

namespace App\Services\Tunneling\Builders;

use App\Models\Server;
use App\Models\TunnelAgent;
use App\Models\TunnelGroup;

/**
 * One implementation per tunnel kind. build() returns desired-object SPECS —
 * plain arrays the orchestrator turns into DesiredNetworkObject rows:
 *
 *   side        iran | foreign
 *   object_type interface | ip_address | ipsec | singleton | …
 *   menu        RouterOS menu path
 *   key         marker suffix (full marker = vpnl:tg{group}:{key})
 *   payload     RouterOS attributes (comment is added automatically)
 */
interface TunnelBuilder
{
    /**
     * @return list<array{side: string, object_type: string, menu: string, key: string, payload: array<string, mixed>}>
     */
    public function build(TunnelGroup $group, TunnelAgent $agent, Server $iran, Server $foreign): array;
}
