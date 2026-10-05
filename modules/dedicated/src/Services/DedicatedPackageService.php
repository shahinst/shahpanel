<?php

namespace Modules\Dedicated\Services;

use App\Enums\PackageDurationTier;
use App\Enums\PackagePricingModel;
use App\Enums\ServerType;
use App\Enums\ServiceType;
use App\Models\Package;
use App\Models\Server;
use App\Models\ServerInterface;
use App\Models\User;
use App\Services\PackageService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Dedicated\Models\DedicatedServer;

/**
 * Packages a dedicated agent builds for their own server.
 *
 * They sit in the same packages table as the admin's, so creation, renewal,
 * the portal and subscriptions work unchanged; owner_agent_id is what makes
 * them the agent's. The panel takes nothing from these sales: the pricing
 * service charges the owner nothing and pays a seller's price straight to the
 * agent. Every check below exists so an agent can never put a package on a
 * server that is not theirs -- the form is input, not trusted data.
 */
class DedicatedPackageService
{
    public function __construct(protected PackageService $packages) {}

    /** @return list<ServiceType> */
    public static function serviceTypesFor(Server $server): array
    {
        return match ($server->type) {
            ServerType::Mikrotik => [ServiceType::Wireguard, ServiceType::Ppp, ServiceType::L2tp, ServiceType::Openvpn],
            ServerType::Sanaei => [ServiceType::SanaeiVless, ServiceType::SanaeiVmess, ServiceType::SanaeiTrojan],
            ServerType::Pasarguard => [ServiceType::Pasarguard],
            ServerType::Remnawave => [ServiceType::Remnawave],
            ServerType::CiscoAnyconnect => [ServiceType::CiscoAnyconnect],
            ServerType::Ocserv => [ServiceType::Ocserv],
            default => [],
        };
    }

    /**
     * What a package on this server may be pinned to: WireGuard interfaces
     * and PPP profiles on a MikroTik, inbounds on a Sanaei panel.
     *
     * @return array<string, string> remote key => label
     */
    public static function targetsFor(Server $server): array
    {
        return ServerInterface::query()->where('server_id', $server->id)->orderBy('name')->get()
            ->filter(fn (ServerInterface $i): bool => preg_match('/^(profile:(wg|ppp):|inbound:)/', (string) $i->remote_key) === 1)
            ->mapWithKeys(fn (ServerInterface $i): array => [(string) $i->remote_key => $i->name.' · '.explode(':', (string) $i->remote_key)[1]])
            ->all();
    }

    /**
     * @param  array{name: string, server_id: int, service_type: string, targets?: array, data_limit_gb: ?float, is_active: bool, durations: array}  $data
     */
    public function save(User $agent, array $data, ?Package $package = null): Package
    {
        if ($package !== null && (int) $package->owner_agent_id !== (int) $agent->id) {
            throw new InvalidArgumentException(__('dedicated::admin.not_your_package'));
        }

        $serverId = (int) $data['server_id'];

        if (! in_array($serverId, DedicatedServer::serverIdsOf($agent), true)) {
            throw new InvalidArgumentException(__('dedicated::admin.not_your_server'));
        }

        $server = Server::query()->findOrFail($serverId);
        $serviceType = ServiceType::tryFrom((string) $data['service_type']);

        if ($serviceType === null || ! in_array($serviceType, self::serviceTypesFor($server), true)) {
            throw new InvalidArgumentException(__('dedicated::admin.bad_service_type'));
        }

        // Only targets that really are on this server survive.
        $targets = array_values(array_intersect(array_map('strval', (array) ($data['targets'] ?? [])), array_keys(self::targetsFor($server))));
        $wantsProfile = $serviceType === ServiceType::Wireguard ? 'profile:wg:' : 'profile:ppp:';
        $profileKeys = $serviceType->isMikrotik() ? array_values(array_filter($targets, fn ($k) => str_starts_with($k, $wantsProfile))) : [];

        if ($serviceType->isMikrotik() && $profileKeys === []) {
            throw new InvalidArgumentException(__('dedicated::admin.profile_required'));
        }

        $inboundIds = $serviceType->isSanaei()
            ? array_values(array_map(fn ($k) => (int) substr($k, 8), array_filter($targets, fn ($k) => str_starts_with($k, 'inbound:'))))
            : [];

        $durations = $this->normalizedDurations($data['durations'] ?? []);

        if (! collect($durations)->contains(fn ($r) => $r['is_enabled'])) {
            throw new InvalidArgumentException(__('dedicated::admin.duration_required'));
        }

        return DB::transaction(function () use ($agent, $data, $package, $server, $serviceType, $profileKeys, $inboundIds, $durations): Package {
            $attributes = [
                'name' => $data['name'],
                'owner_agent_id' => $agent->id,
                'inbound_allocation_id' => null,
                'service_type' => $serviceType,
                'pricing_model' => PackagePricingModel::Fixed->value,
                'default_server_id' => $server->id,
                'mikrotik_profile_keys' => $profileKeys === [] ? null : $profileKeys,
                'sanaei_inbound_ids' => $inboundIds === [] ? null : $inboundIds,
                'data_limit_gb' => ($data['data_limit_gb'] ?? null) > 0 ? $data['data_limit_gb'] : null,
                'is_active' => (bool) $data['is_active'],
                'package_category_id' => null,
                'duration_days' => 30,
                'base_price' => 0,
            ];

            $package = $package === null ? Package::query()->create($attributes) : tap($package)->update($attributes);

            $this->packages->syncDurations($package, $durations);
            $this->packages->syncServers($package, [$server->id], $serviceType);

            return $package->fresh(['durations']);
        });
    }

    public function delete(User $agent, Package $package): void
    {
        if ((int) $package->owner_agent_id !== (int) $agent->id || ! $package->isDedicatedPackage()) {
            throw new InvalidArgumentException(__('dedicated::admin.not_your_package'));
        }

        // Accounts already sold keep working, as with the admin's packages.
        $this->packages->deletePreservingAccounts($package);
    }

    /** @return array<string, array{is_enabled: bool, price: float}> */
    protected function normalizedDurations(array $input): array
    {
        $rows = [];

        foreach (PackageDurationTier::cases() as $tier) {
            $row = $input[$tier->value] ?? [];
            $enabled = filter_var($row['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $price = (float) western_digits((string) ($row['price'] ?? 0));

            if ($enabled && $price < 0) {
                throw new InvalidArgumentException(__('packages.invalid_duration_price', ['tier' => $tier->label()]));
            }

            $rows[$tier->value] = ['is_enabled' => $enabled, 'price' => $enabled ? $price : 0];
        }

        return $rows;
    }
}
