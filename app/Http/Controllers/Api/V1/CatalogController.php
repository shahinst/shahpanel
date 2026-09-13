<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\Concerns\RespondsWithJson;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\PackageDuration;
use App\Models\Server;
use App\Models\User;
use App\Services\PackageCategoryService;
use App\Services\PackageService;
use App\Services\UserPackageAssignmentService;
use App\Services\UserPackagePricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * What the caller is allowed to sell, priced for the caller.
 *
 * Prices come from UserPackagePricingService, so each reseller sees their own
 * wholesale figure — never the catalog base price.
 */
class CatalogController extends Controller
{
    use RespondsWithJson;

    public function __construct(
        protected UserPackageAssignmentService $assignments,
        protected UserPackagePricingService $pricing,
    ) {}

    public function packages(Request $request): JsonResponse
    {
        $seller = $this->resolveSeller($request);

        $packages = $this->assignments->assignedPackagesQuery($seller)
            ->with(['durations' => fn ($q) => $q->where('is_enabled', true)->orderBy('sort_order')])
            ->when(app(PackageCategoryService::class)->isAvailable(), fn ($q) => $q->with('category'))
            ->get();

        $categoryService = app(PackageCategoryService::class);

        $payload = $packages->map(function (Package $package) use ($seller, $categoryService): array {
            return [
                'id' => $package->id,
                'name' => $package->name,
                'service_type' => $package->service_type?->value,
                'category' => $package->relationLoaded('category') && $package->category !== null
                    ? ['id' => $package->category->id, 'name' => $package->category->name]
                    : null,
                'is_elastic' => $package->isElastic(),
                'is_unlimited' => $package->isUnlimited(),
                'data_limit_gb' => $package->data_limit_gb !== null ? (float) $package->data_limit_gb : null,
                'min_data_gb' => $package->min_data_gb !== null ? (float) $package->min_data_gb : null,
                'max_data_gb' => $package->max_data_gb !== null ? (float) $package->max_data_gb : null,
                'available_for_new_accounts' => $categoryService->isPackageAvailableForNewAccounts($package),
                'available_for_renewal' => $categoryService->isPackageAvailableForRenewal($package),
                'durations' => $package->durations->map(
                    fn (PackageDuration $d): array => $this->durationPayload($seller, $package, $d),
                )->all(),
            ];
        })->values()->all();

        return $this->ok($payload);
    }

    public function servers(Request $request, PackageService $packageService): JsonResponse
    {
        $data = $request->validate([
            'package_id' => ['nullable', 'integer'],
        ]);

        $query = Server::query()
            ->where('is_active', true)
            ->orderBy('name');

        $servers = $query->get(['id', 'name', 'location', 'type', 'is_active', 'max_accounts', 'account_cap']);

        // When a package is given, keep only the servers that package allows.
        if (! empty($data['package_id'])) {
            $package = Package::query()->find((int) $data['package_id']);

            if ($package === null) {
                return $this->fail('not_found', __('api.not_found'), 404);
            }

            $servers = $servers->filter(function (Server $server) use ($packageService, $package): bool {
                try {
                    $packageService->assertServerAllowed($package, $server);

                    return true;
                } catch (Throwable) {
                    return false;
                }
            })->values();
        }

        return $this->ok($servers->map(static fn (Server $s): array => [
            'id' => $s->id,
            'name' => $s->name,
            'location' => $s->location,
            'type' => $s->type?->value ?? (string) $s->type,
        ])->all());
    }

    /**
     * Price one duration, optionally for a specific elastic volume — what a bot
     * needs to render a price list.
     */
    public function price(Request $request): JsonResponse
    {
        $data = $request->validate([
            'package_duration_id' => ['required', 'integer'],
            'data_gb' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $seller = $this->resolveSeller($request);

        $duration = PackageDuration::query()->with('package')->find((int) $data['package_duration_id']);

        if ($duration === null || $duration->package === null) {
            return $this->fail('not_found', __('api.not_found'), 404);
        }

        try {
            $this->assignments->assertUserHasPackage($seller, $duration->package);
        } catch (Throwable) {
            return $this->fail('forbidden', __('api.forbidden'), 403);
        }

        $gb = isset($data['data_gb']) ? (float) $data['data_gb'] : null;

        return $this->ok($this->durationPayload($seller, $duration->package, $duration, $gb));
    }

    /** @return array<string, mixed> */
    protected function durationPayload(User $seller, Package $package, PackageDuration $duration, ?float $gb = null): array
    {
        $duration->setRelation('package', $package);

        $purchase = null;
        $renewal = null;

        try {
            $purchase = $this->pricing->buyerWholesaleTotal($seller, $duration, $gb);
        } catch (Throwable) {
            $purchase = null;
        }

        try {
            $renewal = $this->pricing->buyerRenewalWholesaleTotal($seller, $duration, $gb);
        } catch (Throwable) {
            $renewal = null;
        }

        return [
            'id' => $duration->id,
            'days' => $duration->duration_days,
            'tier' => $duration->tier?->value ?? null,
            'unit_price' => $this->pricing->wholesalePriceFor($seller, $duration),
            'purchase_total' => $purchase,
            'renewal_total' => $renewal,
            'priced_for_gb' => $gb,
        ];
    }

    /**
     * Sellers price for themselves; an agent may ask for one of their sellers.
     */
    protected function resolveSeller(Request $request): User
    {
        $actor = $request->user();
        $requested = $request->input('seller_id');

        if (empty($requested) || (int) $requested === (int) $actor->id) {
            return $actor;
        }

        if ($actor->role !== UserRole::Agent) {
            throw ValidationException::withMessages(['seller_id' => [__('api.forbidden')]]);
        }

        $seller = User::query()
            ->whereNull('deleted_at')
            ->whereIn('id', User::subtreeUserIds($actor))
            ->find((int) $requested);

        if ($seller === null) {
            throw ValidationException::withMessages(['seller_id' => [__('api.forbidden')]]);
        }

        return $seller;
    }
}
