<?php

namespace App\Http\Controllers\Seller;

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
use App\Models\Account;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AgentFinancialPlanService;
use App\Services\AgentSellerMarkupService;
use App\Services\GlobalDiscountService;
use App\Services\UserPackagePricingService;
use App\Services\PackageService;
use App\Services\ServerSelectionService;
use App\Services\UserPackageAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        return redirect()->route('seller.accounts.wireguard', $request->query());
    }

    public function create(Request $request): RedirectResponse
    {
        $this->authorize('create', Account::class);

        $category = \App\Enums\AccountCategory::tryFrom((string) $request->query('category'));

        return redirect()->route('seller.accounts.'.($category?->value ?? 'wireguard'), ['create' => 1]);
    }

    public function store(
        Request $request,
        AccountService $accountService,
        ServerSelectionService $serverSelection,
        PackageService $packageService,
        \App\Services\EndUserService $endUserService,
    ): RedirectResponse {
        $this->authorize('create', Account::class);

        return $this->createSimplifiedStaffAccount(
            $request,
            $accountService,
            $serverSelection,
            $packageService,
            $endUserService,
        );
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

        return $this->purchasePreviewResponse(
            $request,
            $globalDiscountService,
            $packageService,
            $pricingService,
            $markupService,
            $financialPlanService,
        );
    }

    public function edit(Account $account): View
    {
        $this->authorize('update', $account);

        $account->load(['package', 'packageDuration', 'server']);

        return view('seller.accounts.edit', compact('account'));
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

    protected function resolveAccountSeller(Request $request, array $validated): User
    {
        return $request->user();
    }

    protected function accountRoutePrefix(): string
    {
        return 'seller';
    }
}
