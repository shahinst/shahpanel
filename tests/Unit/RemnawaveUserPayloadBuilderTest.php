<?php

namespace Tests\Unit;

use App\Enums\PackageDurationTier;
use App\Enums\PackagePricingModel;
use App\Enums\ServiceType;
use App\Models\Package;
use App\Models\PackageDuration;
use App\Services\Remnawave\RemnawaveUserPayloadBuilder;
use Tests\TestCase;

class RemnawaveUserPayloadBuilderTest extends TestCase
{
    public function test_build_create_includes_squads_and_traffic(): void
    {
        $package = new Package([
            'service_type' => ServiceType::Remnawave,
            'remnawave_squads' => ['squad-uuid-1', 'squad-uuid-2'],
            'remnawave_traffic_strategy' => 'MONTH',
            'data_limit_gb' => 50,
        ]);

        $duration = new PackageDuration([
            'tier' => PackageDurationTier::OneMonth,
            'days' => 30,
            'is_enabled' => true,
        ]);

        $builder = new RemnawaveUserPayloadBuilder();
        $payload = $builder->buildCreate(
            $package,
            $duration,
            'rw-testuser',
            50 * 1024 ** 3,
            now()->addDays(30),
        );

        $this->assertSame('rw-testuser', $payload['username']);
        $this->assertSame('ACTIVE', $payload['status']);
        $this->assertSame('MONTH', $payload['trafficLimitStrategy']);
        $this->assertSame(50 * 1024 ** 3, $payload['trafficLimitBytes']);
        $this->assertSame(['squad-uuid-1', 'squad-uuid-2'], $payload['activeInternalSquads']);
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}T.+Z$/', $payload['expireAt']);
    }

    public function test_build_update_includes_uuid_and_disabled_status(): void
    {
        $package = new Package([
            'service_type' => ServiceType::Remnawave,
            'remnawave_squads' => ['squad-a'],
            'remnawave_traffic_strategy' => 'NO_RESET',
        ]);

        $duration = new PackageDuration([
            'tier' => PackageDurationTier::OneMonth,
            'days' => 30,
        ]);

        $builder = new RemnawaveUserPayloadBuilder();
        $payload = $builder->buildUpdate(
            'user-uuid-99',
            $package,
            $duration,
            null,
            now()->addDays(7),
            false,
        );

        $this->assertSame('user-uuid-99', $payload['uuid']);
        $this->assertSame('DISABLED', $payload['status']);
        $this->assertSame(['squad-a'], $payload['activeInternalSquads']);
    }
}
