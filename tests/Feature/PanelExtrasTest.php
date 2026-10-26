<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountUsageLog;
use App\Services\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class PanelExtrasTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_a_v2ray_account_is_found_by_its_config_uuid_and_shows_it(): void
    {
        $agent = $this->makeAgent();
        [$package] = $this->makePackage();
        $server = $this->makeServer('sanaei');
        $uuid = '3f7c2a1e-9b44-4d6a-8e21-5a0c9d7b1f00';
        $this->makeAccount($agent, $server, ['package_id' => $package->id, 'service_type' => \App\Enums\ServiceType::SanaeiVless, 'sanaei_client_uuid' => $uuid, 'remote_username' => 'first']);
        $this->makeAccount($agent, $server, ['package_id' => $package->id, 'service_type' => \App\Enums\ServiceType::SanaeiVless, 'remote_username' => 'second']);

        $this->actingAs($agent)->get(route('agent.accounts.v2ray', ['search' => '9b44-4d6a']))->assertOk()
            ->assertSee($uuid)->assertSee('first')->assertDontSee('>second<', false);
    }

    public function test_accounts_with_recent_traffic_are_marked_online(): void
    {
        $agent = $this->makeAgent();
        [$package] = $this->makePackage();
        $server = $this->makeServer();
        $busy = $this->makeAccount($agent, $server, ['package_id' => $package->id, 'remote_username' => 'busy']);
        $idle = $this->makeAccount($agent, $server, ['package_id' => $package->id, 'remote_username' => 'idle']);
        AccountUsageLog::query()->create(['account_id' => $busy->id, 'rx_delta_bytes' => 1024, 'tx_delta_bytes' => 0, 'recorded_at' => now()->subMinutes(2)]);
        AccountUsageLog::query()->create(['account_id' => $idle->id, 'rx_delta_bytes' => 1024, 'tx_delta_bytes' => 0, 'recorded_at' => now()->subMinutes(Account::ONLINE_WINDOW_MINUTES + 5)]);

        $html = $this->actingAs($agent)->get(route('agent.accounts.wireguard'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'account-presence--online'));
        $this->assertSame(1, substr_count($html, 'account-presence--offline'));
    }

    public function test_accounting_dates_cover_whole_days_in_jalali_and_filter_by_owner(): void
    {
        $this->app->setLocale('fa');
        // 1404/08/01 is 2025-10-23.
        $from = AccountingService::parseFilterDate('۱۴۰۴/۰۸/۰۱', false);
        $to = AccountingService::parseFilterDate('1404/08/01', true);
        $this->assertSame('2025-10-23 00:00:00', $from->format('Y-m-d H:i:s'));
        $this->assertSame('2025-10-23 23:59:59', $to->format('Y-m-d H:i:s'));

        $this->app->setLocale('en');
        $this->assertSame('2025-10-23 23:59:59', AccountingService::parseFilterDate('2025-10-23', true)->format('Y-m-d H:i:s'));
        $this->assertNull(AccountingService::parseFilterDate('not a date', true));

        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $other = $this->makeAgent();
        $this->actingAs($admin)->get(route('admin.accounting.index', ['owner_id' => $agent->id]))->assertOk()
            ->assertSee('name="owner_id"', false)->assertSee('value="'.$agent->id.'" selected', false);
        // An agent only sees their own sellers in the list, never another agent.
        $this->actingAs($agent)->get(route('agent.accounting.index', ['owner_id' => $other->id]))->assertOk()
            ->assertDontSee('value="'.$other->id.'" selected', false);
    }

    public function test_the_panel_offers_a_manifest_and_a_worker_coloured_by_the_theme(): void
    {
        $this->get('/pwa/manifest')->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('display', 'standalone')
            ->assertJsonPath('theme_color', '#4f46e5')
            ->assertJsonPath('icons.0.sizes', '192x192');

        $worker = $this->get('/pwa/worker')->assertOk()->assertHeader('Service-Worker-Allowed', '/');
        $this->assertStringContainsString("'/pwa/offline'", $worker->getContent());
        $this->assertStringContainsString('shahpanel-'.trim(file_get_contents(base_path('VERSION'))), $worker->getContent());

        $this->get('/pwa/offline')->assertOk()->assertSee(__('ui.offline_retry'));
        $this->get('/login')->assertOk()->assertSee('rel="manifest"', false)->assertSee(__('auth.premium_notice'));
    }
}
