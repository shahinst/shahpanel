<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\ServiceType;
use App\Enums\UserRole;
use App\Models\Account;
use App\Models\User;
use App\Services\ClientAccountDetailService;
use App\Services\PortalLinkService;
use Illuminate\Http\Request;
use Illuminate\View\View;

trait ShowsClientAccountDetails
{
    public function showAccount(
        Request $request,
        User $client,
        Account $account,
        ClientAccountDetailService $detailService,
    ): View {
        abort_unless($client->role === UserRole::Client, 404);
        $this->authorize('view', $client);
        $this->authorize('view', $account);
        abort_unless((int) $account->client_user_id === (int) $client->id, 404);

        $detail = $detailService->build($account);
        $panel = $this->clientsPanel();
        $portalLinks = app(PortalLinkService::class);

        $detail['wireguard']['config_download_route'] = $account->service_type === ServiceType::Wireguard && \Route::has($panel.'.accounts.config.download')
            ? route($panel.'.accounts.config.download', $account)
            : null;
        $detail['wireguard']['qr_download_route'] = $account->service_type === ServiceType::Wireguard && \Route::has($panel.'.accounts.config.qr')
            ? route($panel.'.accounts.config.qr', $account)
            : null;

        if ($detail['isPpp'] ?? false) {
            $detail['ppp']['ovpn_download_route'] = ($account->server?->hasOvpnProfile() && \Route::has($panel.'.accounts.ovpn.download'))
                ? route($panel.'.accounts.ovpn.download', $account)
                : null;
        }

        return view('shared.clients.account-show', array_merge($detail, [
            'client' => $client,
            'panel' => $panel,
            'backUrl' => route($panel.'.clients.show', $client),
            'renewFormRoute' => \Route::has($panel.'.accounts.renew-form')
                ? route($panel.'.accounts.renew-form', $account)
                : null,
            'portalIssueUrl' => \Route::has($panel.'.accounts.portal-link')
                ? route($panel.'.accounts.portal-link', $account)
                : null,
            'portalActiveUrl' => $portalLinks->publicUrlIfActive($account),
            'portalLinkTtlMinutes' => $portalLinks->ttlMinutes(),
            'viewerIsClient' => false,
        ]));
    }
}
