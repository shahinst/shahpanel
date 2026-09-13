<?php

namespace Tests\Unit;

use App\Enums\ServerType;
use App\Models\Server;
use App\Services\Remnawave\RemnawavePanelUrl;
use Tests\TestCase;

class RemnawavePanelUrlTest extends TestCase
{
    public function test_api_base_url_with_secret_path(): void
    {
        $server = new Server([
            'host' => 'panel.example.com',
            'port' => 443,
            'type' => ServerType::Remnawave,
            'web_base_path' => 'secret-path',
        ]);

        $url = RemnawavePanelUrl::fromServer($server);

        $this->assertSame('https://panel.example.com/secret-path/api', $url->apiBaseUrl());
        // api() hangs its argument off apiBaseUrl(), so the /api segment
        // asserted on the line above is part of the result too.
        $this->assertSame('https://panel.example.com/secret-path/api/users/', $url->api('users/'));
    }

    public function test_host_with_trailing_api_does_not_double_api_segment(): void
    {
        $server = new Server([
            'host' => 'https://admin.pvline.ir/api/',
            'port' => 443,
            'type' => ServerType::Remnawave,
        ]);

        $url = RemnawavePanelUrl::fromServer($server);

        $this->assertSame('https://admin.pvline.ir/api', $url->apiBaseUrl());
        $this->assertSame('https://admin.pvline.ir/api/internal-squads', $url->api('internal-squads'));
    }

    public function test_normalize_squad_list(): void
    {
        $normalized = \App\Services\Remnawave\RemnawaveSquadCatalog::normalizeList([
            ['uuid' => 'abc-123', 'name' => 'Main'],
            ['id' => 'def-456', 'title' => 'Backup'],
        ]);

        $this->assertCount(2, $normalized);
        $this->assertSame('abc-123', $normalized[0]['uuid']);
        $this->assertSame('def-456', $normalized[1]['uuid']);
        $this->assertSame('Backup', $normalized[1]['name']);
    }
}
