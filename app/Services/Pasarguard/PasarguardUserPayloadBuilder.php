<?php

namespace App\Services\Pasarguard;

use App\Enums\PasarguardExpiryActivation;
use App\Models\Package;
use App\Models\PackageDuration;
use Illuminate\Support\Carbon;

/**
 * Maps vpnpanel package/duration/account fields to PasarGuard UserCreate/UserModify payloads.
 */
final class PasarguardUserPayloadBuilder
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
        $activation = $this->resolveActivation($package);
        $durationSeconds = $this->durationSeconds($duration);

        $payload = [
            'username' => $username,
            'data_limit' => $dataLimitBytes,
            'data_limit_reset_strategy' => 'no_reset',
            'group_ids' => [(int) ($package->pasarguard_group_id ?? 1)],
            'hwid_limit' => (int) ($package->pasarguard_hwid_limit ?? 0),
        ];

        if ($activation->usesOnHold()) {
            $payload['status'] = 'on_hold';
            $payload['expire'] = null;
            $payload['on_hold_timeout'] = null;
            if ($durationSeconds !== null) {
                $payload['on_hold_expire_duration'] = $durationSeconds;
            }
        } else {
            $payload['status'] = 'active';
            $payload['on_hold_expire_duration'] = null;
            $payload['expire'] = $this->resolveExpireTimestamp($duration, $expiryAt);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildUpdate(
        Package $package,
        PackageDuration $duration,
        ?int $dataLimitBytes,
        ?Carbon $expiryAt,
        bool $shouldEnable,
    ): array {
        $activation = $this->resolveActivation($package);
        $durationSeconds = $this->durationSeconds($duration);

        $payload = [
            'data_limit' => $dataLimitBytes,
            'data_limit_reset_strategy' => 'no_reset',
            'group_ids' => [(int) ($package->pasarguard_group_id ?? 1)],
            'hwid_limit' => (int) ($package->pasarguard_hwid_limit ?? 0),
        ];

        if (! $shouldEnable) {
            $payload['status'] = 'disabled';

            return $payload;
        }

        if ($activation->usesOnHold()) {
            $payload['status'] = 'on_hold';
            $payload['expire'] = null;
            if ($durationSeconds !== null) {
                $payload['on_hold_expire_duration'] = $durationSeconds;
            }
        } else {
            $payload['status'] = 'active';
            $payload['on_hold_expire_duration'] = null;
            $payload['expire'] = $this->resolveExpireTimestamp($duration, $expiryAt);
        }

        return $payload;
    }

    protected function resolveActivation(Package $package): PasarguardExpiryActivation
    {
        $value = $package->pasarguard_expiry_activation;

        if ($value instanceof PasarguardExpiryActivation) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            return PasarguardExpiryActivation::tryFrom($value) ?? PasarguardExpiryActivation::FromCreation;
        }

        return PasarguardExpiryActivation::FromCreation;
    }

    protected function durationSeconds(PackageDuration $duration): ?int
    {
        if ($duration->tier->hasNoTimeLimit()) {
            return null;
        }

        $hours = $duration->tier->durationHours();
        if ($hours === null) {
            return null;
        }

        return max(3600, (int) $hours * 3600);
    }

    protected function resolveExpireTimestamp(PackageDuration $duration, ?Carbon $expiryAt): ?int
    {
        if ($duration->tier->hasNoTimeLimit()) {
            return null;
        }

        $at = $expiryAt ?? $duration->expiryFromNow();

        return $at !== null ? (int) $at->getTimestamp() : null;
    }
}
