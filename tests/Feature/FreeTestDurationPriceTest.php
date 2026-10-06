<?php

namespace Tests\Feature;

use App\Models\PackageDuration;
use App\Services\UserPackagePricingService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Concerns\CreatesPanelData;
use Tests\TestCase;

class FreeTestDurationPriceTest extends TestCase
{
    use CreatesPanelData;
    use RefreshDatabase;

    public function test_a_test_duration_priced_at_zero_is_free_for_an_agent(): void
    {
        $agent = $this->makeAgent();
        [$package] = $this->makePackage();
        $this->assignPackage($agent, $package);
        $hour = PackageDuration::query()->forceCreate(['package_id' => $package->id, 'tier' => '1h', 'price' => 0, 'is_enabled' => true]);

        $this->assertSame('0.00', app(UserPackagePricingService::class)->requireWholesalePrice($agent, $hour->fresh()));
        $this->assertSame(now()->addHour()->timestamp, $hour->fresh()->expiryFromNow()->timestamp);
    }

    public function test_a_paid_duration_left_at_zero_is_still_refused(): void
    {
        $agent = $this->makeAgent();
        [$package] = $this->makePackage();
        $this->assignPackage($agent, $package);
        $month = PackageDuration::query()->forceCreate(['package_id' => $package->id, 'tier' => '3m', 'price' => 0, 'is_enabled' => true]);

        $this->expectException(InvalidArgumentException::class);
        app(UserPackagePricingService::class)->requireWholesalePrice($agent, $month->fresh());
    }

    public function test_expired_accounts_are_checked_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'accounts:check-expiry'));

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
    }
}
