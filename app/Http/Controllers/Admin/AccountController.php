<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ListsAccountsByCategory;
use App\Http\Controllers\Concerns\ManagesAccountKyc;
use App\Http\Controllers\Concerns\ManagesAccountLoginSms;
use App\Http\Controllers\Concerns\ManagesAccounts;
use App\Http\Controllers\Concerns\ManagesPortalLinks;
use App\Http\Controllers\Concerns\ManagesPppAccountActions;
use App\Http\Controllers\Concerns\ManagesSimplifiedStaffAccounts;
use App\Http\Controllers\Concerns\ManagesWireguardAccountActions;
use App\Http\Controllers\Concerns\ProvidesAccountReport;
use App\Http\Controllers\Controller;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Models\Invoice;
use App\Enums\InvoiceType;
use App\Services\AccountStaffBillingAdjustmentService;
use App\Services\AccountService;
use App\Services\AccountTransferService;
use App\Services\AgentFinancialPlanService;
use App\Services\AgentSellerMarkupService;
use App\Services\GlobalDiscountService;
use App\Services\PackageService;
use App\Services\ServerSelectionService;
use App\Services\UserPackagePricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountController extends Controller
{
    use ManagesAccountKyc;
    use ManagesAccountLoginSms;
    use ManagesAccounts;
    use ManagesPortalLinks;
    use ManagesPppAccountActions;
    use ManagesSimplifiedStaffAccounts;
    use ManagesWireguardAccountActions;
    use ProvidesAccountReport;
    use ListsAccountsByCategory;

    public function index(Request $request): \Illuminate\Http\RedirectResponse
    {
        return redirect()->route('admin.accounts.wireguard', $request->query());
    }

    public function create(PackageService $packageService): View
    {
        $this->authorize('create', Account::class);

        $accountOwners = User::query()
            ->whereIn('role', [UserRole::Agent, UserRole::Seller])
            ->orderBy('full_name')
            ->get();
        $defaultOwner = $accountOwners->first();
        $packages = $defaultOwner
            ? app(\App\Services\UserPackageAssignmentService::class)
                ->assignedPackagesQuery($defaultOwner)
                ->when(app(\App\Services\PackageCategoryService::class)->isAvailable(), fn ($query) => $query->with('category'))
                ->get()
            : collect();
        $servers = Server::query()->active()->orderBy('name')->get();
        $packageOptions = $defaultOwner
            ? $packageService->accountFormOptions($defaultOwner)
            : [];
        $autoServer = null;

        return view('admin.accounts.create', compact('accountOwners', 'packages', 'servers', 'packageOptions', 'autoServer'));
    }

    public function store(
        Request $request,
        AccountService $accountService,
        ServerSelectionService $serverSelection,
        PackageService $packageService
    ): RedirectResponse {
        $this->authorize('create', Account::class);

        $rules = [
            'owner_seller_id' => [
                'required',
                Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', ['agent', 'seller'])),
            ],
            'package_id' => ['required', 'exists:packages,id'],
            'package_duration_id' => ['required', 'exists:package_durations,id'],
            'data_gb' => ['nullable', 'numeric', 'min:0.01'],
            'server_id' => ['nullable', 'exists:servers,id'],
            'client_mode' => ['required', Rule::in(['existing', 'display_name'])],
            'client_user_id' => ['required_if:client_mode,existing', 'nullable', 'integer', 'exists:users,id'],
            'account_display_name' => ['required_if:client_mode,display_name', 'nullable', 'string', 'max:255'],
            'admin_custom_charge' => ['nullable', 'numeric', 'min:0'],
            'admin_free_account' => ['sometimes', 'boolean'],
            'kyc_verification_id' => ['nullable', 'integer', 'exists:account_kyc_verifications,id'],
        ];

        $validated = $request->validate($rules);

        $seller = User::query()->findOrFail((int) $validated['owner_seller_id']);
        $package = Package::query()->findOrFail($validated['package_id']);

        $this->assertPackageAllowedForSeller($seller, $package);
        $this->assertPackageAvailableForNewAccount($package);
        $this->assertElasticGbValid($package, $validated['data_gb'] ?? null);

        $duration = $packageService->resolveDuration($package, (int) $validated['package_duration_id']);
        $server = $this->resolveServerForCreate($validated, $package, $serverSelection, $packageService);

        $clientData = $validated;
        $clientData['kyc_actor'] = $request->user();

        if ($validated['client_mode'] === 'display_name') {
            $clientData['skip_portal_client'] = true;
            $clientData['display_label'] = $validated['account_display_name'];
            $clientData['auto_random_remote_identity'] = true;
        }

        $adminCustomCharge = $this->resolveAdminCustomCharge($validated);

        try {
            $account = $accountService->createAccount(
                $seller,
                $package,
                $server,
                $duration,
                $clientData,
                adminCustomCharge: $adminCustomCharge,
            );
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', $exception->getMessage() ?: __('accounts.create_failed'));
        }

        $category = $account->service_type->accountCategory()->value;
        $redirectRoute = 'admin.accounts.'.$category;

        if (\Illuminate\Support\Facades\Route::has($redirectRoute)) {
            return redirect()
                ->route($redirectRoute)
                ->with('success', __('app.saved'));
        }

        return redirect()
            ->route('admin.accounts.index')
            ->with('success', __('app.saved'));
    }

    public function show(Account $account, \App\Services\ClientAccountDetailService $detailService): View
    {
        return $this->showStaffAccount($account, $detailService);
    }

    public function purchasePreview(
        Request $request,
        GlobalDiscountService $globalDiscountService,
        PackageService $packageService,
        UserPackagePricingService $pricingService,
        AgentSellerMarkupService $markupService,
        AgentFinancialPlanService $financialPlanService,
    ): JsonResponse {
        $this->authorize('create', Account::class);

        // The preview must resolve the charge exactly like store() does, otherwise the
        // admin is quoted one price and the wallet is debited another.
        $adminCustomCharge = $this->resolveAdminCustomCharge($request->validate([
            'admin_custom_charge' => ['nullable', 'numeric', 'min:0'],
            'admin_free_account' => ['sometimes', 'boolean'],
        ]));

        return $this->purchasePreviewResponse(
            $request,
            $globalDiscountService,
            $packageService,
            $pricingService,
            $markupService,
            $financialPlanService,
            $adminCustomCharge,
        );
    }

    /**
     * Resolve the admin-only price override for a new account.
     *
     * An empty field, a null, or a stray "0" all mean "charge the normal wholesale
     * price": returning null makes AccountService fall through to buyerWholesaleTotal().
     * Treating "0" as an override is what previously made every such account free and
     * skipped the wallet debit entirely — no transaction row was written, so the refund
     * path later had no ledger to work from either.
     *
     * A free account is a deliberate act and needs the explicit admin_free_account
     * checkbox, which returns '0.00' so the override branch runs with a zero charge.
     * Any positive amount is a genuine custom price.
     *
     * @param  array<string, mixed>  $input
     */
    protected function resolveAdminCustomCharge(array $input): ?string
    {
        if (filter_var($input['admin_free_account'] ?? false, FILTER_VALIDATE_BOOL)) {
            return '0.00';
        }

        $raw = $input['admin_custom_charge'] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        $charge = money_string((string) $raw);

        return bccomp($charge, '0', 2) > 0 ? $charge : null;
    }

    public function edit(Account $account): View
    {
        $this->authorize('update', $account);

        $account->load(['ownerSeller', 'package.servers', 'packageDuration', 'server']);
        $servers = $account->package
            ? $account->package->servers()->active()->orderBy('name')->get()
            : Server::query()->active()->orderBy('name')->get();

        $purchaseInvoice = Invoice::query()
            ->where('account_id', $account->id)
            ->where('type', InvoiceType::NewAccount)
            ->orderByDesc('issued_at')
            ->first();

        return view('admin.accounts.edit', compact('account', 'servers', 'purchaseInvoice'));
    }

    public function update(
        Request $request,
        Account $account,
        AccountService $accountService,
        AccountTransferService $transferService,
        PackageService $packageService,
        AccountStaffBillingAdjustmentService $billingAdjustmentService,
    ): RedirectResponse {
        $this->authorize('update', $account);

        $validated = $request->validate([
            'remote_username' => array_merge(
                \App\Support\AccountNameValidator::rules(\App\Support\AccountNameValidator::REMOTE_MAX, required: true),
                [Rule::unique('accounts', 'remote_username')->ignore($account->id)],
            ),
            'client_email' => ['nullable', 'email', 'max:255'],
            'display_label' => ['nullable', 'string', 'max:255'],
            'server_id' => ['nullable', 'exists:servers,id'],
            'status' => ['required', Rule::enum(\App\Enums\AccountStatus::class)],
            'staff_charge' => ['nullable', 'numeric', 'min:0'],
            'expiry_unlimited' => ['nullable', 'boolean'],
            'expiry_jalali' => ['nullable', 'string', 'max:20'],
            'expiry_add_days' => ['nullable', 'integer', 'min:-3650', 'max:3650'],
            'expiry_add_months' => ['nullable', 'integer', 'min:-120', 'max:120'],
        ]);

        $newServerId = isset($validated['server_id']) ? (int) $validated['server_id'] : null;

        try {
            if ($newServerId !== null && $newServerId !== (int) $account->server_id) {
                $this->authorize('transferServer', $account);
                $newServer = Server::query()->findOrFail($newServerId);
                $packageService->assertServerAllowed($account->package, $newServer);
                $transferService->transfer($account, $newServer, $request->user());
            }

            // Status is applied last, through the enable/disable services, so it
            // is pushed to the real server too — a raw status write would leave
            // the account active/disabled only in the panel.
            $account->update([
                'remote_username' => $validated['remote_username'],
                'client_email' => $validated['client_email'],
                'display_label' => $validated['display_label'] ?? $account->display_label,
            ]);

            // The account's purchase amount is also its fixed renewal price: set
            // it here (0 = free renewals) so the admin sets one number, not two.
            if (array_key_exists('staff_charge', $validated)
                && $validated['staff_charge'] !== null
                && $validated['staff_charge'] !== '') {
                $charge = money_string((string) $validated['staff_charge']);

                $billingAdjustmentService->adjustPurchasePrice(
                    $account->fresh(),
                    $charge,
                    $request->user(),
                );

                $account->update(['renewal_charge_override' => $charge]);
            }

            $newExpiry = $this->resolveAdminExpiryUpdate($request, $account);

            if ($newExpiry !== false) {
                $accountService->updateExpiryByAdmin($account->fresh(), $newExpiry, $request->user());
            }

            $this->applyAdminStatusChange(
                $account->fresh(),
                \App\Enums\AccountStatus::from($validated['status']),
                $accountService,
            );
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('admin.accounts.'.$account->service_type->accountCategory()->value)
            ->with('success', __('app.saved'));
    }

    /**
     * Move the account to the status the admin picked, going through the
     * enable/disable services so the change reaches the remote server. Enabling
     * refuses an account that is still expired or out of quota (the service
     * throws), which is the correct outcome — activate needs a valid account.
     */
    protected function applyAdminStatusChange(
        Account $account,
        \App\Enums\AccountStatus $desired,
        AccountService $accountService,
    ): void {
        if ($account->status === $desired) {
            return;
        }

        match ($desired) {
            \App\Enums\AccountStatus::Active => $accountService->enableAccount($account),
            \App\Enums\AccountStatus::Disabled => $accountService->disableAccount($account),
            \App\Enums\AccountStatus::Exhausted => $accountService->disableAccount($account, exhausted: true),
            default => $account->update(['status' => $desired]),
        };
    }

    public function renew(Request $request, Account $account, AccountService $accountService): RedirectResponse
    {
        $this->authorize('update', $account);

        $durationId = $request->filled('package_duration_id')
            ? (int) $request->input('package_duration_id')
            : null;

        return $this->renewAccountViaService($account, $accountService, $durationId, $request);
    }

    public function disable(Account $account, AccountService $accountService): RedirectResponse
    {
        $this->authorize('update', $account);

        return $this->toggleAccountViaService($account, $accountService, 'disable');
    }

    public function enable(Account $account, AccountService $accountService): RedirectResponse
    {
        $this->authorize('update', $account);

        return $this->toggleAccountViaService($account, $accountService, 'enable');
    }

    public function destroy(Account $account, AccountService $accountService): RedirectResponse
    {
        $this->authorize('delete', $account);

        $listRoute = $this->safeAccountsListRoute($account);

        try {
            $remoteWarning = $accountService->deleteAccount($account, request()->user());
        } catch (\Throwable $exception) {
            report($exception);

            return redirect($listRoute)
                ->with('error', $exception->getMessage() ?: __('accounts.delete_failed'));
        }

        $redirect = redirect($listRoute)->with('success', __('app.deleted'));

        if ($remoteWarning !== null) {
            $redirect->with('warning', $remoteWarning);
        }

        return $redirect;
    }

    protected function resolveAccountSeller(Request $request, array $validated): User
    {
        return User::query()->findOrFail($request->input('owner_seller_id'));
    }

    protected function accountRoutePrefix(): string
    {
        return 'admin';
    }

    /**
     * @return \Illuminate\Support\Carbon|null|false  false = no change
     */
    protected function resolveAdminExpiryUpdate(Request $request, Account $account): \Illuminate\Support\Carbon|null|false
    {
        if ($request->boolean('expiry_unlimited')) {
            return null;
        }

        $jalali = trim((string) $request->input('expiry_jalali', ''));

        if ($jalali !== '') {
            $parsed = parse_jalali_date($jalali, endOfDay: true);

            if ($parsed === null) {
                throw new \InvalidArgumentException(__('accounts.expiry_jalali_invalid'));
            }

            return $parsed;
        }

        $addDays = (int) $request->input('expiry_add_days', 0);
        $addMonths = (int) $request->input('expiry_add_months', 0);

        if ($addDays === 0 && $addMonths === 0) {
            return false;
        }

        $base = $account->expiry_at !== null && $account->expiry_at->isFuture()
            ? $account->expiry_at->copy()
            : now();

        if ($addMonths !== 0) {
            $base = $addMonths > 0 ? $base->copy()->addMonths($addMonths) : $base->copy()->subMonths(abs($addMonths));
        }

        if ($addDays !== 0) {
            $base = $addDays > 0 ? $base->copy()->addDays($addDays) : $base->copy()->subDays(abs($addDays));
        }

        return $base->copy()->endOfDay();
    }
}
