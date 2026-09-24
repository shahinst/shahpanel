<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AccountBillingContext;
use App\Enums\AccountStatus;
use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\Concerns\RespondsWithJson;
use App\Http\Controllers\Concerns\ManagesAccounts;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AccountTransformer;
use App\Models\Account;
use App\Models\Package;
use App\Models\PackageDuration;
use App\Models\User;
use App\Services\AccountBillingPackageService;
use App\Services\AccountRenewalPricingService;
use App\Services\AccountService;
use App\Services\ClientAccountDetailService;
use App\Services\PackageCategoryService;
use App\Services\PackageService;
use App\Services\ServerSelectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Selling and lifecycle endpoints.
 *
 * Every guard here is the same one the panel's own screens run — this class
 * reuses ManagesAccounts rather than restating the rules, so a bot can never
 * take a path the web UI would have refused.
 */
class AccountController extends Controller
{
    use ManagesAccounts;
    use RespondsWithJson;

    public function index(Request $request): JsonResponse
    {
        // یک مقدار ناشناخته در فیلتر باید ۴۲۲ بدهد، نه ۲۰۰ با فیلترِ بی‌اثر:
        // وگرنه ربات باور می‌کند فیلتر اعمال شده و فهرست اشتباه را به مشتری
        // نشان می‌دهد. sort از اول همین رفتار را داشت، بقیه هم‌تراز شدند.
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(AccountStatus::class)],
            'service_type' => ['nullable', Rule::enum(ServiceType::class)],
            'package_id' => ['nullable', 'integer'],
            'server_id' => ['nullable', 'integer'],
            'seller_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'expiring_within_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'per_page' => ['nullable', 'integer'],
            'sort' => ['nullable', 'string', 'in:newest,oldest,expiry'],
        ]);

        $query = Account::query()
            ->ownedByHierarchy($request->user())
            ->with(['package', 'packageDuration', 'server', 'ownerSeller']);

        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        if (! empty($data['service_type'])) {
            $query->where('service_type', $data['service_type']);
        }

        if (! empty($data['package_id'])) {
            $query->where('package_id', (int) $data['package_id']);
        }

        if (! empty($data['server_id'])) {
            $query->where('server_id', (int) $data['server_id']);
        }

        // Agents can narrow to one of their sellers; the hierarchy scope above
        // already prevents reaching outside the subtree.
        if (! empty($data['seller_id'])) {
            $query->where('owner_seller_id', (int) $data['seller_id']);
        }

        if (! empty($data['search'])) {
            $term = '%'.$data['search'].'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('remote_username', 'like', $term)
                    ->orWhere('display_label', 'like', $term)
                    ->orWhere('client_email', 'like', $term);
            });
        }

        if (isset($data['expiring_within_days'])) {
            $query->whereNotNull('expiry_at')
                ->whereBetween('expiry_at', [now(), now()->addDays((int) $data['expiring_within_days'])]);
        }

        match ($data['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('id'),
            'expiry' => $query->orderByRaw('expiry_at IS NULL, expiry_at ASC'),
            default => $query->orderByDesc('id'),
        };

        $page = $query->paginate($this->perPage($data['per_page'] ?? null));

        return $this->paginated($page, static fn (Account $a): array => AccountTransformer::make($a));
    }

    public function show(Request $request, string $accountKey): JsonResponse
    {
        $model = $this->findAccount($request, $accountKey);

        if ($model === null) {
            return $this->fail('not_found', __('api.not_found'), 404);
        }

        $model->load(['package', 'packageDuration', 'server', 'ownerSeller', 'clientUser']);

        return $this->ok(AccountTransformer::make($model, detailed: true));
    }

    /**
     * Sell a new account. Charges the seller's wallet through the panel's own
     * pricing chain, so commissions and agent margins settle exactly as they
     * do from the web UI.
     */
    public function store(
        Request $request,
        AccountService $accountService,
        ServerSelectionService $serverSelection,
        PackageService $packageService,
    ): JsonResponse {
        $rules = $this->accountValidationRules();
        // The bot supplies a client only when the panel needs a portal user.
        $rules['client_mode'] = ['nullable', 'in:new,existing'];
        $rules['owner_seller_id'] = ['nullable', 'integer'];

        $validated = $request->validate($rules);
        $validated['client_mode'] ??= 'new';

        if (($validated['client_mode'] === 'new') && empty($validated['client_username'])) {
            // Bots usually have no separate portal user; derive one and skip it.
            $validated['skip_portal_client'] = true;
        }

        try {
            $seller = $this->resolveAccountSeller($request, $validated);
        } catch (ValidationException $e) {
            throw $e;
        }

        $package = Package::query()->find($validated['package_id']);

        if ($package === null) {
            return $this->fail('not_found', __('api.not_found'), 404);
        }

        try {
            $this->assertPackageAllowedForSeller($seller, $package);
            $this->assertPackageAvailableForNewAccount($package);
            $this->assertElasticGbValid($package, $validated['data_gb'] ?? null);

            $duration = $packageService->resolveDuration($package, (int) $validated['package_duration_id']);
            $server = $this->resolveServerForCreate($validated, $package, $serverSelection, $packageService);

            [$validated, $portalPassword] = $this->prepareNewClientPasswordForCreate($request, $validated);
            $validated['kyc_actor'] = $request->user();

            $account = $accountService->createAccount($seller, $package, $server, $duration, $validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return $this->fail('create_failed', $e->getMessage() ?: __('accounts.create_failed'), 422);
        }

        $account->load(['package', 'packageDuration', 'server', 'ownerSeller']);

        return $this->ok([
            'account' => AccountTransformer::make($account, detailed: true),
            'client_portal_password' => $portalPassword,
            'message' => __('api.account_created'),
        ], status: 201);
    }

    /**
     * Price a sale before committing to it — what a bot shows the buyer.
     */
    public function preview(
        Request $request,
        \App\Services\GlobalDiscountService $globalDiscountService,
        PackageService $packageService,
        \App\Services\UserPackagePricingService $pricingService,
        \App\Services\AgentSellerMarkupService $markupService,
        \App\Services\AgentFinancialPlanService $financialPlanService,
    ): JsonResponse {
        $response = $this->purchasePreviewResponse(
            $request,
            $globalDiscountService,
            $packageService,
            $pricingService,
            $markupService,
            $financialPlanService,
        );

        // این تریت با صفحات پنل مشترک است و خطایش قالب همان صفحات را دارد
        // ({"error": ...}) — بدون ok، پس ربات آن را «موفق‌شکل» می‌خواند. ترجمه
        // همین‌جا انجام می‌شود نه در تریت، که JS خود پنل را می‌شکست.
        if ($response->getStatusCode() >= 400) {
            $payload = $response->getData(true);
            $message = is_string($payload['error'] ?? null) && $payload['error'] !== ''
                ? $payload['error']
                : __('accounts.purchase_preview_failed');

            return $this->fail('preview_failed', $message, $response->getStatusCode());
        }

        return $response;
    }

    public function renew(Request $request, string $accountKey, AccountService $accountService): JsonResponse
    {
        $model = $this->findAccount($request, $accountKey);

        if ($model === null) {
            return $this->fail('not_found', __('api.not_found'), 404);
        }

        $data = $request->validate([
            'package_duration_id' => ['nullable', 'integer'],
            'renewal_mode' => ['nullable', 'in:same,add_volume,upgrade_volume'],
            'data_gb' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $model->loadMissing(['package.category']);

        if ($model->package === null
            || ! app(PackageCategoryService::class)->isPackageAvailableForRenewal($model->package)) {
            return $this->fail('renew_unavailable', __('packages.package_not_available_for_renewal'), 422);
        }

        $duration = null;

        if (! empty($data['package_duration_id'])) {
            $duration = PackageDuration::query()
                ->where('id', (int) $data['package_duration_id'])
                ->where('is_enabled', true)
                ->first();

            if ($duration === null) {
                return $this->fail('duration_unavailable', __('packages.duration_not_available'), 422);
            }
        }

        $renewalMode = $data['renewal_mode'] ?? 'same';
        $billingPackageService = app(AccountBillingPackageService::class);
        $renewalPricing = app(AccountRenewalPricingService::class);
        $renewalGbOverride = null;

        try {
            if ($duration !== null) {
                $billingPackageService->assertDurationBelongsToBillingPackage($model, $duration);
            }

            $model = $billingPackageService->syncBillingPackage($model, $duration);
            $model->loadMissing('package');

            if ($model->package !== null && $model->package->isElastic()) {
                $renewalGbOverride = $renewalPricing->resolveRenewalDataGb(
                    $model,
                    $renewalMode,
                    $data['data_gb'] ?? null,
                );
            }

            $account = $accountService->renewAccount(
                $model,
                $duration,
                AccountBillingContext::Staff,
                $request->user(),
                $renewalGbOverride,
                $renewalMode,
            );
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return $this->fail('renew_failed', $e->getMessage(), 422);
        }

        $account->load(['package', 'packageDuration', 'server']);

        return $this->ok([
            'account' => AccountTransformer::make($account, detailed: true),
            'message' => __('api.account_renewed'),
        ]);
    }

    public function enable(Request $request, string $accountKey, AccountService $accountService): JsonResponse
    {
        return $this->toggle($request, $accountKey, $accountService, enable: true);
    }

    public function disable(Request $request, string $accountKey, AccountService $accountService): JsonResponse
    {
        return $this->toggle($request, $accountKey, $accountService, enable: false);
    }

    /** Everything the buyer needs to connect: config text, QR, subscription URL. */
    public function config(Request $request, string $accountKey, ClientAccountDetailService $details): JsonResponse
    {
        $model = $this->findAccount($request, $accountKey);

        if ($model === null) {
            return $this->fail('not_found', __('api.not_found'), 404);
        }

        try {
            $detail = $details->build($model);
        } catch (Throwable $e) {
            report($e);

            return $this->fail('config_failed', $e->getMessage(), 500);
        }

        return $this->ok([
            'account' => AccountTransformer::make($model, detailed: true),
            'wireguard' => [
                'config' => $detail['wireguard']['config'] ?? null,
                'qr_base64' => $detail['wireguard']['qr_base64'] ?? null,
                'error' => $detail['wireguard']['error'] ?? null,
            ],
            'v2ray' => [
                'subscription_link' => $detail['panelV2ray']['subscription_link'] ?? null,
                'subscription_qr' => $detail['panelV2ray']['subscription_qr'] ?? null,
            ],
            'ppp' => $detail['ppp'] ?? null,
            'credentials' => $detail['clientCredentials'] ?? null,
            'usage' => $detail['usage'] ?? null,
        ]);
    }

    /** Force a fresh read from the remote panel, then report usage. */
    public function usage(Request $request, string $accountKey, AccountService $accountService): JsonResponse
    {
        $model = $this->findAccount($request, $accountKey);

        if ($model === null) {
            return $this->fail('not_found', __('api.not_found'), 404);
        }

        if ($request->boolean('refresh')) {
            try {
                $model = $accountService->refreshUsageFromPanelAndLogs($model);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $unlimited = $model->isUnlimited();
        $limit = $unlimited ? null : (int) $model->data_limit_bytes;
        $used = (int) $model->data_used_bytes;

        return $this->ok([
            'id' => $model->id,
            'username' => $model->remote_username,
            'unlimited' => $unlimited,
            'limit_bytes' => $limit,
            'used_bytes' => $used,
            'remaining_bytes' => $limit !== null ? max(0, $limit - $used) : null,
            'percent_used' => ($limit !== null && $limit > 0) ? min(100, round($used / $limit * 100, 1)) : null,
            'expires_at' => optional($model->expiry_at)->toIso8601String(),
            'status' => $model->status->value,
            'last_sync_at' => optional($model->last_sync_at)->toIso8601String(),
        ]);
    }

    protected function toggle(Request $request, string $accountKey, AccountService $accountService, bool $enable): JsonResponse
    {
        $model = $this->findAccount($request, $accountKey);

        if ($model === null) {
            return $this->fail('not_found', __('api.not_found'), 404);
        }

        try {
            $model = $enable
                ? $accountService->enableAccount($model)
                : $accountService->disableAccount($model);
        } catch (Throwable $e) {
            report($e);

            return $this->fail('toggle_failed', $e->getMessage(), 422);
        }

        return $this->ok([
            'account' => AccountTransformer::make($model),
            'message' => $enable ? __('api.account_enabled') : __('api.account_disabled'),
        ]);
    }

    /**
     * Accept either the numeric id or the remote username, always inside the
     * caller's own hierarchy.
     */
    protected function findAccount(Request $request, string $key): ?Account
    {
        $query = Account::query()->ownedByHierarchy($request->user());

        if (ctype_digit($key)) {
            return $query->find((int) $key);
        }

        return $query->where('remote_username', $key)->first();
    }

    /**
     * An agent may sell on behalf of one of their own sellers; anyone else
     * sells as themselves.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function resolveAccountSeller(Request $request, array $validated): User
    {
        $actor = $request->user();
        $requested = $validated['owner_seller_id'] ?? null;

        if (empty($requested) || (int) $requested === (int) $actor->id) {
            return $actor;
        }

        if ($actor->role !== UserRole::Agent) {
            throw ValidationException::withMessages([
                'owner_seller_id' => [__('api.forbidden')],
            ]);
        }

        $seller = User::query()
            ->whereNull('deleted_at')
            ->whereIn('id', User::subtreeUserIds($actor))
            ->find((int) $requested);

        if ($seller === null) {
            throw ValidationException::withMessages([
                'owner_seller_id' => [__('api.forbidden')],
            ]);
        }

        return $seller;
    }

    /** Only used by trait paths that redirect; the API never calls those. */
    protected function accountRoutePrefix(): string
    {
        return 'agent';
    }
}
