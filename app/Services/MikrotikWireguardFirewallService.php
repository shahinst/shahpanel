<?php

namespace App\Services;

use App\Exceptions\RemoteProvisionException;
use App\Models\Server;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Panel-managed NAT + mangle rules for WireGuard client subnets.
 *
 * Idempotent: only adds rules tagged with vpnpanel:wg:* comments.
 * Does not modify routing tables or any router objects without our comment.
 */
class MikrotikWireguardFirewallService
{
    public function __construct(
        protected MikrotikService $mikrotik,
    ) {}

    /**
     * @return array{created: list<string>, skipped: list<string>, warnings: list<string>}
     */
    public function ensureClientRules(Server $server, string $interfaceName, string $subnetCidr): array
    {
        if (! config('vpnpanel.wireguard.auto_firewall_rules', true)) {
            return ['created' => [], 'skipped' => ['disabled'], 'warnings' => []];
        }

        $interfaceName = trim($interfaceName);
        $subnetCidr = trim($subnetCidr);

        if ($interfaceName === '' || $subnetCidr === '') {
            throw new InvalidArgumentException(__('servers.wireguard_firewall_missing_subnet'));
        }

        $created = [];
        $skipped = [];
        $warnings = [];

        $natComment = $this->natComment($interfaceName);

        if ($this->mikrotik->itemExists($server, '/ip/firewall/nat/print', ['comment' => $natComment])) {
            $skipped[] = 'nat';
        } else {
            try {
                $this->mikrotik->sendCommand($server, '/ip/firewall/nat/add', [
                    'chain' => 'srcnat',
                    'src-address' => $subnetCidr,
                    'action' => 'masquerade',
                    'comment' => $natComment,
                ]);
                $created[] = 'nat';
            } catch (Throwable $exception) {
                throw new RemoteProvisionException(
                    __('servers.wireguard_firewall_nat_failed', [
                        'interface' => $interfaceName,
                        'error' => $exception->getMessage(),
                    ]),
                    0,
                    $exception
                );
            }
        }

        $ranges = $this->clientMangleRangesFromSubnet($subnetCidr);
        if ($ranges === null) {
            $warnings[] = __('servers.wireguard_firewall_mangle_skipped_prefix', ['subnet' => $subnetCidr]);

            return compact('created', 'skipped', 'warnings');
        }

        $marks = config('vpnpanel.wireguard.mangle_routing_marks', []);
        $lowMark = (string) ($marks['low'] ?? 'FILTER-2>128');
        $highMark = (string) ($marks['high'] ?? 'FILTER-129>254');

        $mangleRules = [
            [
                'suffix' => 'mangle-low',
                'src_address' => $ranges['low'],
                'routing_mark' => $lowMark,
            ],
            [
                'suffix' => 'mangle-high',
                'src_address' => $ranges['high'],
                'routing_mark' => $highMark,
            ],
        ];

        foreach ($mangleRules as $rule) {
            $comment = $this->mangleComment($interfaceName, $rule['suffix']);

            if ($this->mikrotik->itemExists($server, '/ip/firewall/mangle/print', ['comment' => $comment])) {
                $skipped[] = $rule['suffix'];

                continue;
            }

            if (! $this->routingMarkExists($server, $rule['routing_mark'])) {
                $warnings[] = __('servers.wireguard_firewall_routing_mark_missing', [
                    'mark' => $rule['routing_mark'],
                ]);

                continue;
            }

            try {
                $this->mikrotik->sendCommand($server, '/ip/firewall/mangle/add', [
                    'chain' => 'prerouting',
                    'src-address' => $rule['src_address'],
                    'action' => 'mark-routing',
                    'new-routing-mark' => $rule['routing_mark'],
                    'passthrough' => 'yes',
                    'comment' => $comment,
                ]);
                $created[] = $rule['suffix'];
            } catch (Throwable $exception) {
                $warnings[] = __('servers.wireguard_firewall_mangle_failed', [
                    'rule' => $rule['suffix'],
                    'error' => $exception->getMessage(),
                ]);
                Log::warning('WireGuard mangle rule failed', [
                    'server_id' => $server->id,
                    'interface' => $interfaceName,
                    'rule' => $rule['suffix'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return compact('created', 'skipped', 'warnings');
    }

    /**
     * Remove panel-managed NAT/mangle rules for an interface (by comment tag).
     */
    public function removeClientRules(Server $server, string $interfaceName): int
    {
        $removed = 0;
        $targets = [
            ['/ip/firewall/nat', $this->natComment($interfaceName)],
            ['/ip/firewall/mangle', $this->mangleComment($interfaceName, 'mangle-low')],
            ['/ip/firewall/mangle', $this->mangleComment($interfaceName, 'mangle-high')],
        ];

        foreach ($targets as [$menu, $comment]) {
            foreach ($this->mikrotik->queryRouter($server, $menu.'/print', ['comment' => $comment]) as $row) {
                $id = $row['.id'] ?? null;
                if ($id === null) {
                    continue;
                }

                try {
                    $this->mikrotik->sendCommand($server, $menu.'/remove', ['.id' => $id]);
                    $removed++;
                } catch (Throwable $exception) {
                    Log::warning('WireGuard firewall rule remove failed', [
                        'server_id' => $server->id,
                        'comment' => $comment,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return $removed;
    }

    protected function natComment(string $interfaceName): string
    {
        return 'vpnpanel:wg:'.$interfaceName.':nat';
    }

    protected function mangleComment(string $interfaceName, string $suffix): string
    {
        return 'vpnpanel:wg:'.$interfaceName.':'.$suffix;
    }

    protected function routingMarkExists(Server $server, string $routingMark): bool
    {
        if ($routingMark === '') {
            return false;
        }

        try {
            return $this->mikrotik->itemExists($server, '/routing/table/print', ['name' => $routingMark]);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Client host ranges for mangle (last octet 2–128 and 129–254). Only /24 for now.
     *
     * @return array{low: string, high: string}|null
     */
    protected function clientMangleRangesFromSubnet(string $subnetCidr): ?array
    {
        if (str_contains($subnetCidr, ':')) {
            return null;
        }

        [$base, $prefixRaw] = array_pad(explode('/', trim($subnetCidr), 2), 2, '24');
        $prefix = (int) $prefixRaw;

        if ($prefix !== 24) {
            return null;
        }

        $parts = explode('.', $base);
        if (count($parts) !== 4) {
            return null;
        }

        foreach ($parts as $octet) {
            if ((int) $octet > 255) {
                return null;
            }
        }

        $a = (int) $parts[0];
        $b = (int) $parts[1];
        $c = (int) $parts[2];

        return [
            'low' => "{$a}.{$b}.{$c}.2-{$a}.{$b}.{$c}.128",
            'high' => "{$a}.{$b}.{$c}.129-{$a}.{$b}.{$c}.254",
        ];
    }
}
