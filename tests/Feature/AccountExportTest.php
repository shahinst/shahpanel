<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class AccountExportTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_the_export_holds_what_the_page_shows_and_nothing_more(): void
    {
        $agent = $this->makeAgent();
        $mine = $this->makeSeller($agent);
        $other = $this->makeSeller($this->makeAgent());
        $server = $this->makeServer();

        $this->makeAccount($mine, $server, ['remote_username' => 'zz-mine-wg']);
        $this->makeAccount($other, $server, ['remote_username' => 'zz-other-wg']);

        $csv = $this->actingAs($mine)
            ->get(route('seller.accounts.export', ['category' => 'wireguard']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('zz-mine-wg', $csv);
        $this->assertStringNotContainsString('zz-other-wg', $csv);

        $all = $this->actingAs($this->makeAdmin())
            ->get(route('admin.accounts.export', ['category' => 'wireguard', 'search' => 'zz-other']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('zz-other-wg', $all);
        $this->assertStringNotContainsString('zz-mine-wg', $all);

        $this->actingAs($mine)
            ->get(route('seller.accounts.export', ['category' => 'nonsense']))
            ->assertNotFound();
    }
}
