<?php

namespace App\Services;

use App\Models\Account;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Live traffic snapshot for customer portal — always read Pasarguard/Remnawave API directly.
 */
class PortalPanelTrafficService
{
    public function __construct(
        protected PasarguardService $pasarguardService,
        protected RemnawaveService $remnawaveService,
        protected AccountService $accountService,
    ) {}

    /**
     * @return array{raw: array<string, mixed>, normalized: array<string, int|null>}|null
     */
    public function fetchLiveTraffic(Account $account): ?array
    {
        $account->loadMissing('server');
        $server = $account->server;

        if ($server === null || ! $this->accountService->readsUsageFromRemotePanel($account)) {
            return null;
        }

        try {
            if ($server->isPasarguard() || $account->service_type->isPasarguard() || $account->pasarguard_user_id) {
                $remote = $this->pasarguardService->getUser($server, $account->remote_username);
                $normalized = $this->pasarguardService->normalizeTrafficSnapshot($remote);

                return [
                    'raw' => $remote,
                    'normalized' => $normalized,
                ];
            }

            if ($server->isRemnawave() || $account->service_type->isRemnawave() || $account->remnawave_uuid) {
                $remote = $account->remnawave_uuid
                    ? $this->remnawaveService->getUserByUuid($server, $account->remnawave_uuid)
                    : $this->remnawaveService->getUser($server, $account->remote_username);

                if ($remote === null) {
                    return null;
                }

                $normalized = $this->remnawaveService->normalizeTrafficSnapshot($remote);

                return [
                    'raw' => $remote,
                    'normalized' => $normalized,
                ];
            }
        } catch (Throwable $exception) {
            Log::channel('pasarguard')->warning('Portal panel traffic fetch failed', [
                'account_id' => $account->id,
                'username' => $account->remote_username,
                'error' => $exception->getMessage(),
            ]);
        }

        return null;
    }
}
