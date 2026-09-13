<?php

namespace App\CrmTunneling\Drivers;

use App\CrmTunneling\Contracts\TunnelDriver;
use App\CrmTunneling\Dto\TunnelTestResult;
use App\CrmTunneling\MikrotikClient;
use App\Models\CrmTunnel;
use App\Models\Server;
use Throwable;

class GreTunnelDriver implements TunnelDriver
{
    private const RESET_MENUS = [
        '/ip/firewall/mangle',
        '/ip/firewall/nat',
        '/ip/firewall/filter',
        '/ip/route',
        '/routing/table',
        '/ip/address',
        '/interface/gre',
    ];

    public function __construct(protected MikrotikClient $client)
    {
    }

    public function reset(Server $server): array
    {
        $prefix = (string) config('crm_tunnel.comment_prefix', 'CRM-TUN');
        $logs = ["reset {$server->name}"];

        foreach (self::RESET_MENUS as $menu) {
            try {
                $count = $this->client->removeWhereCommentContains($server, $menu, $prefix);
                $logs[] = "  {$menu}: {$count} removed";
            } catch (Throwable $e) {
                $logs[] = "  {$menu}: ERROR ".$e->getMessage();
            }
        }

        return $logs;
    }

    public function provision(CrmTunnel $tunnel): array
    {
        $tunnel->loadMissing('hubServer', 'exitServer');
        $hub = $tunnel->hubServer;
        $exit = $tunnel->exitServer;
        $tag = $tunnel->comment_tag;
        $logs = [];

        $hubIp = $this->client->hubPublicIp($hub);
        $exitIp = $this->client->hubPublicIp($exit);

        $logs = array_merge($logs, $this->provisionExit($tunnel, $exit, $hubIp, $exitIp, $tag));
        $logs = array_merge($logs, $this->provisionHub($tunnel, $hub, $hubIp, $exitIp, $tag));

        return $logs;
    }

    public function test(CrmTunnel $tunnel): TunnelTestResult
    {
        $tunnel->loadMissing('hubServer');
        $hub = $tunnel->hubServer;
        $raw = [];

        $running = false;
        try {
            $rows = $this->client->print($hub, '/interface/gre', ['name' => $tunnel->name]);
            $row = $rows[0] ?? [];
            $running = in_array(strtolower((string) ($row['running'] ?? '')), ['true', 'yes'], true);
            $raw['interface'] = $row;
        } catch (Throwable $e) {
            $raw['interface_error'] = $e->getMessage();
        }

        $pingTunnel = $this->pingSummary($hub, $tunnel->exit_tunnel_ip, $raw, 'ping_tunnel');
        $pingInternet = '0/0';

        try {
            $this->client->add($hub, '/ip/route', [
                'dst-address' => '1.1.1.1/32',
                'gateway' => $tunnel->exit_tunnel_ip,
                'comment' => 'CRM-TUN-TEST',
            ]);
            $pingInternet = $this->pingSummary($hub, '1.1.1.1', $raw, 'ping_internet');
            $this->client->removeWhereCommentContains($hub, '/ip/route', 'CRM-TUN-TEST');
        } catch (Throwable $e) {
            $raw['internet_test_error'] = $e->getMessage();
        }

        $tunnelReceived = (int) (explode('/', $pingTunnel)[0] ?? 0);
        $internetReceived = (int) (explode('/', $pingInternet)[0] ?? 0);
        $passed = $running && $tunnelReceived > 0 && $internetReceived > 0;

        return new TunnelTestResult($running, $pingTunnel, $pingInternet, $passed, $raw);
    }

    public function teardown(CrmTunnel $tunnel): array
    {
        $tunnel->loadMissing('hubServer', 'exitServer');
        $logs = [];

        foreach ([$tunnel->exitServer, $tunnel->hubServer] as $server) {
            if ($server === null) {
                continue;
            }

            $logs = array_merge($logs, $this->reset($server));
        }

        return $logs;
    }

    /**
     * @return list<string>
     */
    protected function provisionExit(CrmTunnel $tunnel, Server $exit, string $hubIp, string $exitIp, string $tag): array
    {
        $wan = $this->client->wanInterface($exit);
        $logs = ["provision exit {$exit->name}"];

        $this->client->add($exit, '/interface/gre', [
            'name' => $tunnel->name,
            'local-address' => $exitIp,
            'remote-address' => $hubIp,
            'keepalive' => $tunnel->keepalive,
            'comment' => $tag,
        ]);

        $this->client->add($exit, '/ip/address', [
            'address' => $tunnel->exit_tunnel_ip.'/30',
            'interface' => $tunnel->name,
            'comment' => $tag,
        ]);

        $this->client->add($exit, '/ip/firewall/filter', [
            'chain' => 'input',
            'protocol' => 'gre',
            'src-address' => $hubIp,
            'action' => 'accept',
            'comment' => $tag,
        ]);

        $this->client->add($exit, '/ip/firewall/nat', [
            'chain' => 'srcnat',
            'out-interface' => $wan,
            'action' => 'masquerade',
            'comment' => $tag,
        ]);

        foreach (['in-interface', 'out-interface'] as $field) {
            $this->client->add($exit, '/ip/firewall/mangle', [
                'chain' => 'forward',
                'protocol' => 'tcp',
                'tcp-flags' => 'syn',
                $field => $tunnel->name,
                'action' => 'change-mss',
                'new-mss' => 'clamp-to-pmtu',
                'comment' => $tag,
            ]);
        }

        $logs[] = '  exit objects created';

        return $logs;
    }

    /**
     * @return list<string>
     */
    protected function provisionHub(CrmTunnel $tunnel, Server $hub, string $hubIp, string $exitIp, string $tag): array
    {
        $logs = ["provision hub {$hub->name}"];

        $this->client->add($hub, '/interface/gre', [
            'name' => $tunnel->name,
            'local-address' => $hubIp,
            'remote-address' => $exitIp,
            'keepalive' => $tunnel->keepalive,
            'comment' => $tag,
        ]);

        $this->client->add($hub, '/ip/address', [
            'address' => $tunnel->hub_tunnel_ip.'/30',
            'interface' => $tunnel->name,
            'comment' => $tag,
        ]);

        $this->client->add($hub, '/ip/firewall/filter', [
            'chain' => 'input',
            'protocol' => 'gre',
            'src-address' => $exitIp,
            'action' => 'accept',
            'comment' => $tag,
        ]);

        foreach (['out-interface', 'in-interface'] as $field) {
            $this->client->add($hub, '/ip/firewall/mangle', [
                'chain' => 'forward',
                'protocol' => 'tcp',
                'tcp-flags' => 'syn',
                $field => $tunnel->name,
                'action' => 'change-mss',
                'new-mss' => 'clamp-to-pmtu',
                'comment' => $tag,
            ]);
        }

        $logs[] = '  hub objects created';

        return $logs;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    protected function pingSummary(Server $server, string $address, array &$raw, string $key): string
    {
        $sent = 0;
        $received = 0;

        try {
            $rows = $this->client->ping($server, ['address' => $address, 'count' => '5']);
            $raw[$key.'_rows'] = $rows;

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $sent = max($sent, (int) ($row['sent'] ?? 0));
                $received = max($received, (int) ($row['received'] ?? 0));
            }
        } catch (Throwable $e) {
            $raw[$key.'_error'] = $e->getMessage();
        }

        if ($sent === 0) {
            $sent = 5;
        }

        return "{$received}/{$sent}";
    }
}
