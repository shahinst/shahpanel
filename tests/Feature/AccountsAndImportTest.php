<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\ServerInterface;
use App\Models\User;
use App\Services\AccountService;
use App\Services\EndUserService;
use App\Services\MikrotikClientImportService;
use App\Services\MikrotikService;
use App\Services\SanaeiClientImportService;
use App\Services\ServerSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class AccountsAndImportTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    private function fakeServerSelection($server): void
    {
        $sel = Mockery::mock(ServerSelectionService::class);
        $sel->shouldReceive('pickLeastBusyForPackage')->andReturn($server);
        $this->app->instance(ServerSelectionService::class, $sel);
    }

    public function test_failed_auto_purchase_leaves_no_client(): void
    {
        $seller = $this->makeSeller();
        [$pkg, $dur] = $this->makePackage();
        $this->assignPackage($seller, $pkg);
        $this->fakeServerSelection($this->makeServer());

        $svc = Mockery::mock(AccountService::class);
        $svc->shouldReceive('createAccount')->once()->andReturnUsing(function ($s, $p, $srv, $d, $data) {
            DB::transaction(function () use ($s, $data) {
                app(EndUserService::class)->resolveForAccount($s, $data);
                throw new \RuntimeException('router down');
            });
        });
        $this->app->instance(AccountService::class, $svc);

        $before = User::query()->where('role', 'client')->count();
        $this->actingAs($seller)->post(route('seller.accounts.store'), [
            'account_display_name' => 'Ali', 'client_mode' => 'auto', 'package_id' => $pkg->id, 'package_duration_id' => $dur->id,
        ])->assertSessionHas('error', 'router down');
        $this->assertSame($before, User::query()->where('role', 'client')->count());
    }

    public function test_bulk_create_with_one_shared_client(): void
    {
        $seller = $this->makeSeller();
        [$pkg, $dur] = $this->makePackage();
        $this->assignPackage($seller, $pkg);
        $server = $this->makeServer();
        $this->fakeServerSelection($server);

        $calls = [];
        $svc = Mockery::mock(AccountService::class);
        $svc->shouldReceive('createAccount')->times(3)->andReturnUsing(function ($s, $p, $srv, $d, $data) use (&$calls, $server) {
            $calls[] = $data;
            $client = app(EndUserService::class)->resolveForAccount($s, $data);

            return $this->makeAccount($s, $server, ['client_user_id' => $client->id, 'display_label' => $data['display_label']]);
        });
        $this->app->instance(AccountService::class, $svc);

        $this->actingAs($seller)->post(route('seller.accounts.bulk-store'), [
            'account_display_name' => 'Ali', 'client_mode' => 'auto', 'package_id' => $pkg->id, 'package_duration_id' => $dur->id,
            'account_count' => '۳', 'bulk_client_mode' => 'shared',
        ])->assertSessionHas('success');

        $this->assertSame(['Ali-1', 'Ali-2', 'Ali-3'], array_column($calls, 'display_label'));
        $this->assertSame('existing', $calls[1]['client_mode']);
        $this->assertSame(1, User::query()->where('role', 'client')->where('parent_id', $seller->id)->count());
    }

    public function test_sanaei_import_keeps_unassigned_rows_and_survives_large_forms(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $server = $this->makeServer('sanaei');
        $captured = null;
        $mock = Mockery::mock(SanaeiClientImportService::class);
        $mock->shouldReceive('import')->once()->andReturnUsing(function ($s, $i, $t, $a) use (&$captured) {
            $captured = $a;

            return ['created' => count($a), 'updated' => 0, 'skipped' => 0, 'errors' => [], 'lines' => []];
        });
        $this->app->instance(SanaeiClientImportService::class, $mock);

        $clients = [];
        $rows = [];
        for ($i = 0; $i < 400; $i++) {
            $clients[] = ['uuid' => "u$i", 'email' => "e$i", 'existing_account_id' => null];
            $rows[] = $i < 380 ? ['uuid' => "u$i", 'owner_id' => (string) $agent->id] : ['uuid' => "u$i"];
        }

        $this->actingAs($admin)
            ->withSession(['server_client_import_'.$server->id => ['source' => 'sanaei', 'inbound_id' => 4, 'service_type' => 'sanaei_vless', 'clients' => $clients]])
            ->post(route('admin.servers.import-clients.store', $server), ['assignments_json' => json_encode($rows)])
            ->assertRedirect(route('admin.servers.import-clients.assign', $server));

        $this->assertCount(380, $captured);
        $this->assertCount(20, session('server_client_import_'.$server->id)['clients']);
    }

    public function test_mikrotik_import_applies_package_terms_type_and_restores(): void
    {
        $agent = $this->makeAgent();
        $server = $this->makeServer();
        ServerInterface::query()->forceCreate(['server_id' => $server->id, 'name' => 'vpn', 'remote_key' => 'profile:ppp:vpn', 'category' => 'ppp', 'protocol' => 'l2tp']);
        [$ovpn] = $this->makePackage(ServiceType::Openvpn, 100);
        $old = $this->makeAccount($agent, $server, ['remote_username' => 'olduser', 'service_type' => ServiceType::Openvpn]);
        $old->delete();

        $mt = Mockery::mock(MikrotikService::class);
        $mt->shouldReceive('listPppSecrets')->andReturn([
            ['name' => 'olduser', 'profile' => 'vpn', 'service' => 'ovpn'],
            ['name' => 'new.user', 'profile' => 'vpn', 'service' => 'ovpn'],
        ]);
        $this->app->instance(MikrotikService::class, $mt);

        $import = $this->app->make(MikrotikClientImportService::class);
        $res = $import->import($server, 'profile:ppp:vpn', ServiceType::L2tp, [
            ['uuid' => 'ppp:olduser', 'owner_id' => $agent->id, 'package_id' => $ovpn->id, 'update_existing' => false],
            ['uuid' => 'ppp:new.user', 'owner_id' => $agent->id, 'package_id' => $ovpn->id, 'update_existing' => false],
        ]);

        $this->assertSame([1, 1], [$res['created'], $res['updated']]);
        $this->assertNotNull(Account::query()->find($old->id));
        $new = Account::query()->where('remote_username', 'new.user')->first();
        $this->assertSame(ServiceType::Openvpn, $new->service_type);
        $this->assertSame(100 * 1024 ** 3, (int) $new->data_limit_bytes);
        $this->assertNotNull($new->expiry_at);
    }

    public function test_admin_deletes_empty_client_but_not_busy_users(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $seller = $this->makeSeller($agent);
        $client = $this->makeClient($seller);

        $this->actingAs($admin)->delete(route('admin.clients.destroy', $client))->assertSessionHas('success');
        $this->assertSoftDeleted('users', ['id' => $client->id]);

        $this->makeClient($seller);
        $this->actingAs($admin)->delete(route('admin.sellers.destroy', $seller))->assertSessionHas('error');
        $this->makeAccount($agent, $this->makeServer());
        $this->actingAs($admin)->delete(route('admin.users.destroy', $agent))->assertSessionHas('error');
        $this->assertNotSoftDeleted('users', ['id' => $agent->id]);
    }

    public function test_server_with_only_deleted_accounts_can_be_deleted(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $server = $this->makeServer();
        $live = $this->makeServer();
        $acc = $this->makeAccount($agent, $server);
        $acc->delete();
        $this->makeAccount($agent, $live);

        $this->actingAs($admin)->delete(route('admin.servers.destroy', $server))->assertSessionHas('success');
        $this->assertNull(Account::withTrashed()->find($acc->id)->server_id);
        $this->actingAs($admin)->delete(route('admin.servers.destroy', $live))->assertSessionHas('error');
    }

    public function test_refund_of_imported_account_moves_no_money(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $account = $this->makeAccount($agent, $this->makeServer(), ['expiry_at' => now()->addDays(10)]);
        $svc = Mockery::mock(AccountService::class);
        $svc->shouldReceive('deactivateRemoteForRefund')->once();
        $svc->shouldReceive('activateRemoteAfterRefund')->once();
        $this->app->instance(AccountService::class, $svc);

        $refunds = $this->app->make(\App\Services\AccountRefundService::class);
        $this->assertTrue($refunds->refund($account, $admin)['without_payment']);
        $this->assertSame(AccountStatus::Disabled, $account->fresh()->status);
        $refunds->reactivate($account->fresh(), $admin);
        $this->assertSame(AccountStatus::Active, $account->fresh()->status);
    }
}
