<?php

namespace Modules\ShahBot\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\ShahBot\Models\BotInstance;
use Modules\ShahBot\Services\ResellerCryptoService;

/**
 * The callback a reseller pastes into their NowPayments account. The bot id and
 * the bot's secret are in the URL; the payload is trusted only once its
 * signature checks against that reseller's IPN secret.
 */
class ResellerIpnController extends Controller
{
    public function __invoke(Request $request, int $bot, string $secret, ResellerCryptoService $crypto): Response
    {
        $instance = BotInstance::query()->whereKey($bot)->first();

        abort_if($instance === null || ! hash_equals((string) $instance->webhook_secret, $secret), 404);

        $ok = $crypto->handleIpn($instance, $request->getContent(), (string) $request->header('x-nowpayments-sig', ''));

        return response($ok ? 'ok' : 'bad signature', $ok ? 200 : 403);
    }
}
