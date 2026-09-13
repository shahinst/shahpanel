<?php

namespace App\Http\Controllers;

use App\Enums\PaymentGatewayDriver;
use App\Models\GatewayPayment;
use App\Models\PaymentGateway;
use App\Services\PaymentGateways\GatewayPaymentService;
use App\Services\PaymentGateways\PaymentGatewayException;
use App\Support\PaymentSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class GatewayTopUpController extends Controller
{
    public function __construct(
        protected GatewayPaymentService $gatewayPaymentService,
    ) {
        // Entire wallet top-up feature is gated behind the payments module.
        $this->middleware('module:payments');
    }

    public function create(Request $request): View
    {
        $gateways = PaymentGateway::enabledForTopUp();
        $nowGateway = PaymentGateway::findByDriver(PaymentGatewayDriver::NowPayments);
        $nowPaymentsCurrencyOptions = $nowGateway !== null && $nowGateway->isOperational()
            ? app('nowpayments.catalog')->userOptions($nowGateway)
            : [];

        return view('shared.wallet-top-up.create', [
            'gateways' => $gateways,
            'usdtTomanRate' => PaymentSettings::usdtTomanRate(),
            'routePrefix' => $this->routePrefix($request),
            'selectedDriver' => old('driver', $gateways->first()?->driver?->value),
            'nowPaymentsCurrencyOptions' => $nowPaymentsCurrencyOptions,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $base = $request->validate([
            'driver' => ['required', Rule::enum(PaymentGatewayDriver::class)],
        ]);

        $driver = PaymentGatewayDriver::from($base['driver']);

        try {
            $payment = match ($driver) {
                PaymentGatewayDriver::NowPayments => (function () use ($request) {
                    $validated = $request->validate([
                        'amount_usdt' => ['required', 'numeric', 'min:0.01'],
                        'pay_currency' => ['required', 'string', 'max:64'],
                    ]);

                    return app('nowpayments.service')->createTopUp(
                        $request->user(),
                        (string) $validated['amount_usdt'],
                        (string) $validated['pay_currency'],
                    );
                })(),
                PaymentGatewayDriver::Zarinpal => $this->gatewayPaymentService->createZarinpalTopUp(
                    $request->user(),
                    (string) $request->validate(['amount_toman' => ['required', 'numeric', 'min:1000']])['amount_toman'],
                ),
                PaymentGatewayDriver::CardToCard => (function () use ($request) {
                    $validated = $request->validate([
                        'amount_toman' => ['required', 'numeric', 'min:1000'],
                        'tracking_number' => ['required', 'string', 'max:100'],
                        'card_last4' => ['required', 'digits:4'],
                        'requester_note' => ['nullable', 'string', 'max:1000'],
                    ]);

                    return $this->gatewayPaymentService->createCardToCardTopUp(
                        $request->user(),
                        (string) $validated['amount_toman'],
                        [
                            'tracking_number' => $validated['tracking_number'],
                            'card_last4' => $validated['card_last4'],
                            'requester_note' => $validated['requester_note'] ?? null,
                        ],
                    );
                })(),
            };
        } catch (PaymentGatewayException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with('error', __('payment_gateways.initiation_failed'));
        }

        if ($payment->invoice_url) {
            return redirect()->away($payment->invoice_url);
        }

        return redirect()
            ->route($this->routePrefix($request).'.show', $payment)
            ->with('success', $driver === PaymentGatewayDriver::CardToCard
                ? __('payment_gateways.card_to_card_submitted')
                : null);
    }

    public function show(Request $request, GatewayPayment $gatewayPayment): View
    {
        abort_unless($gatewayPayment->isOwnedBy($request->user()), 404);

        $destinationCard = null;

        if ($gatewayPayment->driver === PaymentGatewayDriver::CardToCard) {
            $destinationCard = $gatewayPayment->paymentGateway;
        }

        return view('shared.wallet-top-up.show', [
            'payment' => $gatewayPayment->load(['paymentGateway', 'user']),
            'routePrefix' => $this->routePrefix($request),
            'destinationCard' => $destinationCard,
        ]);
    }

    public function return(Request $request, GatewayPayment $gatewayPayment): View
    {
        abort_unless($gatewayPayment->isOwnedBy($request->user()), 404);

        return view('shared.wallet-top-up.return', [
            'payment' => $gatewayPayment->fresh(['paymentGateway']),
            'returnStatus' => $request->string('status')->toString(),
            'routePrefix' => $this->routePrefix($request),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'driver' => ['required', Rule::enum(PaymentGatewayDriver::class)],
            'amount_usdt' => ['nullable', 'numeric', 'min:0.01'],
            'amount_toman' => ['nullable', 'numeric', 'min:1000'],
            'pay_currency' => ['nullable', 'string', 'max:64'],
        ]);

        $driver = PaymentGatewayDriver::from($validated['driver']);
        $amounts = $this->gatewayPaymentService->previewAmounts(
            $driver,
            isset($validated['amount_usdt']) ? (string) $validated['amount_usdt'] : null,
            isset($validated['amount_toman']) ? (string) $validated['amount_toman'] : null,
        );

        $response = [
            'gross_toman' => $amounts['gross_toman'],
            'commission_toman' => $amounts['commission_toman'],
            'net_toman' => $amounts['net_toman'],
            'commission_payer' => $amounts['commission_payer']->value,
            'gross_toman_formatted' => persian_digits(number_format((float) $amounts['gross_toman'], 0)),
            'commission_toman_formatted' => persian_digits(number_format((float) $amounts['commission_toman'], 0)),
            'net_toman_formatted' => persian_digits(number_format((float) $amounts['net_toman'], 0)),
            'estimated_crypto_amount' => null,
            'estimated_crypto_formatted' => null,
            'estimated_crypto_currency' => null,
        ];

        if (
            $driver === PaymentGatewayDriver::NowPayments
            && ! empty($validated['pay_currency'])
            && ! empty($validated['amount_usdt'])
        ) {
            $nowGateway = PaymentGateway::findByDriver(PaymentGatewayDriver::NowPayments);

            if ($nowGateway !== null && $nowGateway->isOperational()) {
                $estimate = app('nowpayments.catalog')->estimateUsdToCrypto(
                    $nowGateway,
                    (string) $validated['amount_usdt'],
                    (string) $validated['pay_currency'],
                );

                if ($estimate !== null) {
                    $response['estimated_crypto_amount'] = $estimate['estimated_amount'];
                    $response['estimated_crypto_currency'] = strtoupper($estimate['currency']);
                    $response['estimated_crypto_formatted'] = persian_digits(rtrim(rtrim(
                        number_format((float) $estimate['estimated_amount'], 8, '.', ''),
                        '0',
                    ), '.')).' '.strtoupper($estimate['currency']);
                }
            }
        }

        return response()->json($response);
    }

    protected function routePrefix(Request $request): string
    {
        return match ($request->user()?->role) {
            \App\Enums\UserRole::Agent => 'agent.wallet.top-up',
            \App\Enums\UserRole::Seller => 'seller.wallet.top-up',
            \App\Enums\UserRole::Client => 'client.wallet.top-up',
            default => 'agent.wallet.top-up',
        };
    }
}
