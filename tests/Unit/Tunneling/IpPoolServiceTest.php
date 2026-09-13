<?php

namespace Tests\Unit\Tunneling;

use App\Models\IpPoolAllocation;
use App\Models\Location;
use App\Services\Tunneling\IpPoolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IpPoolServiceTest extends TestCase
{
    use RefreshDatabase;

    protected IpPoolService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(IpPoolService::class);

        config([
            'tunneling.transport_supernet' => '172.16.0.0/24',
            'tunneling.client_supernet' => '10.64.0.0/22',
        ]);
    }

    protected function owner(string $code): Location
    {
        return Location::create(['name' => 'L '.$code, 'code' => $code, 'is_active' => true]);
    }

    public function test_transport_allocations_never_overlap(): void
    {
        $cidrs = [];

        for ($i = 0; $i < 20; $i++) {
            $cidrs[] = $this->service->allocate('transport', $this->owner('t'.$i));
        }

        $this->assertCount(20, array_unique($cidrs));
        $this->assertSame('172.16.0.0/30', $cidrs[0]);
        $this->assertSame('172.16.0.4/30', $cidrs[1]);
    }

    public function test_transport_pair_assigns_iran_and_foreign_hosts(): void
    {
        $pair = $this->service->transportPair('172.16.0.8/30');

        $this->assertSame('172.16.0.9', $pair['iran']);
        $this->assertSame('172.16.0.10', $pair['foreign']);
    }

    public function test_wireguard_and_ppp_share_one_client_pool_without_overlap(): void
    {
        $wg = $this->service->allocate('wg_clients', $this->owner('wg'));
        $ppp = $this->service->allocate('ppp_clients', $this->owner('ppp'));

        $this->assertNotSame($wg, $ppp);
        $this->assertSame('10.64.0.0/24', $wg);
        $this->assertSame('10.64.1.0/24', $ppp);
    }

    public function test_release_frees_subnet_for_reuse(): void
    {
        $first = $this->owner('a');
        $cidr = $this->service->allocate('transport', $first);

        $this->service->releaseFor($first);

        $this->assertSame(0, IpPoolAllocation::query()->count());
        $this->assertSame($cidr, $this->service->allocate('transport', $this->owner('b')));
    }

    public function test_exhausted_pool_throws(): void
    {
        config(['tunneling.transport_supernet' => '172.16.0.0/29']);

        $this->service->allocate('transport', $this->owner('x1'));
        $this->service->allocate('transport', $this->owner('x2'));

        $this->expectException(\RuntimeException::class);
        $this->service->allocate('transport', $this->owner('x3'));
    }
}
