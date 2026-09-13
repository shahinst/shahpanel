<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Account;

/**
 * One shape for an account everywhere the API returns one.
 */
class AccountTransformer
{
    /** @return array<string, mixed> */
    public static function make(Account $account, bool $detailed = false): array
    {
        $unlimited = $account->isUnlimited();
        $limitBytes = $unlimited ? null : (int) $account->data_limit_bytes;
        $usedBytes = (int) $account->data_used_bytes;

        $payload = [
            'id' => $account->id,
            'username' => $account->remote_username,
            'label' => $account->display_label,
            'service_type' => $account->service_type->value,
            'status' => $account->status->value,
            'is_expired' => $account->isExpired(),
            'is_exhausted' => $account->isQuotaExhausted(),
            'is_refunded' => $account->isRefunded(),
            'expires_at' => optional($account->expiry_at)->toIso8601String(),
            'created_at' => optional($account->created_at)->toIso8601String(),

            'data' => [
                'unlimited' => $unlimited,
                'limit_bytes' => $limitBytes,
                'used_bytes' => $usedBytes,
                'remaining_bytes' => $limitBytes !== null ? max(0, $limitBytes - $usedBytes) : null,
                'limit_gb' => $limitBytes !== null ? round($limitBytes / (1024 ** 3), 2) : null,
                'used_gb' => round($usedBytes / (1024 ** 3), 2),
                'purchased_gb' => $account->purchased_data_gb !== null
                    ? (float) $account->purchased_data_gb
                    : null,
                'percent_used' => ($limitBytes !== null && $limitBytes > 0)
                    ? min(100, round($usedBytes / $limitBytes * 100, 1))
                    : null,
            ],

            'package' => $account->relationLoaded('package') && $account->package !== null ? [
                'id' => $account->package->id,
                'name' => $account->package->name,
                'is_elastic' => $account->package->isElastic(),
            ] : ['id' => $account->package_id, 'name' => null, 'is_elastic' => null],

            'duration' => $account->relationLoaded('packageDuration') && $account->packageDuration !== null ? [
                'id' => $account->packageDuration->id,
                'days' => $account->packageDuration->duration_days ?? null,
            ] : ['id' => $account->package_duration_id, 'days' => null],

            'server' => $account->relationLoaded('server') && $account->server !== null ? [
                'id' => $account->server->id,
                'name' => $account->server->name,
            ] : ['id' => $account->server_id, 'name' => null],

            'owner' => [
                'seller_id' => $account->owner_seller_id,
                'agent_id' => $account->owner_agent_id,
                'seller_username' => $account->relationLoaded('ownerSeller') && $account->ownerSeller !== null
                    ? $account->ownerSeller->username
                    : null,
            ],
        ];

        if (! $detailed) {
            return $payload;
        }

        $payload['client'] = [
            'user_id' => $account->client_user_id,
            'email' => $account->client_email,
            'username' => $account->relationLoaded('clientUser') && $account->clientUser !== null
                ? $account->clientUser->username
                : null,
        ];

        $payload['portal'] = [
            'token' => $account->portal_token,
            'expires_at' => optional($account->portal_token_expires_at)->toIso8601String(),
        ];

        $payload['last_sync_at'] = optional($account->last_sync_at)->toIso8601String();

        return $payload;
    }
}
