<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\ServiceType;
use App\Models\Account;
use App\Models\PackageDuration;
use App\Models\Server;
use App\Services\AccountBillingPackageService;
use App\Services\AccountRefundService;
use App\Services\AccountRenewalPricingService;
use App\Services\AccountService;
use App\Services\AccountTransferService;
use App\Services\GlobalDiscountService;
use App\Services\PackageService;
use App\Services\WireGuardConfigService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

trait ManagesWireguardAccountActions
{
    public function showConfig(Account $account, WireGuardConfigService $configService): View
    {
        $this->authorize('view', $account);
        $this->assertWireguard($account);

        $account->load(['package', 'server', 'packageDuration']);

        try {
            $config = $configService->buildConfig($account);
            $qrBase64 = base64_encode($configService->buildQrPng($account));
        } catch (\Throwable $exception) {
            report($exception);

            return view('shared.accounts.config-error', [
                'account' => $account,
                'prefix' => $this->accountRoutePrefix(),
                'error' => $exception->getMessage(),
            ]);
        }

        return view('shared.accounts.config', [
            'account' => $account,
            'config' => $config,
            'qrBase64' => $qrBase64,
            'prefix' => $this->accountRoutePrefix(),
        ]);
    }

    public function downloadConfig(Account $account, WireGuardConfigService $configService): Response
    {
        $this->authorize('view', $account);
        $this->assertWireguard($account);

        $config = $configService->buildConfig($account);

        return response($config, 200, [
            'Content-Type' => 'application/x-wireguard-profile',
            'Content-Disposition' => 'attachment; filename="'.$configService->configFilename($account).'"',
        ]);
    }

    public function downloadQr(Account $account, WireGuardConfigService $configService): Response
    {
        $this->authorize('view', $account);
        $this->assertWireguard($account);

        return $configService->qrDownloadResponse($account);
    }

    public function showRenew(Account $account, AccountRenewalPricingService $renewalPricing, AccountBillingPackageService $billingPackageService): View|RedirectResponse
    {
        $this->authorize('update', $account);

        $account->load(['package.category', 'packageDuration', 'ownerSeller', 'server']);

        $actor = auth()->user();

        if ($actor === null) {
            abort(403);
        }

        if ($account->package === null
            || ! app(\App\Services\PackageCategoryService::class)->isPackageAvailableForRenewal($account->package)) {
            return back()->with('error', __('packages.package_not_available_for_renewal'));
        }

        try {
            $account = $billingPackageService->syncBillingPackage($account);
            $account->load(['package.durations']);
            $buyer = $renewalPricing->resolveRenewalBuyer($actor, $account);

            $durations = $account->package->durations()
                ->where('is_enabled', true)
                ->orderBy('sort_order')
                ->get()
                ->map(function (PackageDuration $duration) use ($account, $buyer, $renewalPricing) {
                    $quote = $renewalPricing->quote($buyer, $account, $duration, null, 'same');
                    $duration->display_price = $quote['charged_total'];
                    $duration->renewal_quote = $quote;

                    return $duration;
                });
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }

        if ($durations->isEmpty()) {
            return back()->with('error', __('packages.duration_not_available'));
        }

        return view('shared.accounts.renew', [
            'account' => $account,
            'durations' => $durations,
            'prefix' => $this->accountRoutePrefix(),
            'renewalGb' => $renewalPricing->billableDataGb($account),
            'pricingModel' => $account->package?->isElastic() ? 'per_gb' : 'fixed',
            'renewalBuyer' => $buyer,
            'isElastic' => (bool) $account->package?->isElastic(),
            'packageMinGb' => $account->package?->min_data_gb,
            'packageMaxGb' => $account->package?->max_data_gb,
        ]);
    }

