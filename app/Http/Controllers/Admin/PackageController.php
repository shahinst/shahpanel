<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PackageDurationTier;
use App\Enums\PasarguardExpiryActivation;
use App\Enums\ServerType;
use App\Enums\ServiceType;
use App\Services\Pasarguard\PasarguardGroupCatalog;
use App\Services\ServerInterfaceSyncService;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\PackageCategory;
use App\Models\PackageDuration;
use App\Models\Server;
use App\Models\ServerInterface;
use App\Services\PackageCategoryService;
use App\Services\PackageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PackageController extends Controller
{
    public function __construct(
        protected PackageService $packageService,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Package::class);

        $categoryService = app(PackageCategoryService::class);

        $packages = Package::query()
            ->with(array_merge(['durations', 'servers'], $categoryService->packageWithRelations()))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $search = $request->string('search')->toString();
                $query->where('name', 'like', "%{$search}%");
            })
            ->when($categoryService->isAvailable() && $request->filled('category_id'), function ($query) use ($request): void {
                $categoryId = $request->integer('category_id');
                if ($categoryId === 0) {
                    $query->whereNull('package_category_id');
                } else {
                    $query->where('package_category_id', $categoryId);
                }
            })
            ->orderBy('sort_order')
            ->paginate(15)
            ->withQueryString();

        $categories = $categoryService->orderedActive();

        $managedCategories = collect();
        if ($categoryService->isAvailable() && class_exists(PackageCategory::class)) {
            try {
                $managedCategories = PackageCategory::query()
                    ->withCount('packages')
                    ->orderBy('sort_order')
                    ->orderBy('name')
                    ->get();
            } catch (\Throwable) {
                $managedCategories = collect();
            }
        }

        return view('admin.packages.index', compact('packages', 'categories', 'managedCategories'));
    }

    public function create(): View
    {
        $this->authorize('create', Package::class);

        $servers = Server::query()->active()->orderBy('name')->get();
        $durationTiers = PackageDurationTier::cases();
        $categories = app(PackageCategoryService::class)->forPackageForm();

        return view('admin.packages.create', [
            'servers' => $servers,
            'durationTiers' => $durationTiers,
            'categories' => $categories,
            'pasarguardGroupOptions' => [],
            'pasarguardGroupsByServer' => $this->pasarguardGroupsByServerMap($servers),
            'mikrotikProfilesByServer' => $this->mikrotikProfilesByServerMap($servers),
            'sanaeiInboundsByServer' => $this->sanaeiInboundsByServerMap($servers),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Package::class);

        $validated = $this->validatedCore($request);

        DB::transaction(function () use ($request, $validated): void {
            $package = Package::query()->create($validated);

            $this->packageService->syncDurations($package, $request->input('durations', []));
            $this->packageService->syncServers(
                $package,
                $request->input('server_ids', []),
                ServiceType::from($validated['service_type'])
            );
        });

        return redirect()
            ->route('admin.packages.index')
            ->with('success', __('app.saved'));
    }

    public function edit(Package $package): View
    {
        $this->authorize('update', $package);

        $package->load(array_merge(['durations', 'servers'], app(PackageCategoryService::class)->packageWithRelations()));
        $servers = Server::query()->active()->orderBy('name')->get();
        $durationTiers = PackageDurationTier::cases();
        $durationsByTier = $package->durations->keyBy(fn (PackageDuration $d) => $d->tier->value);
        $categories = app(PackageCategoryService::class)->forPackageForm($package->category);

        $pasarguardGroupOptions = $this->pasarguardGroupOptionsForPackage($package);

        return view('admin.packages.edit', [
            'package' => $package,
            'servers' => $servers,
            'durationTiers' => $durationTiers,
            'durationsByTier' => $durationsByTier,
            'categories' => $categories,
            'pasarguardGroupOptions' => $pasarguardGroupOptions,
            'pasarguardGroupsByServer' => $this->pasarguardGroupsByServerMap($servers),
            'mikrotikProfilesByServer' => $this->mikrotikProfilesByServerMap($servers),
            'sanaeiInboundsByServer' => $this->sanaeiInboundsByServerMap($servers),
        ]);
    }

    /**
     * Live provisioning options for a single server, used by the package form so
     * the admin can pick a freshly-added server's interfaces / groups without
     * saving, leaving, syncing on the server page, and coming back.
     *
     * With ?sync=1 (the default) it pulls from the remote first — the same pull
     * the "sync interfaces" button on the server page runs — then returns the
     * now-current options for that one server.
     */
    public function serverProvisioningOptions(
        Request $request,
        Server $server,
        ServerInterfaceSyncService $interfaceSyncService,
    ): JsonResponse {
        $this->authorize('create', Package::class);

        $syncOk = true;
        $syncMessage = null;

        if ($request->boolean('sync', true)) {
            @set_time_limit(max(120, (int) config('shahpanel.mikrotik.inline_max_seconds', 600)));

            try {
                $result = $interfaceSyncService->sync($server);
                $errors = array_values(array_filter(array_map('strval', $result['errors'] ?? [])));

                if ($errors !== []) {
                    $syncOk = false;
                    $syncMessage = implode(' | ', $errors);
                }
            } catch (\Throwable $exception) {
                report($exception);
                $syncOk = false;
                $syncMessage = $exception->getMessage();
            }
        }

        $server->refresh();
        $servers = collect([$server]);
        $key = (string) $server->id;

        return response()->json([
            'server_id' => $server->id,
            'type' => $server->type->value,
            'sync_ok' => $syncOk,
            'sync_message' => $syncMessage,
            'mikrotik_profiles' => $this->mikrotikProfilesByServerMap($servers)[$key] ?? [],
            'pasarguard_groups' => $this->pasarguardGroupsByServerMap($servers)[$key] ?? [],
        ]);
    }

    public function update(Request $request, Package $package): RedirectResponse
    {
        $this->authorize('update', $package);

        $validated = $this->validatedCore($request);
        // Currency is immutable after create to keep wallets/history consistent.
        $validated['currency'] = $package->moneyCurrency()->value;

        DB::transaction(function () use ($request, $validated, $package): void {
            $package->update($validated);

            $this->packageService->syncDurations($package, $request->input('durations', []));
            $this->packageService->syncServers(
                $package,
                $request->input('server_ids', []),
                ServiceType::from($validated['service_type'])
            );
        });

        return redirect()
            ->route('admin.packages.index')
            ->with('success', __('app.saved'));
    }

    public function destroy(Package $package): RedirectResponse
    {
        $this->authorize('delete', $package);

        try {
            $result = $this->packageService->deletePreservingAccounts($package);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()
                ->route('admin.packages.index', request()->only('category_id'))
                ->with('error', __('packages.delete_failed', ['error' => $exception->getMessage()]));
        }

        $message = ($result['active'] ?? 0) > 0
            ? __('packages.deleted_with_active_accounts', ['count' => persian_digits($result['active'])])
            : __('app.deleted');

        return redirect()
            ->route('admin.packages.index', request()->only('category_id'))
            ->with('success', $message);
    }

    public function toggleActive(Package $package): RedirectResponse
    {
        $this->authorize('update', $package);

        $next = ! $package->is_active;

        if ($next) {
            $package->loadMissing('category');

            if ($package->category !== null && ! $package->category->is_active) {
                return redirect()
                    ->route('admin.packages.index', request()->only('category_id'))
                    ->with('error', __('packages.cannot_activate_package_inactive_category'));
            }
        }

        $package->update(['is_active' => $next]);

        return redirect()
            ->route('admin.packages.index', request()->only('category_id'))
            ->with('success', $next ? __('packages.package_activated') : __('packages.package_deactivated'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedCore(Request $request): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'currency' => ['required', Rule::enum(\App\Enums\MoneyCurrency::class)],
            'service_type' => ['required', Rule::enum(ServiceType::class)],
            'pricing_model' => ['required', Rule::enum(\App\Enums\PackagePricingModel::class)],
            'data_limit_gb' => ['nullable', 'numeric', 'min:0'],
            'min_data_gb' => ['nullable', 'numeric', 'min:1'],
            'max_data_gb' => ['nullable', 'numeric', 'min:1', 'gte:min_data_gb'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'server_ids' => ['required', 'array', 'min:1'],
            'server_ids.*' => ['integer', 'exists:servers,id'],
            'durations' => ['required', 'array'],
        ];

        if (app(PackageCategoryService::class)->isAvailable()) {
            $rules['package_category_id'] = ['nullable', 'integer', 'exists:package_categories,id'];
        }

        $validated = $request->validate($rules);

        $hasEnabled = collect($validated['durations'] ?? [])
            ->contains(fn ($row): bool => filter_var($row['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN));

        if (! $hasEnabled) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'durations' => [__('packages.at_least_one_duration')],
            ]);
        }

        $isElastic = ($validated['pricing_model'] ?? 'fixed') === \App\Enums\PackagePricingModel::Elastic->value;

        if ($isElastic) {
            if (empty($validated['min_data_gb']) || empty($validated['max_data_gb'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'min_data_gb' => [__('packages.elastic_bounds_required')],
                ]);
            }

            foreach (\App\Enums\PackageDurationTier::cases() as $tier) {
                $row = $validated['durations'][$tier->value] ?? [];
                $enabled = filter_var($row['is_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                if (! $enabled) {
                    continue;
                }

                if ((float) ($row['price'] ?? 0) <= 0) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'durations' => [__('packages.elastic_duration_price_required', ['duration' => $tier->label()])],
                    ]);
                }
            }
        } else {
            $noTimeLimitEnabled = collect([
                \App\Enums\PackageDurationTier::UnlimitedTime->value,
                \App\Enums\PackageDurationTier::VolumeOnly->value,
            ])->contains(fn (string $tier): bool => filter_var(
                $validated['durations'][$tier]['is_enabled'] ?? false,
                FILTER_VALIDATE_BOOLEAN
            ));

            if ($noTimeLimitEnabled && (empty($validated['data_limit_gb']) || (float) $validated['data_limit_gb'] <= 0)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'data_limit_gb' => [__('packages.volume_only_requires_data_limit')],
                ]);
            }
        }

        $serverIds = array_map('intval', $validated['server_ids']);
        $serviceTypeEnum = ServiceType::from($validated['service_type']);
        $hasPasarguard = $serviceTypeEnum->isPasarguard()
            || Server::query()->whereIn('id', $serverIds)->where('type', ServerType::Pasarguard)->exists();

        if ($hasPasarguard) {
            $pasarguardRules = $request->validate([
                'pasarguard_group_id' => ['required', 'integer', 'min:1'],
                'pasarguard_hwid_limit' => ['nullable', 'integer', 'min:0', 'max:255'],
                'pasarguard_expiry_activation' => ['required', Rule::enum(PasarguardExpiryActivation::class)],
            ]);
            $validated = array_merge($validated, $pasarguardRules);

            $this->assertPasarguardGroupAllowed($serviceTypeEnum, $serverIds, (int) $pasarguardRules['pasarguard_group_id']);
        }

        $hasRemnawave = $serviceTypeEnum->isRemnawave()
            || Server::query()->whereIn('id', $serverIds)->where('type', ServerType::Remnawave)->exists();

        if ($hasRemnawave) {
            $remnawaveRules = $request->validate([
                'remnawave_traffic_strategy' => ['required', Rule::in(['NO_RESET', 'DAY', 'WEEK', 'MONTH'])],
            ]);
            $validated = array_merge($validated, $remnawaveRules);
        }

        $hasCisco = $serviceTypeEnum->isCiscoAnyconnect()
            || Server::query()->whereIn('id', $serverIds)->where('type', ServerType::CiscoAnyconnect)->exists();

        if ($hasCisco) {
            $ciscoRules = $request->validate([
                'cisco_group_policy' => ['nullable', 'string', 'max:128'],
                'cisco_tunnel_group' => ['nullable', 'string', 'max:128'],
                'cisco_simultaneous_logins' => ['nullable', 'integer', 'min:0', 'max:100'],
            ]);
            $validated = array_merge($validated, $ciscoRules);

            if (! $serviceTypeEnum->isCiscoAnyconnect()) {
                throw ValidationException::withMessages([
                    'service_type' => [__('packages.cisco_requires_service_type')],
                ]);
            }
            $nonCiscoServers = Server::query()->whereIn('id', $serverIds)->where('type', '!=', ServerType::CiscoAnyconnect)->exists();
            if ($nonCiscoServers) {
                throw ValidationException::withMessages([
                    'server_ids' => [__('packages.cisco_servers_only')],
                ]);
            }
        }

        $hasOcserv = $serviceTypeEnum->isOcserv()
            || Server::query()->whereIn('id', $serverIds)->where('type', ServerType::Ocserv)->exists();

        if ($hasOcserv) {
            $ocservRules = $request->validate([
                'ocserv_max_sessions' => ['nullable', 'integer', 'min:0', 'max:1000'],
                'ocserv_group' => ['nullable', 'string', 'max:128'],
            ]);
            $validated = array_merge($validated, $ocservRules);

            if (! $serviceTypeEnum->isOcserv()) {
                throw ValidationException::withMessages([
                    'service_type' => [__('packages.ocserv_requires_service_type')],
                ]);
            }
            $nonOcservServers = Server::query()->whereIn('id', $serverIds)->where('type', '!=', ServerType::Ocserv)->exists();
            if ($nonOcservServers) {
                throw ValidationException::withMessages([
                    'server_ids' => [__('packages.ocserv_servers_only')],
                ]);
            }
        }

        $hasMikrotik = $serviceTypeEnum->isMikrotik()
            || Server::query()->whereIn('id', $serverIds)->where('type', ServerType::Mikrotik)->exists();

        if ($hasMikrotik && $serviceTypeEnum->isMikrotik()) {
            $mikrotikRules = $request->validate([
                'mikrotik_profile_keys' => ['required', 'array', 'min:1'],
                'mikrotik_profile_keys.*' => ['required', 'string', 'max:128', 'regex:/^profile:(wg|ppp):/'],
            ]);
            $validated = array_merge($validated, $mikrotikRules);
            $this->assertMikrotikProfilesAllowed(
                $serviceTypeEnum,
                $serverIds,
                array_map('strval', $validated['mikrotik_profile_keys'])
            );
        }

        // پکیج ثنایی می‌تواند به inboundهای مشخصی محدود شود؛ خالی یعنی «همهٔ inboundهای فعال» (رفتار قبلی پکیج‌های موجود).
        $hasSanaei = $serviceTypeEnum->isSanaei()
            || Server::query()->whereIn('id', $serverIds)->where('type', ServerType::Sanaei)->exists();

        $sanaeiInboundIds = [];

        if ($hasSanaei && $serviceTypeEnum->isSanaei()) {
            $sanaeiRules = $request->validate([
                'sanaei_inbound_ids' => ['nullable', 'array'],
                'sanaei_inbound_ids.*' => ['integer', 'min:1'],
            ]);
            $validated = array_merge($validated, $sanaeiRules);
            $sanaeiInboundIds = array_values(array_unique(array_map('intval', $sanaeiRules['sanaei_inbound_ids'] ?? [])));
            $this->assertSanaeiInboundsAllowed($serverIds, $sanaeiInboundIds);
        }

        return [
            'name' => $validated['name'],
            'currency' => $validated['currency'] ?? \App\Enums\MoneyCurrency::IRT->value,
            'package_category_id' => app(PackageCategoryService::class)->isAvailable()
                ? ($validated['package_category_id'] ?? null)
                : null,
            'service_type' => $validated['service_type'],
            'pricing_model' => $validated['pricing_model'],
            'data_limit_gb' => $isElastic ? null : ($validated['data_limit_gb'] ?? null),
            'min_data_gb' => $isElastic ? $validated['min_data_gb'] : null,
            'max_data_gb' => $isElastic ? $validated['max_data_gb'] : null,
            'pasarguard_group_id' => $hasPasarguard ? (int) $validated['pasarguard_group_id'] : null,
            'pasarguard_hwid_limit' => $hasPasarguard ? (int) ($validated['pasarguard_hwid_limit'] ?? 0) : null,
            'pasarguard_expiry_activation' => $hasPasarguard
                ? ($validated['pasarguard_expiry_activation'] ?? PasarguardExpiryActivation::FromCreation->value)
                : PasarguardExpiryActivation::FromCreation->value,
            'remnawave_squads' => null,
            'remnawave_traffic_strategy' => $hasRemnawave
                ? strtoupper((string) $validated['remnawave_traffic_strategy'])
                : 'NO_RESET',
            'cisco_group_policy' => $hasCisco ? ($validated['cisco_group_policy'] ?? null) : null,
            'cisco_tunnel_group' => $hasCisco ? ($validated['cisco_tunnel_group'] ?? null) : null,
            'cisco_simultaneous_logins' => $hasCisco && isset($validated['cisco_simultaneous_logins']) && $validated['cisco_simultaneous_logins'] !== null && $validated['cisco_simultaneous_logins'] !== ''
                ? (int) $validated['cisco_simultaneous_logins']
                : null,
            'ocserv_max_sessions' => $hasOcserv && isset($validated['ocserv_max_sessions']) && $validated['ocserv_max_sessions'] !== null && $validated['ocserv_max_sessions'] !== ''
                ? (int) $validated['ocserv_max_sessions']
                : null,
            'ocserv_group' => $hasOcserv ? ($validated['ocserv_group'] ?? null) : null,
            'mikrotik_profile_keys' => ($hasMikrotik && $serviceTypeEnum->isMikrotik())
                ? array_values(array_unique(array_map('strval', $validated['mikrotik_profile_keys'])))
                : null,
            'sanaei_inbound_ids' => ($hasSanaei && $serviceTypeEnum->isSanaei() && $sanaeiInboundIds !== [])
                ? $sanaeiInboundIds
                : null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_active' => $this->resolvePackageActiveState($request, $validated),
            'kyc_required' => $request->boolean('kyc_required'),
            'default_server_id' => (int) $validated['server_ids'][0],
            'duration_days' => 30,
            'base_price' => 0,
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    protected function pasarguardGroupOptionsForPackage(Package $package): array
    {
        foreach ($package->servers as $server) {
            if ($server->isPasarguard()) {
                return $this->pasarguardGroupOptionsForServer($server);
            }
        }

        return [];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    protected function pasarguardGroupOptionsForServer(Server $server): array
    {
        return PasarguardGroupCatalog::forServer($server);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Server>|\Illuminate\Database\Eloquent\Collection<int, Server>  $servers
     * @return array<string, list<array{id: int, name: string, inbound_tags: list<string>}>>
     */
    protected function pasarguardGroupsByServerMap($servers): array
    {
        $map = [];

        foreach ($servers as $server) {
            if ($server->type !== ServerType::Pasarguard) {
                continue;
            }

            $map[(string) $server->id] = PasarguardGroupCatalog::forServer($server);
        }

        return $map;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Server>|\Illuminate\Database\Eloquent\Collection<int, Server>  $servers
     * @return array<string, list<array{key: string, name: string, kind: string, label: string}>}
     */
    protected function mikrotikProfilesByServerMap($servers): array
    {
        $map = [];
        $serverIds = $servers->filter(fn (Server $server): bool => $server->isMikrotik())->pluck('id')->all();

        if ($serverIds === []) {
            return $map;
        }

        $ifaces = ServerInterface::query()
            ->whereIn('server_id', $serverIds)
            ->orderBy('name')
            ->get();

        foreach ($ifaces as $iface) {
            $key = (string) $iface->remote_key;

            if (! str_starts_with($key, 'profile:wg:') && ! str_starts_with($key, 'profile:ppp:')) {
                continue;
            }

            $kind = $iface->isWireguardProfile() ? 'wg' : 'ppp';
            $suffix = $kind === 'wg' ? __('packages.mikrotik_kind_wireguard') : __('packages.mikrotik_kind_ppp');

            $map[(string) $iface->server_id][] = [
                'key' => $key,
                'name' => $iface->name,
                'kind' => $kind,
                'label' => $iface->name.' ('.$suffix.')',
            ];
        }

        return $map;
    }

    /**
     * inboundهای همگام‌شدهٔ سرورهای ثنایی، گروه‌بندی‌شده بر اساس سرور تا ادمین بداند هر inbound مال کدام سرور است.
     *
     * @param  \Illuminate\Support\Collection<int, Server>|\Illuminate\Database\Eloquent\Collection<int, Server>  $servers
     * @return array<string, list<array{id: int, name: string, protocol: string|null, port: int|null, label: string}>>
     */
    protected function sanaeiInboundsByServerMap($servers): array
    {
        $map = [];
        $serverIds = [];

        foreach ($servers as $server) {
            if (! $server->isSanaei()) {
                continue;
            }

            $map[(string) $server->id] = [];
            $serverIds[] = $server->id;
        }

        if ($serverIds === []) {
            return $map;
        }

        // فقط inbound فعال قابل انتخاب است؛ inbound غیرفعال روی پنل اکانت نمی‌سازد.
        $rows = ServerInterface::query()
            ->whereIn('server_id', $serverIds)
            ->where('category', 'inbound')
            ->where('is_enabled', true)
            ->orderBy('name')
            ->get();

        foreach ($rows as $row) {
            $inboundId = $this->sanaeiInboundIdFromRemoteKey((string) $row->remote_key);

            if ($inboundId === null) {
                continue;
            }

            $key = (string) $row->server_id;

            if (! isset($map[$key])) {
                continue;
            }

            $name = (string) ($row->name ?: 'Inbound '.$inboundId);
            $details = array_values(array_filter([
                $row->protocol,
                $row->port !== null ? (string) $row->port : null,
            ]));

            // پروتکل و پورت در برچسب می‌آید چون چند inbound معمولاً نام مشابه دارند.
            $map[$key][] = [
                'id' => $inboundId,
                'name' => $name,
                'protocol' => $row->protocol,
                'port' => $row->port,
                'label' => $details === []
                    ? $name.' (#'.$inboundId.')'
                    : $name.' — '.implode(':', $details).' (#'.$inboundId.')',
            ];
        }

        return $map;
    }

    /**
     * شناسهٔ واقعی inbound در 3x-ui داخل remote_key به شکل «inbound:12» ذخیره شده است.
     * PasarGuard هم از همین category استفاده می‌کند ولی کلیدش md5 تگ است، پس عددی بودن را چک می‌کنیم.
     */
    protected function sanaeiInboundIdFromRemoteKey(string $remoteKey): ?int
    {
        $prefix = 'inbound:';

        if (! str_starts_with($remoteKey, $prefix)) {
            return null;
        }

        $inboundId = substr($remoteKey, strlen($prefix));

        return ctype_digit($inboundId) && (int) $inboundId > 0 ? (int) $inboundId : null;
    }

    /**
     * فرم قابل دستکاری است، پس id ارسالی باید واقعاً متعلق به یکی از سرورهای ثنایی همین پکیج باشد.
     *
     * @param  list<int>  $serverIds
     * @param  list<int>  $inboundIds
     */
    protected function assertSanaeiInboundsAllowed(array $serverIds, array $inboundIds): void
    {
        if ($inboundIds === []) {
            return; // خالی مجاز است و یعنی «همهٔ inboundهای فعال»
        }

        $sanaeiServerIds = Server::query()
            ->whereIn('id', $serverIds)
            ->where('type', ServerType::Sanaei)
            ->pluck('id')
            ->all();

        if ($sanaeiServerIds === []) {
            throw ValidationException::withMessages([
                'sanaei_inbound_ids' => [__('packages.sanaei_server_required')],
            ]);
        }

        $allowed = [];

        $remoteKeys = ServerInterface::query()
            ->whereIn('server_id', $sanaeiServerIds)
            ->where('category', 'inbound')
            ->pluck('remote_key');

        foreach ($remoteKeys as $remoteKey) {
            $inboundId = $this->sanaeiInboundIdFromRemoteKey((string) $remoteKey);

            if ($inboundId !== null) {
                $allowed[$inboundId] = true;
            }
        }

        foreach ($inboundIds as $inboundId) {
            if (! isset($allowed[$inboundId])) {
                throw ValidationException::withMessages([
                    'sanaei_inbound_ids' => [__('packages.sanaei_inbound_not_on_servers', ['id' => $inboundId])],
                ]);
            }
        }
    }

    /**
     * @param  list<int>  $serverIds
     * @param  list<string>  $profileKeys
     */
    protected function assertMikrotikProfilesAllowed(ServiceType $serviceType, array $serverIds, array $profileKeys): void
    {
        $profileKeys = array_values(array_unique(array_filter(
            array_map('strval', $profileKeys),
            static fn (string $key): bool => $key !== ''
        )));

        if ($profileKeys === []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'mikrotik_profile_keys' => [__('packages.mikrotik_profiles_required')],
            ]);
        }

        $expectedPrefix = $serviceType === ServiceType::Wireguard ? 'profile:wg:' : 'profile:ppp:';

        foreach ($profileKeys as $profileKey) {
            if (! str_starts_with($profileKey, $expectedPrefix)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'mikrotik_profile_keys' => [$serviceType === ServiceType::Wireguard
                        ? __('packages.mikrotik_profile_wrong_kind_wireguard')
                        : __('packages.mikrotik_profile_wrong_kind_ppp')],
                ]);
            }
        }

        $mikrotikServerIds = Server::query()
            ->whereIn('id', $serverIds)
            ->where('type', ServerType::Mikrotik)
            ->pluck('id');

        if ($mikrotikServerIds->isEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'server_ids' => [__('packages.mikrotik_server_required')],
            ]);
        }

        // Interface keys are name-based and each row lives on a specific server.
        // A package spans several MikroTik servers, each contributing its own
        // interfaces, so the rules are per-server rather than "every key on
        // every server":
        //   1) each chosen key must exist on at least one selected server, and
        //   2) every selected server must own at least one chosen interface —
        //      otherwise balancing an account onto that server could not resolve
        //      an interface at creation time.
        $rows = ServerInterface::query()
            ->whereIn('server_id', $mikrotikServerIds)
            ->whereIn('remote_key', $profileKeys)
            ->get(['server_id', 'remote_key']);

        $presentKeys = $rows->pluck('remote_key')->unique();
        $unknownKey = collect($profileKeys)->first(
            static fn (string $key): bool => ! $presentKeys->contains($key)
        );

        if ($unknownKey !== null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'mikrotik_profile_keys' => [__('packages.mikrotik_profile_not_on_servers', ['name' => $unknownKey])],
            ]);
        }

        $serversWithInterface = $rows->pluck('server_id')->unique();
        $serversMissingInterface = $mikrotikServerIds->diff($serversWithInterface);

        if ($serversMissingInterface->isNotEmpty()) {
            $serverName = Server::query()
                ->whereIn('id', $serversMissingInterface->all())
                ->orderBy('id')
                ->value('name');

            throw \Illuminate\Validation\ValidationException::withMessages([
                'mikrotik_profile_keys' => [__('packages.mikrotik_server_needs_interface', ['server' => (string) $serverName])],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function resolvePackageActiveState(Request $request, array $validated): bool
    {
        $isActive = $request->boolean('is_active');

        if (! $isActive || ! app(PackageCategoryService::class)->isAvailable()) {
            return $isActive;
        }

        $categoryId = $validated['package_category_id'] ?? null;

        if ($categoryId === null) {
            return true;
        }

        $category = PackageCategory::query()->find((int) $categoryId);

        return $category === null || $category->is_active;
    }

    /**
     * @param  list<int>  $serverIds
     */
    protected function assertPasarguardGroupAllowed(ServiceType $serviceType, array $serverIds, int $groupId): void
    {
        if (! $serviceType->isPasarguard()) {
            return;
        }

        $server = Server::query()
            ->whereIn('id', $serverIds)
            ->where('type', ServerType::Pasarguard)
            ->first();

        if ($server === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'server_ids' => [__('packages.pasarguard_server_required')],
            ]);
        }

        $allowed = collect(PasarguardGroupCatalog::forServer($server));

        if ($allowed->isEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'pasarguard_group_id' => [__('packages.pasarguard_groups_not_synced')],
            ]);
        }

        if (! $allowed->contains('id', $groupId)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'pasarguard_group_id' => [__('packages.pasarguard_group_invalid')],
            ]);
        }
    }

}
