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
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SanaeiClientImportService
{
    public function __construct(
        protected SanaeiService $sanaeiService,
        protected UserHierarchyService $hierarchyService,
    ) {}

    /**
     * @return array{
     *     inbound_id: int,
     *     inbound_name: string,
     *     protocol: string,
     *     service_type: ServiceType,
     *     clients: list<array<string, mixed>>
     * }
     */
    public function fetchForAssignment(Server $server, int $inboundId): array
    {
        if (! $server->isSanaei()) {
            throw new InvalidArgumentException('سینک کلاینت فقط برای سرور Sanaei است.');
        }

        set_time_limit(300);

        $iface = ServerInterface::query()
            ->where('server_id', $server->id)
            ->where('remote_key', 'inbound:'.$inboundId)
            ->first();

        $inbound = $this->sanaeiService->getInbound($server, $inboundId);
        $protocol = (string) ($inbound['protocol'] ?? $iface?->protocol ?? 'vless');
        $serviceType = $this->sanaeiService->serviceTypeFromProtocol($protocol);

        $clients = $this->sanaeiService->listClientsFromInbound($server, $inboundId);
        $packages = Package::query()
            ->active()
            ->where('service_type', $serviceType)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $existingByUuid = Account::query()
            ->withTrashed()
            ->where('server_id', $server->id)
            ->whereIn('sanaei_client_uuid', array_column($clients, 'uuid'))
            ->with(['ownerSeller', 'ownerAgent', 'package'])
            ->get()
            ->keyBy('sanaei_client_uuid');

        $rows = [];

        foreach ($clients as $client) {
            $existing = $existingByUuid->get($client['uuid']);
            $owner = $existing?->ownerSeller ?? $existing?->ownerAgent;
            $matchedPackage = $this->matchPackageByTraffic($packages, $client['data_limit_bytes'] ?? null);
            $packageId = $existing?->package_id ?? $matchedPackage?->id;
            $packageName = $existing?->package?->name ?? $matchedPackage?->name;

            $rows[] = array_merge($client, [
                'existing_account_id' => $existing?->id,
                'existing_owner_id' => $existing?->owner_seller_id ?? $existing?->owner_agent_id,
                'existing_owner_name' => $owner?->full_name,
                'existing_package_id' => $packageId,
                'existing_package_name' => $packageName,
                'suggested_package_id' => $packageId,
                'is_already_imported' => $existing !== null,
            ]);
        }

        return [
            'inbound_id' => $inboundId,
            'inbound_name' => (string) ($inbound['remark'] ?? $inbound['tag'] ?? $iface?->name ?? "Inbound {$inboundId}"),
            'protocol' => $protocol,
            'service_type' => $serviceType,
            'clients' => $rows,
            'packages' => $packages->map(fn (Package $package): array => [
                'id' => $package->id,
                'name' => $package->name,
                'data_limit_label' => $package->isUnlimited()
                    ? __('servers.unlimited')
                    : persian_digits(rtrim(rtrim(number_format((float) $package->data_limit_gb, 2, '.', ''), '0'), '.')).' GB',
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, Package>  $packages
     */
    protected function matchPackageByTraffic(Collection $packages, ?int $dataLimitBytes): ?Package
    {
        if ($packages->isEmpty()) {
            return null;
        }

        if ($dataLimitBytes === null || $dataLimitBytes <= 0) {
            return $packages->first(fn (Package $package): bool => $package->isUnlimited())
                ?? $packages->first();
        }

        $gigabytes = $dataLimitBytes / (1024 ** 3);

        $exact = $packages->first(function (Package $package) use ($gigabytes): bool {
            if ($package->isUnlimited()) {
                return false;
            }

            return abs((float) $package->data_limit_gb - $gigabytes) < 0.05;
        });

        if ($exact !== null) {
            return $exact;
        }

        return $packages
            ->filter(fn (Package $package): bool => ! $package->isUnlimited())
            ->sortBy(fn (Package $package): float => abs((float) $package->data_limit_gb - $gigabytes))
            ->first()
            ?? $packages->first();
    }

    /**
     * @param  list<array{uuid: string, owner_id: int, package_id?: int|null, update_existing?: bool}>  $assignments
     * @return array{created: int, updated: int, skipped: int, errors: list<string>, lines: list<string>}
     */
    public function import(Server $server, int $inboundId, ServiceType $serviceType, array $assignments): array
    {
        $remoteClients = collect($this->sanaeiService->listClientsFromInbound($server, $inboundId))
            ->keyBy('uuid');

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $lines = [];

        foreach ($assignments as $assignment) {
            $uuid = (string) ($assignment['uuid'] ?? '');
            $ownerId = (int) ($assignment['owner_id'] ?? 0);
            $packageId = isset($assignment['package_id']) && $assignment['package_id'] !== ''
                ? (int) $assignment['package_id']
                : null;
            $updateExisting = (bool) ($assignment['update_existing'] ?? false);

            if ($uuid === '' || $ownerId <= 0) {
                continue;
            }

            $remote = $remoteClients->get($uuid);
            if ($remote === null) {
                $errors[] = "کلاینت {$uuid} روی inbound یافت نشد.";
                continue;
            }

            $owner = User::query()->find($ownerId);
            if ($owner === null || ! in_array($owner->role, [UserRole::Agent, UserRole::Seller], true)) {
                $errors[] = "مالک نامعتبر برای {$remote['email']}.";
                continue;
            }

            try {
                [$sellerId, $agentId] = $this->resolveOwnership($owner);
            } catch (InvalidArgumentException $exception) {
                $errors[] = "{$remote['email']}: {$exception->getMessage()}";
                continue;
            }

            $existing = Account::query()
                ->where('server_id', $server->id)
                ->where('sanaei_client_uuid', $uuid)
                ->first();

            if ($existing !== null) {
                if ($updateExisting) {
                    $existing->update([
                        'owner_seller_id' => $sellerId,
                        'owner_agent_id' => $agentId,
                        'package_id' => $packageId,
                        'data_limit_bytes' => $remote['data_limit_bytes'],
                        'data_used_bytes' => $remote['data_used_bytes'],
                        'expiry_at' => $remote['expiry_at'],
                        'status' => ($remote['enable'] ?? true) ? AccountStatus::Active : AccountStatus::Disabled,
                        'last_sync_at' => now(),
                    ]);
                    $updated++;
                    $lines[] = "مالک «{$remote['email']}» بروزرسانی شد.";
                } else {
                    $skipped++;
                    $lines[] = "«{$remote['email']}» قبلاً وارد شده — رد شد.";
                }

                continue;
            }

            $username = $this->uniqueUsername($serviceType, $remote['email'], $uuid);

            Account::query()->create([
                'owner_seller_id' => $sellerId,
                'owner_agent_id' => $agentId,
                'package_id' => $packageId,
                'package_duration_id' => null,
                'server_id' => $server->id,
                'service_type' => $serviceType,
                'remote_username' => $username,
                'remote_password_enc' => null,
                'sanaei_inbound_id' => $inboundId,
                'sanaei_client_uuid' => $uuid,
                'client_email' => $remote['email'],
                'portal_token' => Str::random((int) config('vpnpanel.portal_token_length', 32)),
                'data_limit_bytes' => $remote['data_limit_bytes'],
                'data_used_bytes' => $remote['data_used_bytes'],
                'expiry_at' => $remote['expiry_at'],
                'status' => ($remote['enable'] ?? true) ? AccountStatus::Active : AccountStatus::Disabled,
                'last_sync_at' => now(),
            ]);

            $created++;
            $lines[] = "«{$remote['email']}» وارد شد.";
        }

        return compact('created', 'updated', 'skipped', 'errors', 'lines');
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

    protected function uniqueUsername(ServiceType $serviceType, string $email, string $uuid): string
    {
        $local = Str::before($email, '@');
        $local = preg_replace('/[^a-zA-Z0-9_-]/', '', $local) ?: '';
        $base = Str::lower($serviceType->usernamePrefix().($local !== '' ? $local : Str::substr($uuid, 0, 8)));

        if (! Account::query()->where('remote_username', $base)->exists()) {
            return $base;
        }

        for ($i = 0; $i < 15; $i++) {
            $candidate = $base.'-'.Str::lower(Str::random(4));
            if (! Account::query()->where('remote_username', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $serviceType->usernamePrefix().Str::lower(Str::random(10));
    }
}
