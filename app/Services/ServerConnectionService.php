<?php

namespace App\Services;

use App\Enums\ServerHealthStatus;
use App\Models\Server;

class ServerConnectionService
{
    public function __construct(
        protected MikrotikService $mikrotikService,
        protected SanaeiService $sanaeiService,
        protected PasarguardService $pasarguardService,
        protected RemnawaveService $remnawaveService,
        protected CiscoAnyconnectService $ciscoAnyconnectService,
        protected OcservService $ocservService,
    ) {}

    /**
     * @return array{ok: bool, message: string, details: array<string, mixed>}
     */
    public function test(Server $server): array
    {
        $result = match (true) {
            $server->isMikrotik() => $this->mikrotikService->testConnectionDetails($server),
            $server->isPasarguard() => $this->pasarguardService->testConnectionDetails($server),
            $server->isRemnawave() => $this->remnawaveService->testConnectionDetails($server),
            $server->isCiscoAnyconnect() => $this->ciscoAnyconnectService->testConnectionDetails($server),
            $server->isOcserv() => $this->ocservService->testConnectionDetails($server),
            $server->isSanaei() => $this->sanaeiService->testConnectionDetails($server),
            default => [
                'ok' => false,
                'message' => __('services.server_type_unsupported', ['type' => $server->type?->label() ?? __('services.unknown')]),
                'error' => 'server_type_unsupported',
            ],
        };

        $healthPayload = [
            'last_health_check_at' => now(),
            'last_health_status' => $result['ok']
                ? ServerHealthStatus::Healthy
                : ServerHealthStatus::Unreachable,
        ];

        $server->update($healthPayload);
        $server->refresh();

        return [
            'ok' => $result['ok'],
            'message' => $result['message'],
            'details' => array_diff_key($result, array_flip(['ok', 'message'])),
        ];
    }
}
