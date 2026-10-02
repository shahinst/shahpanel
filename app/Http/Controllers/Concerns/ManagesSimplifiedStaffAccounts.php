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
    /**
     * Several accounts of the same package in one go -- a customer who buys ten
     * WireGuard or OpenVPN accounts no longer has to be served one form at a time.
     */
    public function bulkStore(
        Request $request,
        AccountService $accountService,
        ServerSelectionService $serverSelection,
        PackageService $packageService,
        EndUserService $endUserService,
    ): RedirectResponse {
        $this->authorize('create', Account::class);

        return $this->createSimplifiedStaffAccount(
            $request,
            $accountService,
            $serverSelection,
            $packageService,
            $endUserService,
            bulk: true,
        );
    }

    public function createSimplifiedStaffAccount(
        Request $request,
        AccountService $accountService,
        ServerSelectionService $serverSelection,
        PackageService $packageService,
        EndUserService $endUserService,
        bool $bulk = false,
    ): RedirectResponse {
        $maxBulk = max(2, (int) config('shahpanel.bulk_account_max', 50));

        $rules = [
            'account_display_name' => ['required', 'string', 'max:240'],
            'client_mode' => ['required', Rule::in(['existing', 'auto'])],
            'client_user_id' => ['required_if:client_mode,existing', 'nullable', 'integer', 'exists:users,id'],
            'package_id' => ['required', 'exists:packages,id'],
            'package_duration_id' => ['required', 'exists:package_durations,id'],
            'data_gb' => ['nullable', 'numeric', 'min:0.01'],
            'kyc_verification_id' => ['nullable', 'integer', 'exists:account_kyc_verifications,id'],
        ];

        if (in_array($this->accountRoutePrefix(), ['agent', 'admin'], true)) {
            $rules['owner_seller_id'] = ['required', 'exists:users,id'];
        }

        if ($bulk) {
            $rules['account_count'] = ['required', 'integer', 'min:2', 'max:'.$maxBulk];
            $rules['bulk_client_mode'] = ['nullable', Rule::in(['shared', 'separate'])];
        }

        foreach (['data_gb', 'account_count'] as $numericField) {
            if ($request->filled($numericField)) {
                $request->merge([$numericField => western_digits($request->input($numericField))]);
            }
        }

        $validated = $request->validate($rules);

        $seller = $this->resolveAccountSeller($request, $validated);

        // Accounts belong to an agent or a seller; the admin picks one of them.
        if (! in_array($seller->role, [\App\Enums\UserRole::Agent, \App\Enums\UserRole::Seller], true)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'owner_seller_id' => [__('accounts.owner_seller_not_allowed')],
            ]);
        }

        $package = Package::query()->findOrFail($validated['package_id']);

        $this->assertPackageAllowedForSeller($seller, $package);
        $this->assertPackageAvailableForNewAccount($package);
        $this->assertElasticGbValid($package, $validated['data_gb'] ?? null);

        $duration = $packageService->resolveDuration($package, (int) $validated['package_duration_id']);

        $count = $bulk ? (int) $validated['account_count'] : 1;
        $sharedClient = ! $bulk || ($validated['bulk_client_mode'] ?? 'shared') === 'shared';
        $baseName = trim((string) $validated['account_display_name']);

        if ($count > 1) {
            @set_time_limit(max(300, $count * 20));
        }

        $created = [];
        $clientUserId = $validated['client_mode'] === 'existing' ? (int) $validated['client_user_id'] : null;
        $failure = null;

        for ($i = 1; $i <= $count; $i++) {
            $displayName = $count > 1 ? $baseName.'-'.$i : $baseName;

            $clientData = [
                'display_label' => $displayName,
                'auto_random_remote_identity' => true,
                // KYC is verified once for the batch; it is attached to the first account only.
                'kyc_verification_id' => $i === 1 ? ($validated['kyc_verification_id'] ?? null) : null,
                'kyc_actor' => $request->user(),
            ];

            if ($package->isElastic()) {
                $clientData['data_gb'] = $package->clampDataGb((float) $validated['data_gb']);
            }

            if ($clientUserId !== null && ($sharedClient || $validated['client_mode'] === 'existing')) {
                $clientData['client_mode'] = 'existing';
                $clientData['client_user_id'] = $clientUserId;
            } else {
                // The client itself is created inside createAccount's transaction
                // (EndUserService::resolveForAccount), so a failed purchase does not
                // leave an orphan client behind.
                $password = $endUserService->generatePortalPassword();
                $clientData['client_mode'] = 'auto';
                $clientData['client_full_name'] = $sharedClient ? $baseName : $displayName;
                $clientData['client_password'] = $password;
                $clientData['store_portal_password'] = $password;
            }

            try {
                // Picked per account so a batch spreads over the package's servers
                // the same way single purchases do.
                $server = $this->resolveServerForCreate([], $package, $serverSelection, $packageService);
                $account = $accountService->createAccount($seller, $package, $server, $duration, $clientData);
            } catch (\Illuminate\Validation\ValidationException $exception) {
                if ($created === []) {
                    throw $exception;
                }

                $failure = collect($exception->errors())->flatten()->first() ?: __('accounts.create_failed');
                break;
            } catch (\Throwable $exception) {
                report($exception);
                $failure = $exception->getMessage() ?: __('accounts.create_failed');
                break;
            }

            $created[] = $account;

            // Later accounts of a shared batch go to the client the first one made.
            if ($sharedClient && $clientUserId === null) {
                $clientUserId = $account->client_user_id ? (int) $account->client_user_id : null;
            }
        }

        if ($created === []) {
            return back()
                ->withInput()
                ->with('open_create_modal', true)
                ->with('error', $failure ?: __('accounts.create_failed'));
        }

        $account = $created[0];
        $category = $account->service_type->accountCategory()->value;
        $redirectRoute = $this->accountRoutePrefix().'.accounts.'.$category;
        $target = \Illuminate\Support\Facades\Route::has($redirectRoute)
            ? redirect()->route($redirectRoute)
            : redirect()->route($this->accountRoutePrefix().'.accounts.index');

        if (! $bulk) {
            return $target->with('success', __('app.saved'));
        }

        if ($failure !== null) {
            return $target->with('warning', __('accounts.bulk_partial', [
                'created' => count($created),
                'total' => $count,
                'error' => $failure,
            ]));
        }

        return $target->with('success', __('accounts.bulk_created', ['count' => count($created)]));
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
            'portalRegenerateUrl' => \Route::has($prefix.'.accounts.portal-link.regenerate')
                ? route($prefix.'.accounts.portal-link.regenerate', $account)
                : null,
            // فقط پنل‌های V2Ray کش اشتراک دارند؛ برای وایرگارد و PPP این دکمه
            // بی‌معنا است و نباید ساخته شود.
            'configRefreshUrl' => $account->service_type->isPanelV2ray() && \Route::has($prefix.'.accounts.config-cache.refresh')
                ? route($prefix.'.accounts.config-cache.refresh', $account)
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
