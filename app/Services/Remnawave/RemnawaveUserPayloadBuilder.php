<?php

namespace App\Services\Remnawave;

use App\Models\Package;
use App\Models\PackageDuration;
use Illuminate\Support\Carbon;

/**
 * Maps shahpanel package/duration/account fields to Remnawave UserCreate/UserModify payloads.
 *
 * Compatible with Remnawave API v2.x (uuid) and v3.x (numeric id / username).
 */
final class RemnawaveUserPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function buildCreate(
        Package $package,
        PackageDuration $duration,
        string $username,
        ?int $dataLimitBytes,
        ?Carbon $expiryAt,
    ): array {
        $payload = [
            'username' => $username,
            'status' => 'ACTIVE',
            'expireAt' => $this->resolveExpireAt($duration, $expiryAt),
            'trafficLimitBytes' => $dataLimitBytes !== null ? max(0, $dataLimitBytes) : 0,
            'trafficLimitStrategy' => $package->remnawaveTrafficStrategy(),
            'description' => 'shahpanel',
        ];

        $squads = $package->remnawaveSquadUuids();
        if ($squads !== []) {
            $payload['activeInternalSquads'] = $squads;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildUpdate(
        string $identifier,
        Package $package,
        PackageDuration $duration,
        ?int $dataLimitBytes,
        ?Carbon $expiryAt,
        bool $shouldEnable,
        ?string $username = null,
    ): array {
        $payload = RemnawaveUserIdentity::patchIdentity($identifier, $username);

        $payload['status'] = $shouldEnable ? 'ACTIVE' : 'DISABLED';
        $payload['trafficLimitBytes'] = $dataLimitBytes !== null ? max(0, $dataLimitBytes) : 0;
        $payload['trafficLimitStrategy'] = $package->remnawaveTrafficStrategy();

        // v3 rejects expireAt in the past; omit so disable/sync of expired users still works.
        $expireAt = $this->resolveExpireAt($duration, $expiryAt);
        if (Carbon::parse($expireAt)->isFuture()) {
            $payload['expireAt'] = $expireAt;
        }

        $squads = $package->remnawaveSquadUuids();
        if ($squads !== []) {
            $payload['activeInternalSquads'] = $squads;
        }

        return $payload;
    }

    /**
     * Remnawave requires a concrete expireAt; unlimited-time packages use a far-future date.
     */
    protected function resolveExpireAt(PackageDuration $duration, ?Carbon $expiryAt): string
    {
        $at = $duration->tier->hasNoTimeLimit()
            ? now()->addYears(50)
            : ($expiryAt ?? $duration->expiryFromNow() ?? now()->addYears(50));

        return $at->copy()->utc()->toIso8601ZuluString('millisecond');
    }
}
