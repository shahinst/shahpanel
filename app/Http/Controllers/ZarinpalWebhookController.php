<?php

namespace App\Http\Controllers;

use App\Enums\GatewayPaymentStatus;
use App\Enums\UserRole;
use App\Models\GatewayPayment;
use App\Services\PaymentGateways\GatewayPaymentService;
use App\Services\PaymentGateways\PaymentGatewayException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ZarinpalWebhookController extends Controller
{
    public function __construct(
        protected GatewayPaymentService $gatewayPaymentService,
    ) {}

    public function handle(Request $request): RedirectResponse
    {
        $authority = trim($request->string('Authority')->toString());
        $status = trim($request->string('Status')->toString());

        if ($authority === '') {
            abort(404);
        }

        $payment = GatewayPayment::query()
            ->where('external_payment_id', $authority)
            ->first();

        if ($payment === null) {
            abort(404);
        }

        $routePrefix = $this->routePrefixFor($payment->user);

        try {
            $payment = $this->gatewayPaymentService->handleZarinpalCallback($authority, $status);
        } catch (PaymentGatewayException) {
            return redirect()->route($routePrefix.'.return', [
                'gatewayPayment' => $payment->uuid,
                'status' => 'cancel',
            ])->with('error', __('payment_gateways.zarinpal_verify_failed'));
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route($routePrefix.'.return', [
                'gatewayPayment' => $payment->uuid,
                'status' => 'cancel',
            ])->with('error', __('payment_gateways.initiation_failed'));
        }

        $returnStatus = $payment->status === GatewayPaymentStatus::Completed ? 'success' : 'cancel';

        return redirect()->route($routePrefix.'.return', [
            'gatewayPayment' => $payment->uuid,
            'status' => $returnStatus,
        ]);
    }

    protected function routePrefixFor(\App\Models\User $user): string
    {
        return match ($user->role) {
            UserRole::Agent => 'agent.wallet.top-up',
            UserRole::Seller => 'seller.wallet.top-up',
            UserRole::Client => 'client.wallet.top-up',
            default => 'agent.wallet.top-up',
        };
    }
}
