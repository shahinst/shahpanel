<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Server;
use App\Models\ServerInterface;
use RuntimeException;

/**
 * Removing a WireGuard interface or a PPP profile from the router and the panel.
 *
 * There was no way to do either: the only path was deleting it on the router
 * by hand, after which the next sync brought nothing back but left the
 * panel's row, its queues and its firewall rules behind.
 *
 * Removal is refused while anything still uses it -- a panel account, or (for
 * PPP) a secret on the router -- because the router would either refuse or,
 * worse, drop those customers on the spot. The router is cleaned first and the
 * panel row goes last, so a router error leaves the panel describing what is
 * really there.
 */
class MikrotikInterfaceRemovalService
{
    /** Built into RouterOS; the router refuses to remove them. */
    private const BUILTIN_PPP_PROFILES = ['default', 'default-encryption'];

    public function __construct(
        protected MikrotikService $mikrotik,
        protected MikrotikQueueService $queues,
        protected MikrotikWireguardFirewallService $firewall,
    ) {}

    public function removeWireguard(Server $server, ServerInterface $iface): void
    {
        $this->assertUnused($server, $iface);
        $name = $iface->name;

        $this->queues->removeInterfaceSpeedQueues($server, $name);
        $this->firewall->removeClientRules($server, $name);
        $this->mikrotik->removeMatching($server, '/interface/wireguard/peers', 'interface', $name);
        $this->mikrotik->removeMatching($server, '/ip/address', 'interface', $name);
        $this->mikrotik->removeMatching($server, '/interface/wireguard', 'name', $name);

        $iface->delete();
    }

    public function removePpp(Server $server, ServerInterface $iface): void
    {
        $name = $iface->name;

        if (in_array(strtolower($name), self::BUILTIN_PPP_PROFILES, true)) {
            throw new RuntimeException(__('servers.ppp_profile_builtin', ['name' => $name]));
        }

        $this->assertUnused($server, $iface);

        $secrets = count($this->mikrotik->queryRouter($server, '/ppp/secret/print', ['profile' => $name]));

        if ($secrets > 0) {
            throw new RuntimeException(__('servers.interface_in_use_router', ['name' => $name, 'count' => $secrets]));
        }

        $meta = $iface->meta ?? [];
        $this->queues->removeInterfaceSpeedQueues($server, $name);
        $this->mikrotik->removeMatching($server, '/ppp/profile', 'name', $name);

        // Only a pool the panel made for this profile; a pool someone built
        // on the router by hand may serve other profiles too.
        $pool = (string) ($meta['pool_name'] ?? '');

        if ($pool !== '' && ($meta['created_via_panel'] ?? false)) {
            $this->mikrotik->removeMatching($server, '/ip/pool', 'name', $pool);
        }

        $iface->delete();
    }

    protected function assertUnused(Server $server, ServerInterface $iface): void
    {
        $accounts = Account::query()
            ->where('server_id', $server->id)
            ->where('mikrotik_profile_key', $iface->remote_key)
            ->count();

        if ($accounts > 0) {
            throw new RuntimeException(__('servers.interface_in_use', ['name' => $iface->name, 'count' => $accounts]));
        }
    }
}
