<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Package;
use App\Models\PackageDuration;
use App\Models\User;
use InvalidArgumentException;

class TestPackageGuardService
{
    public function assertClientCanReceiveTest(User $client, Package $package, PackageDuration $duration): void
    {
        if (! $duration->tier->isTest()) {
            return;
        }

        $alreadyUsed = Account::query()
            ->where('client_user_id', $client->id)
            ->where('package_id', $package->id)
            ->whereHas('packageDuration', fn ($query) => $query->whereIn('tier', $this->testTierValues()))
            ->exists();

        if ($alreadyUsed) {
            throw new InvalidArgumentException(__('packages.test_already_used_for_client'));
        }
    }

    /**
     * @return list<string>
     */
    protected function testTierValues(): array
    {
        return collect(\App\Enums\PackageDurationTier::cases())
            ->filter(fn (\App\Enums\PackageDurationTier $tier): bool => $tier->isTest())
            ->map(fn (\App\Enums\PackageDurationTier $tier): string => $tier->value)
            ->values()
            ->all();
    }
}
