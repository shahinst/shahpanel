<?php

namespace Modules\NowPayments\Http;

use App\Enums\PaymentGatewayDriver;
use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\NowPayments\Client\NowPaymentsSignatureVerifier;
use Modules\NowPayments\NowPaymentsService;

class NowPaymentsWebhookController extends Controller
{
    public function __construct(
        protected NowPaymentsService $nowPaymentsService,
        protected NowPaymentsSignatureVerifier $signatureVerifier,
    ) {}

    public function handle(Request $request): Response
    {
        $rawBody = $request->getContent();
        $payload = $request->json()->all();

        if (! is_array($payload) || $payload === []) {
            return response('Invalid payload', 400);
        }

        $gateway = PaymentGateway::findByDriver(PaymentGatewayDriver::NowPayments);

        if ($gateway === null) {
            return response('Gateway not configured', 404);
        }

        $ipnSecret = $gateway->encryptedConfigValue('ipn_secret_enc');

        // SECURITY: signature verification is mandatory. Without a configured IPN
        // secret + valid signature we refuse to process, otherwise a forged IPN
        // could credit a wallet. The IPN secret must be set in the NowPayments
        // dashboard and in the gateway settings.
        if ($ipnSecret === null || $ipnSecret === '') {
            Log::warning('NOWPayments IPN rejected: no IPN secret configured', [
                'order_id' => $payload['order_id'] ?? null,
            ]);

            return response('IPN secret not configured', 403);
        }

        $signature = $request->header('x-nowpayments-sig');

        if (! $this->signatureVerifier->verify($rawBody, is_string($signature) ? $signature : null, $ipnSecret)) {
            Log::warning('NOWPayments IPN signature verification failed', [
                'order_id' => $payload['order_id'] ?? null,
            ]);

            return response('Invalid signature', 403);
        }

        try {
            $this->nowPaymentsService->handleIpn($payload);
        } catch (\Throwable $exception) {
            report($exception);

            return response('Processing error', 500);
        }

        return response('OK', 200);
    }
}
