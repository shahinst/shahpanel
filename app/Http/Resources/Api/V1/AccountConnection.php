<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\AccountCategory;
use App\Models\Account;
use App\Services\ServerOvpnProfileService;
use Illuminate\Support\Facades\Storage;

/**
 * What a client app or a sales bot needs to connect an account: the address,
 * the username and the password, and for PPP servers that offer OpenVPN the
 * server's .ovpn profile. Built from the panel's own records only, so it is
 * cheap enough to return with every created or renewed account.
 *
 * The .ovpn file is one per server (the account's own login goes in the
 * username and password fields), so it only changes when the admin uploads
 * a new profile for that server; a bot can cache it per server.
 */
class AccountConnection
{
    /** @return array<string, mixed>|null */
    public static function make(Account $account): ?array
    {
        $category = $account->service_type->accountCategory();

        if (! in_array($category, [AccountCategory::Ppp, AccountCategory::Anyconnect], true)) {
            return null;
        }

        $account->loadMissing('server');
        $server = $account->server;
        $host = '';

        if ($server !== null) {
            $host = trim((string) ($server->ocserv_vpn_address ?? ''));
            $host = $host !== '' ? $host : $server->vpnClientEndpointHost();
            $host = $host !== '' ? $host : (string) $server->host;
        }

        $connection = [
            'type' => $account->service_type->value,
            'server_address' => $host !== '' ? $host : null,
            'username' => $account->remote_username,
            'password' => $account->remote_password_enc,
            'ovpn' => null,
        ];

        if ($category === AccountCategory::Ppp && $server !== null && $server->isMikrotik()
            && app(ServerOvpnProfileService::class)->hasProfile($server)) {
            $connection['ovpn'] = [
                'filename' => app(ServerOvpnProfileService::class)->downloadFilename($server),
                'content' => Storage::disk('local')->get($server->ovpn_profile_path),
                'updated_at' => optional($server->updated_at)->toIso8601String(),
            ];
        }

        return $connection;
    }
}
