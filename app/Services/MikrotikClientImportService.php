<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Package;
use App\Models\Server;
use App\Models\ServerInterface;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MikrotikClientImportService
{
    public function __construct(
        protected MikrotikService $mikrotikService,
        protected MikrotikProfileService $profileService,
        protected UserHierarchyService $hierarchyService,
    ) {}

    /**
     * @return array{
     *     profile_key: string,
     *     profile_name: string,
     *     protocol: string,
     *     service_type: ServiceType,
     *     clients: list<array<string, mixed>>,
     *     packages: list<array<string, mixed>>
     * }
     */
    public function fetchForAssignment(Server $server, string $profileKey): array
    {
        if (! $server->isMikrotik()) {
            throw new InvalidArgumentException(__('servers.import_mikrotik_only'));
        }

        set_time_limit(300);

        $profile = ServerInterface::query()
            ->where('server_id', $server->id)
            ->where('remote_key', $profileKey)
            ->firstOrFail();

        if ($profile->isWireguardProfile()) {
            throw new InvalidArgumentException(__('servers.import_wireguard_no_profile'));
        }

        if ($profile->isPppProfile()) {
            return $this->fetchPppClients($server, $profile);
        }

        throw new InvalidArgumentException(__('servers.import_invalid_profile'));
    }

    /**
     * @return array{
     *     profile_key: ?string,
     *     profile_name: string,
     *     protocol: string,
     *     service_type: ServiceType,
     *     clients: list<array<string, mixed>>,
     *     packages: list<array<string, mixed>>
     * }
     */
    public function fetchWireguardForAssignment(Server $server): array
    {
        if (! $server->isMikrotik()) {
            throw new InvalidArgumentException(__('servers.import_mikrotik_only'));
        }

        set_time_limit(300);

        $clients = [];
        $seenKeys = [];

        foreach ($this->mikrotikService->listWireguardInterfaces($server) as $row) {
            $interface = (string) ($row['name'] ?? '');
            if ($interface === '') {
                continue;
            }

            foreach ($this->mikrotikService->listWireguardPeers($server, $interface) as $peer) {
                $publicKey = (string) ($peer['public-key'] ?? '');
                if ($publicKey === '' || isset($seenKeys[$publicKey])) {
                    continue;
                }

                $seenKeys[$publicKey] = true;
                $clients[] = $this->normalizeWireguardImportClient($server, $peer, $publicKey);
            }
        }

        return [
            'profile_key' => null,
            'profile_name' => 'WireGuard',
            'protocol' => 'wireguard',
            'service_type' => ServiceType::Wireguard->value,
            'clients' => $clients,
            'packages' => $this->packagesFor(ServiceType::Wireguard),
        ];
    }

    /**
     * @param  list<array{uuid: string, owner_id: int, package_id: ?int, update_existing: bool}>  $assignments
     * @return array{created: int, updated: int, skipped: int, lines: list<string>, errors: list<string>}
     */
    public function import(
        Server $server,
        ?string $profileKey,
        ServiceType $serviceType,
        array $assignments
    ): array {
        $payload = $serviceType === ServiceType::Wireguard
            ? $this->fetchWireguardForAssignment($server)
            : $this->fetchForAssignment($server, (string) $profileKey);
        $clientsByUuid = collect($payload['clients'])->keyBy('uuid');

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $lines = [];
        $errors = [];

        foreach ($assignments as $assignment) {
            $client = $clientsByUuid->get($assignment['uuid']);

            if ($client === null) {
                $skipped++;
                continue;
            }

            $owner = User::query()->find($assignment['owner_id']);

            if ($owner === null || ! in_array($owner->role, [UserRole::Agent, UserRole::Seller], true)) {
                $errors[] = "مالک نامعتبر برای «{$client['label']}».";
                $skipped++;
                continue;
            }

            $existing = Account::query()
                ->withTrashed()
                ->where('server_id', $server->id)
                ->when(
                    $serviceType === ServiceType::Wireguard,
                    fn ($q) => $q->where('wireguard_public_key', $client['public_key'] ?? null),
                    fn ($q) => $q->where('remote_username', $client['username'] ?? null)
                )
                ->first();

            if ($existing !== null && ! $assignment['update_existing']) {
                $skipped++;
                $lines[] = "«{$client['label']}» از قبل وجود دارد — رد شد.";
                continue;
            }

            try {
                [$sellerId, $agentId] = $this->resolveOwnership($owner);
                $package = ! empty($assignment['package_id'])
                    ? Package::query()->find($assignment['package_id'])
                    : null;

                $data = [
                    'server_id' => $server->id,
                    'service_type' => $serviceType,
                    'remote_username' => $client['username'],
                    'owner_seller_id' => $sellerId,
                    'owner_agent_id' => $agentId,
                    'package_id' => $package?->id,
                    'data_limit_bytes' => $client['data_limit_bytes'] ?? null,
                    'data_used_bytes' => $client['data_used_bytes'] ?? 0,
                    'status' => ($client['disabled'] ?? false) ? AccountStatus::Disabled : AccountStatus::Active,
                    'client_email' => $client['username'].'@imported.local',
                    'portal_token' => Str::random((int) config('shahpanel.portal_token_length', 32)),
                    'last_sync_at' => now(),
                ];

                if ($serviceType !== ServiceType::Wireguard && $profileKey !== null && $profileKey !== '') {
                    $data['mikrotik_profile_key'] = $profileKey;
                }

                if ($serviceType === ServiceType::Wireguard) {
                    $data['wireguard_public_key'] = $client['public_key'] ?? null;
                    $data['wireguard_address'] = $client['allowed_address'] ?? null;
                    $interfaceName = (string) ($client['interface'] ?? '');
                    if ($interfaceName !== '') {
                        $data['mikrotik_profile_key'] = app(MikrotikProfileService::class)->wireguardRemoteKey($interfaceName);
                    }
                }

                if ($existing !== null) {
                    $existing->update($data);
                    $updated++;
                    $lines[] = "«{$client['label']}» بروزرسانی شد.";
                } else {
                    Account::query()->create($data);
                    $created++;
                    $lines[] = "«{$client['label']}» وارد شد.";
                }
            } catch (\Throwable $exception) {
                $errors[] = "«{$client['label']}»: ".$exception->getMessage();
            }
        }

        return compact('created', 'updated', 'skipped', 'lines', 'errors');
    }

    /**
     * @param  array<string, mixed>  $peer
     * @return array<string, mixed>
     */
    protected function normalizeWireguardImportClient(Server $server, array $peer, string $publicKey): array
    {
        $existing = Account::query()
            ->withTrashed()
            ->where('server_id', $server->id)
            ->where('wireguard_public_key', $publicKey)
            ->with(['ownerSeller', 'ownerAgent', 'package'])
            ->first();

        $username = (string) ($peer['name'] ?? $peer['comment'] ?? 'wg-'.substr($publicKey, 0, 8));
        $owner = $existing?->ownerSeller ?? $existing?->ownerAgent;

        return $this->normalizeImportClientRow([
            'uuid' => $publicKey,
            'label' => $username,
            'username' => $username,
            'email' => $username,
            'public_key' => $publicKey,
            'interface' => (string) ($peer['interface'] ?? ''),
            'allowed_address' => $peer['allowed-address'] ?? null,
            'disabled' => ($peer['disabled'] ?? 'false') === 'true',
            'data_used_bytes' => (int) ($peer['rx'] ?? $peer['rx-byte'] ?? 0) + (int) ($peer['tx'] ?? $peer['tx-byte'] ?? 0),
            'data_limit_bytes' => null,
            'data_remaining_bytes' => null,
            'expiry_at' => null,
            'existing_account_id' => $existing?->id,
            'existing_owner_id' => $existing?->owner_seller_id ?? $existing?->owner_agent_id,
            'existing_owner_name' => $owner?->full_name,
            'existing_package_id' => $existing?->package_id,
            'existing_package_name' => $existing?->package?->name,
            'suggested_package_id' => $existing?->package_id,
            'is_already_imported' => $existing !== null,
        ]);
    }

    /**
     * @return array{
     *     profile_key: string,
     *     profile_name: string,
     *     protocol: string,
     *     service_type: ServiceType,
     *     clients: list<array<string, mixed>>,
     *     packages: list<array<string, mixed>>
     * }
     */
    protected function fetchPppClients(Server $server, ServerInterface $profile): array
    {
        $profileName = $this->profileService->pppProfileName($profile);
        $secrets = $this->mikrotikService->listPppSecrets($server);
        $protocol = (string) ($profile->protocol ?: 'ppp');
        $serviceType = $this->profileService->mapPppServiceToServiceType($protocol);
        $packages = $this->packagesFor($serviceType);

        $existing = Account::query()
            ->withTrashed()
            ->where('server_id', $server->id)
            ->whereNotNull('remote_username')
            ->with(['ownerSeller', 'ownerAgent', 'package'])
            ->get()
            ->keyBy('remote_username');

        $clients = [];

        foreach ($secrets as $secret) {
            if ((string) ($secret['profile'] ?? '') !== $profileName) {
                continue;
            }

            $username = (string) ($secret['name'] ?? '');
            if ($username === '') {
                continue;
            }

            $secretService = (string) ($secret['service'] ?? 'any');
            $rowServiceType = $this->profileService->mapPppServiceToServiceType($secretService);
            $existingAccount = $existing->get($username);
            $owner = $existingAccount?->ownerSeller ?? $existingAccount?->ownerAgent;

            $clients[] = $this->normalizeImportClientRow([
                'uuid' => 'ppp:'.$username,
                'label' => $username,
                'username' => $username,
                'email' => $username,
                'service' => $secretService,
                'disabled' => ($secret['disabled'] ?? 'false') === 'true',
                'data_used_bytes' => (int) ($secret['bytes-in'] ?? 0) + (int) ($secret['bytes-out'] ?? 0),
                'data_limit_bytes' => null,
                'data_remaining_bytes' => null,
                'expiry_at' => null,
                'service_type' => $rowServiceType->value,
                'existing_account_id' => $existingAccount?->id,
                'existing_owner_id' => $existingAccount?->owner_seller_id ?? $existingAccount?->owner_agent_id,
                'existing_owner_name' => $owner?->full_name,
                'existing_package_id' => $existingAccount?->package_id,
                'existing_package_name' => $existingAccount?->package?->name,
                'suggested_package_id' => $existingAccount?->package_id,
                'is_already_imported' => $existingAccount !== null,
            ]);
        }

        return [
            'profile_key' => $profile->remote_key,
            'profile_name' => $profile->name,
            'protocol' => $protocol,
            'service_type' => $serviceType->value,
            'clients' => $clients,
            'packages' => $packages,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function packagesFor(ServiceType $serviceType): array
    {
        return Package::query()
            ->active()
            ->where('service_type', $serviceType)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Package $package) => [
                'id' => $package->id,
                'name' => $package->name,
                'data_limit_label' => $package->isUnlimited()
                    ? __('servers.unlimited')
                    : number_format((float) $package->data_limit_gb, 1).' GB',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{0: int, 1: int}
     */
    protected function resolveOwnership(User $owner): array
    {
        if ($owner->role === UserRole::Agent) {
            return [$owner->id, $owner->id];
        }

        $chain = $this->hierarchyService->resolveCommissionChain($owner);

        return [$owner->id, $chain['owner_agent_id']];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function normalizeImportClientRow(array $row): array
    {
        $label = (string) ($row['label'] ?? $row['username'] ?? $row['email'] ?? '');

        return array_merge([
            'email' => $label,
            'data_remaining_bytes' => null,
            'expiry_at' => null,
            'suggested_package_id' => null,
            'is_already_imported' => false,
        ], $row);
    }
}
