<?php

namespace App\Services\InboundReseller;

use App\Enums\PackageDurationTier;
use App\Enums\PackagePricingModel;
use App\Models\InboundAllocation;
use App\Models\Package;
use App\Models\ServerInterface;
use App\Models\User;
use App\Services\PackageService;
use App\Services\SanaeiService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Packages an inbound reseller builds on their own allocation. They live in
 * the same packages table as the admin's (so creation, renewal, portal links
 * and subscriptions all work unchanged) but belong to the agent: only they and
 * their sellers see them, and their price is what the agent charges sellers.
 */
class AgentPackageService
{
    public function __construct(
        protected PackageService $packages,
        protected SanaeiService $sanaei,
    ) {}

    /**
     * @param  array{name: string, data_limit_gb: ?float, sanaei_limit_ip: ?int, is_active: bool, durations: array<string, array{is_enabled?: mixed, price?: mixed}>}  $data
     */
    public function save(User $agent, InboundAllocation $allocation, array $data, ?Package $package = null): Package
    {
        if ((int) $allocation->agent_user_id !== (int) $agent->id) {
            throw new InvalidArgumentException(__('inbound_resellers.not_your_allocation'));
        }

        if ($package !== null && (int) $package->owner_agent_id !== (int) $agent->id) {
            throw new InvalidArgumentException(__('inbound_resellers.not_your_package'));
        }

        $enabledTiers = collect($data['durations'] ?? [])
            ->filter(fn ($row): bool => filter_var($row['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN));

        if ($enabledTiers->isEmpty()) {
            throw new InvalidArgumentException(__('inbound_resellers.duration_required'));
        }

        $serviceType = $this->serviceTypeFor($allocation);

        return DB::transaction(function () use ($agent, $allocation, $data, $package, $serviceType): Package {
            $attributes = [
                'name' => $data['name'],
                'owner_agent_id' => $agent->id,
                'inbound_allocation_id' => $allocation->id,
                'service_type' => $serviceType,
                'pricing_model' => PackagePricingModel::Fixed->value,
                'data_limit_gb' => $data['data_limit_gb'] !== null && $data['data_limit_gb'] > 0 ? $data['data_limit_gb'] : null,
                'sanaei_inbound_ids' => $allocation->inboundIdList(),
                'sanaei_limit_ip' => ($data['sanaei_limit_ip'] ?? 0) > 0 ? (int) $data['sanaei_limit_ip'] : null,
                'currency' => $allocation->moneyCurrency()->value,
                'is_active' => (bool) $data['is_active'],
                'package_category_id' => null,
                'duration_days' => 30,
                'base_price' => 0,
            ];

            $package = $package === null
                ? Package::query()->create($attributes)
                : tap($package)->update($attributes);

            $this->packages->syncDurations($package, $this->normalizedDurations($data['durations'] ?? []));
            $this->packages->syncServers($package, [$allocation->server_id], $serviceType);

            return $package->fresh(['durations']);
        });
    }

    public function delete(User $agent, Package $package): void
    {
        if ((int) $package->owner_agent_id !== (int) $agent->id) {
            throw new InvalidArgumentException(__('inbound_resellers.not_your_package'));
        }

        // Same rule as the admin's packages: accounts already sold keep working.
        $this->packages->deletePreservingAccounts($package);
    }

    /**
     * The protocol of the allocation's first inbound decides the account
     * type (VLESS / VMess / Trojan) the package creates.
     */
    protected function serviceTypeFor(InboundAllocation $allocation): \App\Enums\ServiceType
    {
        $first = $allocation->inboundIdList()[0] ?? null;
        $protocol = $first === null ? null : ServerInterface::query()
            ->where('server_id', $allocation->server_id)
            ->where('remote_key', 'inbound:'.$first)
            ->value('protocol');

        return $this->sanaei->serviceTypeFromProtocol((string) ($protocol ?? 'vless'));
    }

    /**
     * @param  array<string, array{is_enabled?: mixed, price?: mixed}>  $input
     * @return array<string, array{is_enabled: bool, price: float}>
     */
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
