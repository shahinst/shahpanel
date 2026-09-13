<?php

namespace Tests\Unit\CrmTunneling;

use App\CrmTunneling\SubnetAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubnetAllocatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_hub_gets_higher_host_than_exit(): void
    {
        $pair = app(SubnetAllocator::class)->allocate();

        $this->assertMatchesRegularExpression('#^\d+\.\d+\.\d+\.\d+/30$#', $pair['subnet']);
        $this->assertSame(1, ip2long($pair['hub_ip']) - ip2long($pair['exit_ip']));
    }
}
