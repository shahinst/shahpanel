<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\PackageDuration;
use App\Services\ClientAccountDetailService;
use App\Services\ClientRenewalService;
use App\Services\PortalLinkService;
use App\Services\ServerOvpnProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AccountController extends Controller
{
    public function index(Request $request): View
    {
        $accounts = Account::query()
            ->forClient($request->user())
            ->with(['package', 'packageDuration', 'server'])
            ->latest('created_at')
            ->paginate(15);

        return view('client.accounts.index', compact('accounts'));
    }

    public function show(Request $request, Account $account, ClientAccountDetailService $detailService): View
    {
        $this->assertClientOwnsAccount($request, $account);

        $detail = $detailService->build($account, $request->user());
        $portalLinks = app(PortalLinkService::class);
        $portalActiveUrl = filled($account->portal_token)
            ? $portalLinks->issue($account)
            : null;

        if ($detail['isPpp'] ?? false) {
            $detail['ppp']['ovpn_download_route'] = ($account->server?->hasOvpnProfile() && \Route::has('client.accounts.ovpn.download'))
                ? route('client.accounts.ovpn.download', $account)
                : null;
        }

        return view('shared.clients.account-show', array_merge($detail, [
            'client' => $request->user(),
            'panel' => 'client',
            'backUrl' => route('client.accounts.index'),
            'renewFormRoute' => null,
            'portalIssueUrl' => null,
            'portalActiveUrl' => $portalActiveUrl,
            'portalLinkTtlMinutes' => $portalLinks->ttlMinutes(),
            'viewerIsClient' => true,
        ]));
    }

    public function stats(Request $request, Account $account, \App\Services\SyncService $syncService): JsonResponse
    {
        $this->assertClientOwnsAccount($request, $account);

        return app(\App\Http\Controllers\Portal\ClientPortalController::class)->stats($account->portal_token, $syncService);
    }

    public function renew(Request $request, Account $account, ClientRenewalService $renewalService): RedirectResponse
    {
        $this->assertClientOwnsAccount($request, $account);

        $durationId = $request->input('package_duration_id');
        $duration = null;

        if ($durationId !== null) {
            $duration = PackageDuration::query()
                ->where('id', $durationId)
                ->where('package_id', $account->package_id)
                ->where('is_enabled', true)
                ->first();
        }

        try {
            $renewalService->renew($account, $request->user(), $duration);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('clients.renewed'));
    }

    public function downloadOvpnProfile(
        Request $request,
        Account $account,
        ServerOvpnProfileService $ovpnProfiles,
    ): BinaryFileResponse {
        $this->assertClientOwnsAccount($request, $account);

        return $ovpnProfiles->downloadResponse($account);
    }

    protected function assertClientOwnsAccount(Request $request, Account $account): void
    {
        abort_unless((int) $account->client_user_id === (int) $request->user()->id, 404);
    }
}
