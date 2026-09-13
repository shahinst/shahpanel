<?php

namespace Tests\Unit;

use App\Services\Remnawave\RemnawaveNodeCatalog;
use Tests\TestCase;

class RemnawaveNodeCatalogTest extends TestCase
{
    public function test_normalize_list_extracts_nodes(): void
    {
        $normalized = RemnawaveNodeCatalog::normalizeList([
            [
                'uuid' => '550e8400-e29b-41d4-a716-446655440000',
                'name' => 'DE-1',
                'address' => '1.2.3.4',
                'port' => 443,
                'isConnected' => true,
            ],
        ]);

        $this->assertCount(1, $normalized);
        $this->assertSame('DE-1', $normalized[0]['name']);
        $this->assertSame('1.2.3.4', $normalized[0]['address']);
        $this->assertSame(443, $normalized[0]['port']);
        $this->assertTrue($normalized[0]['is_connected']);
    }
}
