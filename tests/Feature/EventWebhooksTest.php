<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Webhooks\Services\EventWebhookService;
use Modules\Webhooks\WebhooksServiceProvider;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class EventWebhooksTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    /** What the receiver answers; one fake for the whole test, as a later Http::fake would be shadowed. */
    private int $receiverStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class, 'Modules\\Webhooks\\')) {
                $file = base_path('modules/webhooks/src/'.str_replace('\\', '/', substr($class, strlen('Modules\\Webhooks\\'))).'.php');

                if (is_file($file)) {
                    require_once $file;
                }
            }
        });
        $this->app->register(WebhooksServiceProvider::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        Http::fake(['hooks.example/*' => fn () => Http::response('ok', $this->receiverStatus)]);
    }

    /**
     * @return list<string>
     */
    private function delivered(): array
    {
        return Http::recorded(fn (Request $r) => str_starts_with($r->url(), 'https://hooks.example/'))
            ->map(fn (array $pair) => $pair[0]['event'])->values()->all();
    }

    public function test_account_lifecycle_events_are_delivered_signed(): void
    {
        $this->actingAs($admin = $this->makeAdmin())
            ->post(route('admin.webhooks.update'), ['url' => 'https://hooks.example/in', 'events' => EventWebhookService::EVENTS])
            ->assertSessionHasNoErrors();
        $secret = EventWebhookService::secret();
        $this->assertSame(48, strlen($secret));

        [$package] = $this->makePackage();
        $account = $this->makeAccount($this->makeAgent(), $this->makeServer(), ['package_id' => $package->id, 'expiry_at' => now()->addDays(3)]);
        $account->update(['expiry_at' => now()->addDays(33)]);
        $account->update(['status' => AccountStatus::Disabled]);
        $account->update(['status' => AccountStatus::Expired]);
        $account->update(['display_label' => 'renamed']);
        $account->delete();

        $this->assertSame(['account.created', 'account.renewed', 'account.status_changed', 'account.expired', 'account.deleted'], $this->delivered());

        Http::assertSent(function (Request $r) use ($secret, $account) {
            return $r['event'] === 'account.expired'
                && $r['account']['id'] === $account->id
                && $r['account']['previous_status'] === 'disabled'
                && $r->header('X-ShahPanel-Signature')[0] === 'sha256='.hash_hmac('sha256', $r->body(), $secret);
        });
    }

    public function test_only_chosen_events_go_out_and_http_is_refused(): void
    {
        $this->actingAs($this->makeAdmin())
            ->post(route('admin.webhooks.update'), ['url' => 'http://hooks.example/in', 'events' => ['account.created']])
            ->assertSessionHasErrors('url');

        $this->post(route('admin.webhooks.update'), ['url' => 'https://hooks.example/in', 'events' => ['account.deleted']]);
        [$package] = $this->makePackage();
        $account = $this->makeAccount($this->makeAgent(), $this->makeServer(), ['package_id' => $package->id]);
        $account->update(['expiry_at' => now()->addYear()]);
        $account->delete();

        $this->assertSame(['account.deleted'], $this->delivered());
    }

    public function test_the_test_button_reports_the_receiver_answer(): void
    {
        $this->receiverStatus = 500;
        $this->actingAs($this->makeAdmin())
            ->post(route('admin.webhooks.update'), ['url' => 'https://hooks.example/in', 'events' => EventWebhookService::EVENTS]);

        $this->post(route('admin.webhooks.test'))->assertSessionHasErrors('url');
        $this->get(route('admin.webhooks.index'))->assertOk()->assertSee('HTTP 500');
    }
}
