<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\AccountBillingContext;
use App\Models\Account;
use App\Models\Package;
use App\Models\PackageDuration;
use App\Models\Server;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AccountTransferService;
use App\Services\AgentFinancialPlanService;
use App\Services\AgentSellerMarkupService;
use App\Support\AccountNameValidator;
use App\Services\EndUserService;
use App\Services\GlobalDiscountService;
use App\Services\UserPackageAssignmentService;
use App\Services\UserPackagePricingService;
use App\Services\PackageCategoryService;
use App\Services\PackageService;
use App\Services\ServerSelectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

trait ManagesAccounts
{
    protected function accountValidationRules(?int $accountId = null, bool $serverOptional = true): array
    {
        return [
            'package_id' => ['required', 'exists:packages,id'],
            'package_duration_id' => ['required', 'exists:package_durations,id'],
            'data_gb' => ['nullable', 'numeric', 'min:0.01'],
            'server_id' => [
                $serverOptional ? 'nullable' : 'required',
                'exists:servers,id',
            ],
            'remote_username' => array_merge(
                AccountNameValidator::rules(AccountNameValidator::REMOTE_MAX),
                [Rule::unique('accounts', 'remote_username')->ignore($accountId)],
            ),
            'client_email' => ['nullable', 'email', 'max:255'],
            'client_mode' => ['required', Rule::in(['new', 'existing'])],
            'client_user_id' => ['required_if:client_mode,existing', 'nullable', 'integer', 'exists:users,id'],
            'client_username' => ['required_if:client_mode,new', 'nullable', 'string', 'max:50', 'alpha_dash'],
            'client_password_mode' => ['nullable', Rule::in(['manual', 'auto'])],
            'client_password' => array_merge(['nullable'], EndUserService::portalPasswordValidationRules()),
            'client_full_name' => ['nullable', 'string', 'max:255'],
            'sanaei_client_name' => AccountNameValidator::rules(AccountNameValidator::SANAEI_LABEL_MAX),
            'sanaei_inbound_id' => ['nullable', 'integer', 'min:1'],
            'kyc_verification_id' => ['nullable', 'integer', 'exists:account_kyc_verifications,id'],
        ];
    }

    protected function accountUpdateValidationRules(Account $account): array
    {
        return [
            'remote_username' => array_merge(
                AccountNameValidator::rules(AccountNameValidator::REMOTE_MAX, required: true),
                [Rule::unique('accounts', 'remote_username')->ignore($account->id)],
            ),
            'client_email' => ['nullable', 'email', 'max:255'],
            'server_id' => ['nullable', 'exists:servers,id'],
            'status' => ['required', Rule::enum(\App\Enums\AccountStatus::class)],
        ];
    }

    protected function createAccountViaService(
        Request $request,
        AccountService $accountService,
        ServerSelectionService $serverSelection,
        PackageService $packageService
    ): RedirectResponse {
        $rules = $this->accountValidationRules();

        if ($request->has('owner_seller_id')) {
            $rules['owner_seller_id'] = ['required', 'exists:users,id'];
        }

        $validated = $request->validate($rules);

        $seller = $this->resolveAccountSeller($request, $validated);
        $package = Package::query()->findOrFail($validated['package_id']);

        if ($package->service_type->isSanaei()) {
            $validated = array_merge(
                $validated,
                $request->validate([
                    'sanaei_client_name' => AccountNameValidator::rules(AccountNameValidator::SANAEI_LABEL_MAX, required: true),
                ])
            );
        }

        $this->assertPackageAllowedForSeller($seller, $package);
        $this->assertPackageAvailableForNewAccount($package);
        $this->assertElasticGbValid($package, $validated['data_gb'] ?? null);
        $duration = $packageService->resolveDuration($package, (int) $validated['package_duration_id']);
        $server = $this->resolveServerForCreate($validated, $package, $serverSelection, $packageService);

        $clientPortalPassword = null;

        try {
            [$validated, $clientPortalPassword] = $this->prepareNewClientPasswordForCreate($request, $validated);
            $validated['kyc_actor'] = $request->user();
            $account = $accountService->createAccount($seller, $package, $server, $duration, $validated);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', $exception->getMessage() ?: __('accounts.create_failed'));
        }

        $successMessage = __('app.saved');

        if ($clientPortalPassword !== null && ($validated['client_mode'] ?? '') === 'new') {
            $successMessage .= ' '.__('accounts.client_password_delivery', [
                'username' => $validated['client_username'] ?? '',
                'password' => $clientPortalPassword,
            ]);
        }

        $category = $account->service_type->accountCategory()->value;
        $redirectRoute = $this->accountRoutePrefix().'.accounts.'.$category;

        if (\Illuminate\Support\Facades\Route::has($redirectRoute)) {
            return redirect()
                ->route($redirectRoute)
                ->with('success', $successMessage);
        }

        return redirect()
            ->route($this->accountRoutePrefix().'.accounts.index')
            ->with('success', $successMessage);
    }

