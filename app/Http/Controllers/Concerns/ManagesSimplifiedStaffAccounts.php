<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Account;
use App\Models\Package;
use App\Models\Server;
use App\Models\User;
use App\Services\AccountService;
use App\Services\EndUserService;
use App\Services\PackageService;
use App\Services\PortalLinkService;
use App\Services\ServerSelectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

trait ManagesSimplifiedStaffAccounts
{
    public function createSimplifiedStaffAccount(
        Request $request,
        AccountService $accountService,
        ServerSelectionService $serverSelection,
        PackageService $packageService,
        EndUserService $endUserService,
    ): RedirectResponse {
        $rules = [
            'account_display_name' => ['required', 'string', 'max:255'],
            'client_mode' => ['required', Rule::in(['existing', 'auto'])],
            'client_user_id' => ['required_if:client_mode,existing', 'nullable', 'integer', 'exists:users,id'],
            'package_id' => ['required', 'exists:packages,id'],
            'package_duration_id' => ['required', 'exists:package_durations,id'],
            'data_gb' => ['nullable', 'numeric', 'min:0.01'],
            'kyc_verification_id' => ['nullable', 'integer', 'exists:account_kyc_verifications,id'],
        ];

        if ($this->accountRoutePrefix() === 'agent') {
            $rules['owner_seller_id'] = ['required', 'exists:users,id'];
        }

        if ($request->filled('data_gb')) {
            $request->merge(['data_gb' => western_digits($request->input('data_gb'))]);
        }

        $validated = $request->validate($rules);

        $seller = $this->resolveAccountSeller($request, $validated);
        $package = Package::query()->findOrFail($validated['package_id']);

        $this->assertPackageAllowedForSeller($seller, $package);
        $this->assertPackageAvailableForNewAccount($package);
        $this->assertElasticGbValid($package, $validated['data_gb'] ?? null);

        $duration = $packageService->resolveDuration($package, (int) $validated['package_duration_id']);
        $server = $this->resolveServerForCreate([], $package, $serverSelection, $packageService);

        $clientData = [
            'display_label' => $validated['account_display_name'],
            'auto_random_remote_identity' => true,
            'kyc_verification_id' => $validated['kyc_verification_id'] ?? null,
            'kyc_actor' => $request->user(),
        ];

        if ($package->isElastic()) {
            $clientData['data_gb'] = $package->clampDataGb((float) $validated['data_gb']);
        }

        try {
            if ($validated['client_mode'] === 'auto') {
                $autoClient = $endUserService->createAutoClientForAccount(
                    $seller,
                    $validated['account_display_name'],
                );
                $clientData['client_mode'] = 'existing';
                $clientData['client_user_id'] = $autoClient['user']->id;
                $clientData['store_portal_password'] = $autoClient['password'];
            } else {
                $clientData['client_mode'] = 'existing';
                $clientData['client_user_id'] = (int) $validated['client_user_id'];
            }

            $account = $accountService->createAccount($seller, $package, $server, $duration, $clientData);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('open_create_modal', true)
                ->with('error', $exception->getMessage() ?: __('accounts.create_failed'));
        }

        $category = $account->service_type->accountCategory()->value;
        $redirectRoute = $this->accountRoutePrefix().'.accounts.'.$category;

        if (\Illuminate\Support\Facades\Route::has($redirectRoute)) {
            return redirect()
                ->route($redirectRoute)
                ->with('success', __('app.saved'));
        }

        return redirect()
            ->route($this->accountRoutePrefix().'.accounts.index')
            ->with('success', __('app.saved'));
    }

    public function showStaffAccount(Account $account, \App\Services\ClientAccountDetailService $detailService): \Illuminate\View\View
    {
        $this->authorize('view', $account);

        $account->loadMissing(['clientUser', 'kycVerification']);
        $detail = $detailService->build($account, $account->clientUser);
        $prefix = $this->accountRoutePrefix();
        $portalLinks = app(PortalLinkService::class);

        $detail['wireguard']['config_download_route'] = $account->service_type === \App\Enums\ServiceType::Wireguard && \Route::has($prefix.'.accounts.config.download')
            ? route($prefix.'.accounts.config.download', $account)
            : null;
        $detail['wireguard']['qr_download_route'] = $account->service_type === \App\Enums\ServiceType::Wireguard && \Route::has($prefix.'.accounts.config.qr')
            ? route($prefix.'.accounts.config.qr', $account)
            : null;

        if ($detail['isPpp'] ?? false) {
            $detail['ppp']['ovpn_download_route'] = ($account->server?->hasOvpnProfile() && \Route::has($prefix.'.accounts.ovpn.download'))
                ? route($prefix.'.accounts.ovpn.download', $account)
                : null;
        }

        return view('shared.clients.account-show', array_merge($detail, [
            'client' => $account->clientUser,
            'panel' => $prefix,
            'backUrl' => route($prefix.'.accounts.'.$account->service_type->accountCategory()->value),
            'renewFormRoute' => \Route::has($prefix.'.accounts.renew-form')
                ? route($prefix.'.accounts.renew-form', $account)
                : null,
            'portalIssueUrl' => \Route::has($prefix.'.accounts.portal-link')
                ? route($prefix.'.accounts.portal-link', $account)
                : null,
            'portalActiveUrl' => $portalLinks->publicUrlIfActive($account),
            'portalLinkTtlMinutes' => $portalLinks->ttlMinutes(),
            'portalLinkTtlLabel' => $portalLinks->ttlLabel(),
            'portalActiveExpiresAt' => $portalLinks->expiresAtIfActive($account),
            'viewerIsClient' => false,
            'kycVerification' => $account->kycVerification,
            'viewerIsAdmin' => $prefix === 'admin',
        ]));
    }
}
