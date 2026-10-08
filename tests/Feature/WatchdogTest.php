<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Services\ServerBackup\ServerBackupTelegramNotifier;
use App\Support\ServerBackupTelegramSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Watchdog\Services\WatchdogService;
use Modules\Watchdog\WatchdogServiceProvider;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class WatchdogTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    /** Hosts the fake network answers on. */
    public array $up = [];

    public float $free = 50;

    public array $health = [];

    protected function setUp(): void
    {
        parent::setUp();

        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'Modules\\Watchdog\\')) {
                $file = base_path('modules/watchdog/src/'.str_replace('\\', '/', substr($class, strlen('Modules\\Watchdog\\'))).'.php');

                if (is_file($file)) {
                    require_once $file;
                }
            }
        });
        $this->app->register(WatchdogServiceProvider::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        config(['app.url' => 'http://panel.example']);
        ServerBackupTelegramSettings::setBotToken('1:watch');
        ServerBackupTelegramSettings::setChatId('99');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $test = $this;
        $this->app->bind(WatchdogService::class, fn ($app) => new class($app->make(ServerBackupTelegramNotifier::class), $test) extends WatchdogService
        {
            public function __construct(ServerBackupTelegramNotifier $telegram, private WatchdogTest $test)
            {
                parent::__construct($telegram);
            }

            protected function reachable(string $host, int $port): bool
            {
                return in_array($host, $this->test->up, true);
            }

            protected function diskSpace(): array
            {
                return [$this->test->free, 100];
            }

            protected function certificateExpiry(string $host, int $port): ?int
            {
                return time() + 5 * 86400;
            }

            protected function ocservHealth(Server $server): array
            {
                return $this->test->health;
            }
        });
    }

    private function alerts(string $needle): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/sendMessage') && str_contains((string) $r['text'], $needle))->count();
    }

    public function test_a_server_counts_as_down_after_two_failures_and_its_return_is_announced(): void
    {
        $this->makeServer('mikrotik', ['name' => 'Router A', 'host' => '198.51.100.7', 'port' => 8729]);
        $watchdog = app(WatchdogService::class);

        $watchdog->run();
        $this->assertSame(0, $this->alerts('Router A'));

        $watchdog->run();
        $watchdog->run();
        $this->assertSame(1, $this->alerts('Router A'));

        $this->up = ['198.51.100.7'];
        $watchdog->run();
        $this->assertSame(2, $this->alerts('Router A'));
        $this->assertSame(1, $this->alerts('✅'));
    }

    public function test_low_disk_expiring_certificates_and_a_full_pool_are_announced(): void
    {
        $this->up = ['203.0.113.5'];
        $this->free = 4;
        $this->health = ['ok' => true, 'pool_size' => 100, 'sessions' => 95];
        $this->makeServer('ocserv', ['name' => 'AC 1', 'host' => '203.0.113.5', 'port' => 9443, 'ocserv_vpn_address' => 'vpn.example']);

        $checks = collect(app(WatchdogService::class)->run())->keyBy('id');

        $this->assertFalse($checks['disk']['ok']);
        $this->assertFalse($checks['cert:vpn.example:443']['ok']);
        $this->assertFalse($checks['pool:'.Server::query()->value('id')]['ok']);
        $this->assertTrue($checks['server:'.Server::query()->value('id')]['ok']);
        $this->assertSame(3, $this->alerts('⚠️'));
    }

    public function test_the_admin_page_shows_the_last_check(): void
    {
        $this->up = [];
        app(WatchdogService::class)->run();

        $this->actingAs($this->makeAdmin())
            ->get(route('admin.watchdog.index'))
            ->assertOk()
            ->assertSee(__('watchdog::watchdog.label_disk'));
    }
}
