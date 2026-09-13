<?php

namespace App\CrmTunneling;

use App\Models\CrmTunnel;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SubnetAllocator
{
    /**
     * Allocate a /30: exit gets .1, hub gets .2 (network base +1 / +2).
     *
     * @return array{subnet: string, hub_ip: string, exit_ip: string}
     */
    public function allocate(): array
    {
        $pool = (string) config('crm_tunnel.subnet_pool', '10.16.0.0/16');

        return DB::transaction(function () use ($pool): array {
            [$network, $poolPrefix] = $this->parseCidr($pool);
            $prefix = 30;
            $step = 4;
            $poolSize = 1 << (32 - $poolPrefix);

            $taken = CrmTunnel::query()->lockForUpdate()->pluck('tunnel_subnet')
                ->map(fn (string $cidr): int => $this->parseCidr($cidr)[0])
                ->all();

            for ($candidate = $network; $candidate < $network + $poolSize; $candidate += $step) {
                if (! in_array($candidate, $taken, true)) {
                    $cidr = long2ip($candidate).'/'.$prefix;

                    return [
                        'subnet' => $cidr,
                        'exit_ip' => long2ip($candidate + 1),
                        'hub_ip' => long2ip($candidate + 2),
                    ];
                }
            }

            throw new RuntimeException("Pool {$pool} has no free /30 subnets.");
        });
    }

    /** @return array{0: int, 1: int} */
    protected function parseCidr(string $cidr): array
    {
        [$ip, $prefix] = array_pad(explode('/', $cidr, 2), 2, '32');
        $network = ip2long($ip) & (~((1 << (32 - (int) $prefix)) - 1) & 0xFFFFFFFF);

        return [$network, (int) $prefix];
    }
}
