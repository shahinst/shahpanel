<?php

namespace App\Services\Tunneling;

use App\Models\IpPool;
use App\Models\IpPoolAllocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Collision-free subnet allocator. Transport /30s for tunnel agents and
 * client /24s for managed interfaces all come from configured supernets;
 * every hand-out is recorded in ip_pool_allocations (unique per pool+cidr),
 * so re-running provisioning can never produce overlapping networks.
 */
class IpPoolService
{
    /**
     * Allocate the next free subnet of $prefix size from the pool serving
     * $purpose (pool auto-created from config supernet on first use).
     */
    public function allocate(string $purpose, Model $owner, ?int $prefix = null): string
    {
        $pool = $this->poolFor($purpose);
        $prefix ??= (int) $pool->allocation_prefix;

        return DB::transaction(function () use ($pool, $prefix, $owner): string {
            [$network, $poolPrefix] = $this->parseCidr($pool->cidr);

            if ($prefix < $poolPrefix) {
                throw new RuntimeException("اندازه سابنت /{$prefix} از سوپرنت /{$poolPrefix} بزرگ‌تر است.");
            }

            $taken = IpPoolAllocation::query()
                ->where('ip_pool_id', $pool->id)
                ->lockForUpdate()
                ->pluck('cidr')
                ->map(fn (string $cidr): array => $this->parseCidr($cidr))
                ->all();

            $step = 1 << (32 - $prefix);
            $poolSize = 1 << (32 - $poolPrefix);

            for ($candidate = $network; $candidate < $network + $poolSize; $candidate += $step) {
                if (! $this->overlapsAny($candidate, $step, $taken)) {
                    $cidr = long2ip($candidate).'/'.$prefix;

                    IpPoolAllocation::create([
                        'ip_pool_id' => $pool->id,
                        'cidr' => $cidr,
                        'owner_type' => $owner->getMorphClass(),
                        'owner_id' => $owner->getKey(),
                    ]);

                    return $cidr;
                }
            }

            throw new RuntimeException("سوپرنت {$pool->cidr} فضای خالی برای /{$prefix} ندارد.");
        });
    }

    /** Release every allocation owned by the model. */
    public function releaseFor(Model $owner): void
    {
        IpPoolAllocation::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->delete();
    }

    /**
     * First/second usable host of a /30 (Iran gets .1, foreign gets .2).
     *
     * @return array{iran: string, foreign: string}
     */
    public function transportPair(string $cidr): array
    {
        [$network] = $this->parseCidr($cidr);

        return [
            'iran' => long2ip($network + 1),
            'foreign' => long2ip($network + 2),
        ];
    }

    protected function poolFor(string $purpose): IpPool
    {
        $defaults = match ($purpose) {
            'transport' => [
                'cidr' => (string) config('tunneling.transport_supernet', '172.16.0.0/16'),
                'allocation_prefix' => 30,
            ],
            'wg_clients', 'ppp_clients' => [
                'cidr' => (string) config('tunneling.client_supernet', '10.64.0.0/12'),
                'allocation_prefix' => 24,
            ],
            default => throw new RuntimeException("نوع pool ناشناخته: {$purpose}"),
        };

        // wg/ppp share one client supernet through a single pool row.
        $cidr = $defaults['cidr'];
        $name = str_contains($purpose, 'clients') ? 'clients' : $purpose;

        return IpPool::query()->firstOrCreate(
            ['cidr' => $cidr],
            [
                'name' => $name,
                'purpose' => $purpose,
                'allocation_prefix' => $defaults['allocation_prefix'],
                'is_active' => true,
            ],
        );
    }

    /**
     * @return array{0: int, 1: int} network long + prefix
     */
    protected function parseCidr(string $cidr): array
    {
        [$base, $prefix] = array_pad(explode('/', trim($cidr), 2), 2, '32');
        $prefix = (int) $prefix;
        $mask = $prefix === 0 ? 0 : (~((1 << (32 - $prefix)) - 1) & 0xFFFFFFFF);

        return [ip2long($base) & $mask, $prefix];
    }

    /**
     * @param  list<array{0: int, 1: int}>  $taken
     */
    protected function overlapsAny(int $candidate, int $size, array $taken): bool
    {
        $candidateEnd = $candidate + $size - 1;

        foreach ($taken as [$network, $prefix]) {
            $end = $network + (1 << (32 - $prefix)) - 1;

            if ($candidate <= $end && $candidateEnd >= $network) {
                return true;
            }
        }

        return false;
    }
}