    protected function purchasePreviewResponse(
        Request $request,
        GlobalDiscountService $globalDiscountService,
        PackageService $packageService,
        UserPackagePricingService $pricingService,
        AgentSellerMarkupService $markupService,
        AgentFinancialPlanService $financialPlanService,
        ?string $adminCustomCharge = null,
    ): JsonResponse {
        try {
            return $this->buildPurchasePreviewResponse(
                $request,
                $globalDiscountService,
                $packageService,
                $pricingService,
                $markupService,
                $financialPlanService,
                $adminCustomCharge,
            );
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'error' => $exception->getMessage() ?: __('accounts.purchase_preview_failed'),
            ], 500);
        }
    }

    protected function buildPurchasePreviewResponse(
        Request $request,
        GlobalDiscountService $globalDiscountService,
        PackageService $packageService,
        UserPackagePricingService $pricingService,
        AgentSellerMarkupService $markupService,
        AgentFinancialPlanService $financialPlanService,
        ?string $adminCustomCharge = null,
    ): JsonResponse {
        $rules = [
            'package_duration_id' => ['required', 'exists:package_durations,id'],
            'data_gb' => ['nullable', 'numeric', 'min:0.01'],
        ];

        if ($request->filled('owner_seller_id')) {
            $rules['owner_seller_id'] = ['required', 'exists:users,id'];
        }

        $validated = $request->validate($rules);

        $duration = PackageDuration::query()->with('package.category')->findOrFail($validated['package_duration_id']);
        $buyer = $this->resolvePreviewBuyer($request, $validated);
        $package = $duration->package;

        if ($package !== null) {
            $this->assertPackageAvailableForNewAccount($package);
        }
        $gb = $package !== null && $package->isElastic()
            ? $package->clampDataGb((float) ($validated['data_gb'] ?? $package->min_data_gb ?? 1))
            : null;

        try {
            $unitPrice = $pricingService->requireWholesalePrice($buyer, $duration);
            $wholesalePrice = $pricingService->buyerWholesaleTotal($buyer, $duration, $gb);

            if ($adminCustomCharge !== null) {
                $chargedPrice = $adminCustomCharge;
                $chargeBreakdown = [
                    'buyer_charge' => $chargedPrice,
                    'plan_applied' => false,
                    'plan_discount' => '0.00',
                    'plan_wholesale' => '0.00',
                    'slices' => [],
                ];
                $economics = $pricingService->resolvePurchaseEconomics(
                    $buyer,
                    $duration,
                    $chargedPrice,
                    $gb,
                    scaleCommissionsToCharge: true,
                );
            } else {
                $chargeBreakdown = $financialPlanService->resolveCharge($buyer, $wholesalePrice);
                $chargedPrice = $chargeBreakdown['buyer_charge'];
                $economics = $pricingService->resolvePurchaseEconomics($buyer, $duration, $chargedPrice, $gb);
            }
        } catch (\Throwable $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        $isPerGb = $package !== null && $package->isElastic();
        $units = $isPerGb
            ? $pricingService->unitsFor($package, $gb)
            : '1';
        $agentMarginPercent = null;

        if (bccomp($economics['agent_margin'] ?? '0', '0', 2) > 0 && bccomp($economics['agent_wholesale'] ?? '0', '0', 2) > 0) {
            $agentMarginPercent = $markupService->effectiveMarginPercent(
                $economics['agent_wholesale'],
                $economics['agent_margin']
            );
        }

        return response()->json([
            'catalog_price' => number_format((float) $duration->price, 2, '.', ''),
            'is_elastic' => $package !== null && $package->isElastic(),
            'currency' => $package?->moneyCurrency()->value ?? \App\Enums\MoneyCurrency::displayDefault()->value,
            'currency_symbol' => $package?->moneyCurrency()->symbol() ?? \App\Enums\MoneyCurrency::displayDefault()->symbol(),
            'currency_label' => $package?->moneyCurrency()->label() ?? \App\Enums\MoneyCurrency::displayDefault()->label(),
            'currency_decimals' => $package?->moneyCurrency()->displayDecimals() ?? \App\Enums\MoneyCurrency::displayDefault()->displayDecimals(),
            'unit_price' => $unitPrice,
            'units' => $units,
            'pricing_model' => $isPerGb ? 'per_gb' : 'fixed',
            'data_gb' => $gb !== null ? number_format($gb, 2, '.', '') : null,
            'wholesale_price' => $wholesalePrice,
            'list_price' => $wholesalePrice,
            'charged_price' => $chargedPrice,
            'final_charge' => $chargedPrice,
            'agent_margin' => $economics['agent_margin'],
            'agent_wholesale' => $economics['agent_wholesale'],
            'agent_margin_percent' => $agentMarginPercent,
            'markup_policy_percent' => $markupService->isEnabled() ? $markupService->percent() : null,
            'admin_revenue' => $economics['admin_revenue'],
            'discount_active' => $globalDiscountService->isActive() || $chargeBreakdown['plan_applied'],
            'discount_percent' => $globalDiscountService->percent(),
            'plan_applied' => $chargeBreakdown['plan_applied'],
            'plan_discount' => $chargeBreakdown['plan_discount'],
            'plan_wholesale' => $chargeBreakdown['plan_wholesale'],
            'plan_slices' => $chargeBreakdown['slices'],
            'admin_custom_charge' => $adminCustomCharge !== null,
        ]);
    }

    protected function assertElasticGbValid(Package $package, mixed $gb): void
    {
        if (! $package->isElastic()) {
            return;
        }

        if ($gb === null || $gb === '' || (float) $gb <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'data_gb' => [__('packages.elastic_gb_required')],
            ]);
        }

        $min = $package->min_data_gb !== null ? (float) $package->min_data_gb : 1.0;
        $max = $package->max_data_gb !== null ? (float) $package->max_data_gb : null;

        if ((float) $gb < $min || ($max !== null && (float) $gb > $max)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'data_gb' => [__('packages.elastic_gb_out_of_range', [
                    'min' => rtrim(rtrim(number_format($min, 2, '.', ''), '0'), '.'),
                    'max' => $max !== null ? rtrim(rtrim(number_format($max, 2, '.', ''), '0'), '.') : '∞',
                ])],
            ]);
        }
    }

    public function packageOptions(Request $request, PackageService $packageService): JsonResponse
    {
        $this->authorize('create', Account::class);

        if ($request->filled('owner_seller_id')) {
            $request->validate([
                'owner_seller_id' => ['required', 'exists:users,id'],
            ]);
        }

        $seller = $this->resolveClientOwner($request);
        $categoryService = app(PackageCategoryService::class);
        $packages = app(UserPackageAssignmentService::class)
            ->assignedPackagesQuery($seller)
            ->when($categoryService->isAvailable(), fn ($query) => $query->with('category'))
            ->get(['id', 'name', 'package_category_id']);
        $packageGroups = $categoryService->groupPackages($packages);

        return response()->json([
            'packages' => $packages->map(fn (Package $package): array => [
                'id' => $package->id,
                'name' => $package->name,
            ])->values(),
            'packageGroups' => $packageGroups->map(fn (array $group): array => [
                'label' => $group['label'],
                'packages' => $group['packages']->map(fn (Package $package): array => [
                    'id' => $package->id,
                    'name' => $package->name,
                ])->values(),
            ])->values(),
            'packageOptions' => $packageService->accountFormOptions($seller),
        ]);
    }

    public function clientOptions(Request $request, EndUserService $endUserService): JsonResponse
    {
        $this->authorize('create', Account::class);

        if ($request->filled('owner_seller_id')) {
            $request->validate([
                'owner_seller_id' => ['required', 'exists:users,id'],
            ]);
        }

        $owner = $this->resolveClientOwner($request);

        $clients = $endUserService->clientsForOwner($owner)->map(fn (User $client): array => [
            'id' => $client->id,
            'username' => $client->username,
            'full_name' => $client->full_name,
            'label' => trim($client->full_name.' ('.$client->username.')'),
        ])->values();

        return response()->json(['clients' => $clients]);
    }

    protected function resolveClientOwner(Request $request): User
    {
        // پیش‌تر فقط نماینده بررسی می‌شد و بازدیدکننده‌ی فروشنده کلاً از authorize
        // رد می‌شد؛ یعنی هر فروشنده با owner_seller_id یک تنانت دیگر، فهرست کامل
        // مشتریان نهایی و قیمت عمده‌ی بسته‌های آن تنانت (حتی یک نماینده) را
        // می‌گرفت. حالا هر نقشی جز ادمین به زیردرخت خودش محدود است.
        return $this->resolveOwnerSellerForActor($request, $request->input('owner_seller_id'));
    }

    protected function assertPackageAllowedForSeller(User $seller, Package $package): void
    {
        app(UserPackageAssignmentService::class)->assertUserHasPackage($seller, $package);
    }

    protected function assertPackageAvailableForNewAccount(Package $package): void
    {
        if (! app(PackageCategoryService::class)->isPackageAvailableForNewAccounts($package)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'package_id' => [__('packages.package_not_available_for_new_accounts')],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function resolvePreviewBuyer(Request $request, array $validated): User
    {
        // کل خروجی پیش‌نمایش برای همین خریدار محاسبه می‌شود و قیمت عمده، حاشیه سود
        // نماینده، درصد markup و سهم درآمد ادمین را برمی‌گرداند؛ findOrFail بدون
        // محدوده اجازه می‌داد هر نماینده/فروشنده با پیمایش شناسه‌ی کاربر و دوره،
        // کل جدول قیمت و حاشیه سود رقیب را بازسازی کند.
        return $this->resolveOwnerSellerForActor($request, $validated['owner_seller_id'] ?? null);
    }

    /**
     * شناسه‌ی درخواستیِ «مالک/فروشنده» را به یک کاربر مجاز برای همین بازدیدکننده
     * ترجمه می‌کند. ادمین تنها نقشی است که جست‌وجوی بدون محدوده برایش لازم و مجاز
     * است (فرم ساخت اکانت ادمین روی هر نماینده/فروشنده‌ای کار می‌کند). نماینده فقط
     * زیردرخت خودش را می‌بیند و فروشنده هیچ دلیل مشروعی برای اقدام به نام دیگری
     * ندارد، پس کل درخواستش رد می‌شود. جایگزینیِ خاموشِ خریدار انجام نمی‌شود چون
     * یک فروش واقعی را اشتباه قیمت‌گذاری می‌کرد.
     */
    protected function resolveOwnerSellerForActor(Request $request, mixed $requestedId): User
    {
        $actor = $request->user();

        if ($requestedId === null || $requestedId === '' || (int) $requestedId === (int) $actor->id) {
            return $actor;
        }

        $requestedId = (int) $requestedId;

        if ($actor->role === \App\Enums\UserRole::Admin) {
            return User::query()->findOrFail($requestedId);
        }

        if ($actor->role !== \App\Enums\UserRole::Agent) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'owner_seller_id' => [__('accounts.owner_seller_not_allowed')],
            ]);
        }

        $target = User::query()
            ->whereIn('id', User::subtreeUserIds($actor))
            ->find($requestedId);

        if ($target === null) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'owner_seller_id' => [__('accounts.owner_seller_not_allowed')],
            ]);
        }

        // بررسی سیاست هم نگه داشته می‌شود تا محدودیت نقش‌ها (UserPolicy::view) ضعیف نشود.
        $this->authorize('view', $target);

        return $target;
    }

    protected function updateAccountViaService(
        Request $request,
        Account $account,
        AccountService $accountService,
        AccountTransferService $transferService,
        PackageService $packageService
    ): RedirectResponse {
        $validated = $request->validate($this->accountUpdateValidationRules($account));

        $newServerId = isset($validated['server_id']) ? (int) $validated['server_id'] : null;

        try {
            if ($newServerId !== null && $newServerId !== (int) $account->server_id) {
                $this->authorize('transferServer', $account);
                $newServer = Server::query()->findOrFail($newServerId);
                $packageService->assertServerAllowed($account->package, $newServer);
                $transferService->transfer($account, $newServer, $request->user());
            }

            $account->update([
                'remote_username' => $validated['remote_username'],
                'client_email' => $validated['client_email'],
                'status' => $validated['status'],
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route($this->accountRoutePrefix().'.accounts.'.$account->service_type->accountCategory()->value)
            ->with('success', __('app.saved'));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function resolveServerForCreate(
        array $validated,
        Package $package,
        ServerSelectionService $serverSelection,
        PackageService $packageService
    ): Server {
        if (! empty($validated['server_id'])) {
            $server = Server::query()->findOrFail($validated['server_id']);
            $packageService->assertServerAllowed($package, $server);

            return $server;
        }

        return $serverSelection->pickLeastBusyForPackage($package);
    }

    protected function renewAccountViaService(
        Account $account,
        AccountService $accountService,
        ?int $durationId = null,
        ?Request $request = null,
    ): RedirectResponse {
        $request ??= request();
        $duration = null;

        $account->loadMissing(['package.category']);

        if ($account->package === null
            || ! app(\App\Services\PackageCategoryService::class)->isPackageAvailableForRenewal($account->package)) {
            return back()->with('error', __('packages.package_not_available_for_renewal'));
        }

        if ($durationId !== null) {
            $duration = PackageDuration::query()
                ->where('id', $durationId)
                ->where('is_enabled', true)
                ->first();

            if ($duration === null) {
                return back()->with('error', __('packages.duration_not_available'));
            }

            try {
                app(\App\Services\AccountBillingPackageService::class)
                    ->assertDurationBelongsToBillingPackage($account, $duration);
            } catch (\Throwable $exception) {
                return back()->with('error', $exception->getMessage());
            }
        }

        $renewalPricing = app(\App\Services\AccountRenewalPricingService::class);
        $billingPackageService = app(\App\Services\AccountBillingPackageService::class);
        $renewalGbOverride = null;

        try {
            $account = $billingPackageService->syncBillingPackage($account, $duration);
            $account->loadMissing('package');
            $package = $account->package;

            if ($package !== null && $package->isElastic()) {
                $mode = (string) $request->input('renewal_mode', 'same');
                $renewalGbOverride = $renewalPricing->resolveRenewalDataGb(
                    $account,
                    $mode,
                    $request->input('data_gb'),
                );
            }
        } catch (\Throwable $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        $renewalMode = (string) $request->input('renewal_mode', 'same');

        try {
            $accountService->renewAccount(
                $account,
                $duration,
                \App\Enums\AccountBillingContext::Staff,
                $request->user(),
                $renewalGbOverride,
                $renewalMode,
            );
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('app.renewed'));
    }

    protected function toggleAccountViaService(Account $account, AccountService $accountService, string $action): RedirectResponse
    {
        try {
            match ($action) {
                'disable' => $accountService->disableAccount($account),
                'enable' => $accountService->enableAccount($account),
                default => throw new \InvalidArgumentException('Invalid action'),
            };
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('app.saved'));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    protected function prepareNewClientPasswordForCreate(Request $request, array $validated): array
    {
        if (($validated['client_mode'] ?? 'new') !== 'new') {
            return [$validated, null];
        }

        $mode = $validated['client_password_mode'] ?? 'auto';

        if ($mode === 'manual') {
            $request->validate([
                'client_password' => EndUserService::portalPasswordValidationRules(required: true),
            ]);
            $validated['client_password'] = (string) $request->input('client_password');

            return [$validated, $validated['client_password']];
        }

        $password = app(EndUserService::class)->generatePortalPassword();
        $validated['client_password'] = $password;
        $validated['client_password_mode'] = 'auto';

        return [$validated, $password];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    abstract protected function resolveAccountSeller(Request $request, array $validated): User;

    abstract protected function accountRoutePrefix(): string;
}
