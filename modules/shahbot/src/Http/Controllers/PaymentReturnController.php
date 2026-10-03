<?php

namespace Modules\ShahBot\Http\Controllers;

use App\Enums\GatewayPaymentStatus;
use App\Models\GatewayPayment;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Modules\ShahBot\Models\BotGatewayPayment;
use Modules\ShahBot\Services\OnlinePaymentService;
use Modules\ShahBot\Support\BotSettings;

/**
 * Where a bot user lands after an online gateway. They have no panel login, so
 * the page only shows the outcome and links back to the bot; the payment's
 * uuid in the URL is the only key, and nothing beyond the status is shown.
 */
class PaymentReturnController extends Controller
{
    public function __invoke(string $uuid, BotSettings $settings, OnlinePaymentService $online): View
    {
        $payment = GatewayPayment::query()->where('uuid', $uuid)->firstOrFail();
        abort_unless(BotGatewayPayment::query()->where('gateway_payment_id', $payment->id)->exists(), 404);

        $online->notifyFinished($payment->id);

        $username = $settings->get('bot_username');

        return view('shahbot::pay-return', [
            'payment' => $payment,
            'success' => $payment->status === GatewayPaymentStatus::Completed,
            'pending' => ! $payment->status->isTerminal(),
            'botUrl' => $username !== '' ? 'https://t.me/'.ltrim($username, '@') : null,
        ]);
    }
}