    public function renewPreview(
        Request $request,
        Account $account,
        AccountRenewalPricingService $renewalPricing,
        AccountBillingPackageService $billingPackageService,
    ): \Illuminate\Http\JsonResponse {
        $this->authorize('update', $account);

        $validated = $request->validate([
            'package_duration_id' => ['required', 'exists:package_durations,id'],
            'renewal_mode' => ['nullable', 'in:same,upgrade_volume,add_volume'],
            'data_gb' => ['nullable', 'numeric', 'min:0.01'],
        ]);

        $actor = auth()->user();

        if ($actor === null) {
            abort(403);
        }

        $account->loadMissing(['package.category']);

        if ($account->package === null
            || ! app(\App\Services\PackageCategoryService::class)->isPackageAvailableForRenewal($account->package)) {
            return response()->json(['error' => __('packages.package_not_available_for_renewal')], 422);
        }

        try {
            $account = $billingPackageService->syncBillingPackage($account);
            $buyer = $renewalPricing->resolveRenewalBuyer($actor, $account);
            $duration = PackageDuration::query()->with('package')->findOrFail($validated['package_duration_id']);
            $billingPackageService->assertDurationBelongsToBillingPackage($account, $duration);

            $gbOverride = null;
            $renewalMode = (string) ($validated['renewal_mode'] ?? 'same');
            if ($account->package?->isElastic()) {
                $gbOverride = $renewalPricing->resolveRenewalDataGb(
                    $account,
                    $renewalMode,
                    $validated['data_gb'] ?? null,
                );
            }

            $quote = $renewalPricing->quote($buyer, $account, $duration, $gbOverride, $renewalMode);
        } catch (\Throwable $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json($quote);
    }

    public function showTransfer(Account $account): View
    {
        $this->authorize('transferServer', $account);

        $account->load(['package.servers', 'server']);
        $servers = $account->package->servers()->active()->orderBy('name')->get();

        return view('shared.accounts.transfer', [
            'account' => $account,
            'servers' => $servers,
            'prefix' => $this->accountRoutePrefix(),
        ]);
    }

    public function storeTransfer(
        Request $request,
        Account $account,
        AccountTransferService $transferService,
        PackageService $packageService
    ): RedirectResponse {
        $this->authorize('transferServer', $account);

        $validated = $request->validate([
            'server_id' => ['required', 'exists:servers,id'],
        ]);

        $newServer = Server::query()->findOrFail($validated['server_id']);

        if ((int) $newServer->id === (int) $account->server_id) {
            return back()->with('warning', __('accounts.same_server'));
        }

        try {
            $packageService->assertServerAllowed($account->package, $newServer);
            $transferService->transfer($account, $newServer, $request->user());
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route($this->accountRoutePrefix().'.accounts.'.$account->service_type->accountCategory()->value)
            ->with('success', __('accounts.transfer_success'));
    }

    public function refund(Account $account, AccountRefundService $refundService): RedirectResponse
    {
        $this->authorize('refund', $account);

        try {
            $result = $refundService->refund($account, request()->user());
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }

        $currency = $account->package?->moneyCurrency()
            ?? \App\Enums\MoneyCurrency::default();

        return back()->with('success', __('accounts.refund_success', [
            'amount' => format_money($result['owner_refund_amount'], $currency),
            'owner' => $result['owner']->full_name,
        ]));
    }

    public function reactivate(Account $account, AccountRefundService $refundService): RedirectResponse
    {
        $this->authorize('reactivateAfterRefund', $account);

        try {
            $result = $refundService->reactivate($account, request()->user());
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }

        $currency = $account->package?->moneyCurrency()
            ?? \App\Enums\MoneyCurrency::default();

        return back()->with('success', __('accounts.reactivate_success', [
            'amount' => format_money($result['owner_refund_amount'], $currency),
            'owner' => $result['owner']->full_name,
        ]));
    }

    protected function assertWireguard(Account $account): void
    {
        if ($account->service_type !== ServiceType::Wireguard) {
            abort(404);
        }
    }
}
