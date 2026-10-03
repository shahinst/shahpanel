<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\NotificationType;
use App\Jobs\SendTelegramNotificationJob;
use App\Models\Setting;
use App\Services\AccountService;
use App\Services\NotificationService;
use App\Support\ServerBackupTelegramSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class FeaturesTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_bulk_action_runs_for_every_selected_account(): void
    {
        $admin = $this->makeAdmin();
        $agent = $this->makeAgent();
        $server = $this->makeServer();
        $a = $this->makeAccount($agent, $server);
        $b = $this->makeAccount($agent, $server);

        $svc = Mockery::mock(AccountService::class);
        $svc->shouldReceive('disableAccount')->twice();
        $this->app->instance(AccountService::class, $svc);

        $this->actingAs($admin)
            ->from(route('admin.accounts.wireguard'))
            ->post(route('admin.accounts.bulk-action'), ['action' => 'disable', 'ids' => [$a->id, $b->id]])
            ->assertRedirect()
            ->assertSessionHas('success');
    }

    public function test_auto_renew_renews_instead_of_expiring(): void
    {
        $agent = $this->makeAgent();
        $account = $this->makeAccount($agent, $this->makeServer(), [
            'expiry_at' => now()->subMinute(),
            'auto_renew' => true,
        ]);

        $svc = Mockery::mock(AccountService::class);
        $svc->shouldReceive('renewAccount')->once()->andReturnUsing(function ($acc) {
            $acc->update(['expiry_at' => now()->addDays(30)]);

            return $acc;
        });
        $svc->shouldNotReceive('expireAccount');
        $svc->shouldReceive('retryPendingRemoteDisable')->andReturnTrue();
        $this->app->instance(AccountService::class, $svc);

        Artisan::call('accounts:check-expiry');

        $this->assertSame(AccountStatus::Active, $account->fresh()->status);
    }

    public function test_auto_renew_toggle(): void
    {
        $admin = $this->makeAdmin();
        $account = $this->makeAccount($this->makeAgent(), $this->makeServer());

        $this->actingAs($admin)->post(route('admin.accounts.auto-renew', $account))->assertRedirect();

        $this->assertTrue($account->fresh()->auto_renew);
    }

    public function test_low_balance_and_client_alerts(): void
    {
        Setting::setValue('alert_low_balance_amount', '1000');
        Setting::setValue('alert_clients_enabled', '1');

        $agent = $this->makeAgent();
        $seller = $this->makeSeller($agent);
        $client = $this->makeClient($seller);
        $this->makeAccount($seller, $this->makeServer(), [
            'client_user_id' => $client->id,
            'expiry_at' => now()->addDay(),
        ]);

        Artisan::call('alerts:dispatch');

        $this->assertTrue(DB::table('notifications')->where('user_id', $agent->id)->where('reference_key', 'lowbal:'.$agent->id)->exists());
        $this->assertTrue(DB::table('notifications')->where('user_id', $client->id)->where('reference_key', 'like', 'expiry:client:%')->exists());
        $this->assertTrue(DB::table('notifications')->where('user_id', $seller->id)->where('reference_key', 'like', 'expiry:%')->exists());
    }

    public function test_notifications_go_to_telegram_when_enabled(): void
    {
        Queue::fake();
        Setting::setValue('alert_telegram_enabled', '1');
        ServerBackupTelegramSettings::setBotToken('123:abc');

        $agent = $this->makeAgent(['telegram_id' => '123456789']);
        $other = $this->makeAgent(['telegram_id' => '@someone']);

        app(NotificationService::class)->notify($agent, NotificationType::Info, 'T', 'B');
        app(NotificationService::class)->notify($other, NotificationType::Info, 'T', 'B');

        Queue::assertPushed(SendTelegramNotificationJob::class, 1);
    }
}
