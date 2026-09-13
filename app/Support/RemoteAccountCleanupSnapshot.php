<?php

namespace App\Support;

use App\Models\Account;

/**
 * Immutable remote cleanup payload captured before an account is soft-deleted.
 */
final class RemoteAccountCleanupSnapshot
{
    public function __construct(
        public readonly int $accountId,
        public readonly int $serverId,
        public readonly ?string $serviceType,
        public readonly ?string $remoteUsername,
        public readonly ?string $wireguardPublicKey,
        public readonly ?string $pasarguardUserId,
        public readonly ?string $remnawaveUuid,
        public readonly ?string $ciscoAsaUsername,
        public readonly ?string $sanaeiClientUuid,
        public readonly ?string $clientEmail,
        public readonly ?int $sanaeiInboundId,
    ) {}

    public static function fromAccount(Account $account): self
    {
        $account->loadMissing('server');

        return new self(
            accountId: (int) $account->id,
            serverId: (int) $account->server_id,
            serviceType: $account->service_type?->value,
            remoteUsername: $account->remote_username,
            wireguardPublicKey: $account->wireguard_public_key,
            pasarguardUserId: $account->pasarguard_user_id,
            remnawaveUuid: $account->remnawave_uuid,
            ciscoAsaUsername: $account->cisco_asa_username,
            sanaeiClientUuid: $account->sanaei_client_uuid,
            clientEmail: $account->client_email,
            sanaeiInboundId: $account->sanaei_inbound_id ? (int) $account->sanaei_inbound_id : null,
        );
    }
}
