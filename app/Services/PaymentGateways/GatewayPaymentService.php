<?php

namespace App\Services\PaymentGateways;

use App\Enums\GatewayPaymentStatus;
use App\Enums\PaymentGatewayDriver;
use App\Enums\TransactionType;
use App\Enums\UserRole;
use App\Models\GatewayPayment;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Services\PaymentGateways\Zarinpal\ZarinpalApiException;
use App\Services\PaymentGateways\Zarinpal\ZarinpalClient;
use App\Support\PaymentSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GatewayPaymentService
{
    public function __construct(
        protected PaymentGatewayManager $gatewayManager,
        protected PaymentGatewayCommissionCalculator $commissionCalculator,
        protected \App\Services\WalletService $walletService,
    ) {}

    public function createZarinpalTopUp(User $user, string $amountToman): GatewayPayment
    {
        $gateway = $this->requireGateway(PaymentGatewayDriver::Zarinpal);

        if (! $gateway->hasEncryptedConfigValue('merchant_id_enc')) {
            throw new PaymentGatewayException(__('payment_gateways.zarinpal_merchant_missing'));
        }

        $amountToman = number_format((float) $amountToman, 2, '.', '');
        $minToman = (string) config('payment_gateways.zarinpal.min_toman', '1000');

        if (bccomp($amountToman, $minToman, 2) < 0) {
            throw new PaymentGatewayException(__('payment_gateways.min_toman', ['min' => $minToman]));
        }

        $amounts = $this->commissionCalculator->calculateFromGrossToman($amountToman, $gateway);
        $this->assertPositiveNetAmount($amounts['net_toman']);

        return $this->initiateExternalPayment($user, $gateway, PaymentGatewayDriver::Zarinpal, [
            'amount_usdt' => null,
            'amount_toman' => $amountToman,
            'usdt_toman_rate' => null,
            'gross_toman' => $amounts['gross_toman'],
            'commission_toman' => $amounts['commission_toman'],
            'net_toman' => $amounts['net_toman'],
            'commission_payer' => $amounts['commission_payer'],
        ]);
    }

    /**
     * @param  array{
     *     tracking_number: string,
     *     card_last4: string,
     *     requester_note?: ?string
     * }  $proof
     */
    public function createCardToCardTopUp(User $user, string $amountToman, array $proof): GatewayPayment
    {
        $gateway = $this->requireGateway(PaymentGatewayDriver::CardToCard);

        if (trim((string) $gateway->configValue('card_number', '')) === '') {
            throw new PaymentGatewayException(__('payment_gateways.card_to_card_destination_missing'));
        }

        $amountToman = number_format((float) $amountToman, 2, '.', '');
        $minToman = (string) config('payment_gateways.card_to_card.min_toman', '1000');

        if (bccomp($amountToman, $minToman, 2) < 0) {
            throw new PaymentGatewayException(__('payment_gateways.min_toman', ['min' => $minToman]));
        }

        $amounts = $this->commissionCalculator->calculateFromGrossToman($amountToman, $gateway);
        $this->assertPositiveNetAmount($amounts['net_toman']);

        return DB::transaction(function () use ($user, $gateway, $amountToman, $amounts, $proof): GatewayPayment {
            $payment = GatewayPayment::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'payment_gateway_id' => $gateway->id,
                'driver' => PaymentGatewayDriver::CardToCard,
                'status' => GatewayPaymentStatus::Processing,
                'amount_usdt' => null,
                'amount_toman' => $amountToman,
                'usdt_toman_rate' => null,
                'gross_toman' => $amounts['gross_toman'],
                'commission_toman' => $amounts['commission_toman'],
                'net_toman' => $amounts['net_toman'],
                'commission_payer' => $amounts['commission_payer'],
                'tracking_number' => $proof['tracking_number'],
                'card_last4' => $proof['card_last4'],
                'requester_note' => $proof['requester_note'] ?? null,
                'pay_currency' => 'IRT',
                'pay_amount' => $amountToman,
                'callback_payload' => [
                    'destination' => [
                        'card_number' => $gateway->configValue('card_number'),
                        'card_holder' => $gateway->configValue('card_holder'),
                        'bank_name' => $gateway->configValue('bank_name'),
                    ],
                ],
            ]);

            return $payment->fresh(['paymentGateway', 'user']);
        });
    }

    public function approveCardToCard(GatewayPayment $payment, User $reviewer, ?string $adminNote = null): GatewayPayment
    {
        if ($payment->driver !== PaymentGatewayDriver::CardToCard) {
            throw new PaymentGatewayException(__('payment_gateways.invalid_approval_driver'));
        }

        return DB::transaction(function () use ($payment, $reviewer, $adminNote): GatewayPayment {
            $payment = GatewayPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== GatewayPaymentStatus::Processing) {
                throw new PaymentGatewayException(__('payment_gateways.cannot_approve_status'));
            }

            $payment->reviewer_user_id = $reviewer->id;
            $payment->reviewed_at = now();
            $payment->admin_note = $adminNote;
            $payment->save();

            $this->completePayment($payment);

            return $payment->fresh(['paymentGateway', 'user', 'reviewer']);
        });
    }

    public function rejectCardToCard(GatewayPayment $payment, User $reviewer, ?string $adminNote = null): GatewayPayment
    {
        if ($payment->driver !== PaymentGatewayDriver::CardToCard) {
            throw new PaymentGatewayException(__('payment_gateways.invalid_approval_driver'));
        }

        return DB::transaction(function () use ($payment, $reviewer, $adminNote): GatewayPayment {
            $payment = GatewayPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status !== GatewayPaymentStatus::Processing) {
                throw new PaymentGatewayException(__('payment_gateways.cannot_reject_status'));
            }

            $payment->fill([
                'status' => GatewayPaymentStatus::Failed,
                'reviewer_user_id' => $reviewer->id,
                'reviewed_at' => now(),
                'admin_note' => $adminNote,
            ])->save();

            return $payment->fresh(['paymentGateway', 'user', 'reviewer']);
        });
    }

    public function handleZarinpalCallback(string $authority, string $status): GatewayPayment
    {
        $payment = GatewayPayment::query()
            ->where('driver', PaymentGatewayDriver::Zarinpal)
            ->where('external_payment_id', $authority)
            ->first();

        if ($payment === null) {
            throw new PaymentGatewayException(__('payment_gateways.payment_not_found'));
        }

        if ($payment->status->isTerminal()) {
            return $payment;
        }

        if (strtoupper($status) !== 'OK') {
            $payment->status = GatewayPaymentStatus::Failed;
            $payment->save();

            return $payment->fresh();
        }

        $gateway = $payment->paymentGateway ?? PaymentGateway::findByDriver(PaymentGatewayDriver::Zarinpal);

        if ($gateway === null) {
            throw new PaymentGatewayException(__('payment_gateways.gateway_not_available'));
        }

        $client = ZarinpalClient::forGateway($gateway);
        $amountRial = (int) bcmul((string) $payment->gross_toman, '10', 0);

        try {
            $response = $client->verifyPayment([
                'amount' => $amountRial,
                'authority' => $authority,
            ]);
        } catch (ZarinpalApiException $exception) {
            $payment->status = GatewayPaymentStatus::Failed;
            $history = $payment->callback_payload ?? [];
            $history['verify_error'] = $exception->getMessage();
            $payment->callback_payload = $history;
            $payment->save();

            throw new PaymentGatewayException($exception->getMessage(), previous: $exception);
        }

        return DB::transaction(function () use ($payment, $response): GatewayPayment {
            $payment = GatewayPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status->isTerminal()) {
                return $payment;
            }

            $history = $payment->callback_payload ?? [];
            $history['verify'] = $response;
            $payment->callback_payload = $history;

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $code = (int) ($data['code'] ?? 0);

            if (! in_array($code, [100, 101], true)) {
                $payment->status = GatewayPaymentStatus::Failed;
                $payment->save();

                throw new PaymentGatewayException(__('payment_gateways.zarinpal_verify_failed'));
            }

            $payment->save();
            $this->completePayment($payment);

            return $payment->fresh(['paymentGateway', 'user']);
        });
    }

    /**
     * @return array{
     *     gross_toman: string,
     *     commission_toman: string,
     *     net_toman: string,
     *     commission_payer: \App\Enums\CommissionPayer
     * }
     */
    public function previewAmounts(PaymentGatewayDriver $driver, ?string $amountUsdt = null, ?string $amountToman = null): array
    {
        $gateway = PaymentGateway::findByDriver($driver);

        if ($gateway === null) {
            return $this->emptyPreview();
        }

        if ($driver === PaymentGatewayDriver::NowPayments) {
            if (! PaymentSettings::hasUsdtTomanRate() || $amountUsdt === null) {
                return $this->emptyPreview();
            }

            return $this->commissionCalculator->calculate(
                number_format((float) $amountUsdt, 8, '.', ''),
                (string) PaymentSettings::usdtTomanRate(),
                $gateway,
            );
        }

        if ($amountToman === null) {
            return $this->emptyPreview();
        }

        return $this->commissionCalculator->calculateFromGrossToman(
            number_format((float) $amountToman, 2, '.', ''),
            $gateway,
        );
    }

    /**
     * @param  array{
     *     amount_usdt: ?string,
     *     amount_toman: ?string,
     *     usdt_toman_rate: ?string,
     *     gross_toman: string,
     *     commission_toman: string,
     *     net_toman: string,
     *     commission_payer: \App\Enums\CommissionPayer,
     *     pay_currency?: ?string
     * }  $amounts
     */
    public function initiateExternalPayment(
        User $user,
        PaymentGateway $gateway,
        PaymentGatewayDriver $driver,
        array $amounts,
    ): GatewayPayment {
        return DB::transaction(function () use ($user, $gateway, $driver, $amounts): GatewayPayment {
            $payment = GatewayPayment::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'payment_gateway_id' => $gateway->id,
                'driver' => $driver,
                'status' => GatewayPaymentStatus::Pending,
                'amount_usdt' => $amounts['amount_usdt'],
                'amount_toman' => $amounts['amount_toman'],
                'usdt_toman_rate' => $amounts['usdt_toman_rate'],
                'gross_toman' => $amounts['gross_toman'],
                'commission_toman' => $amounts['commission_toman'],
                'net_toman' => $amounts['net_toman'],
                'commission_payer' => $amounts['commission_payer'],
                'pay_currency' => $amounts['pay_currency'] ?? null,
            ]);

            $result = $this->gatewayManager->driver($driver)->initiate($payment, $gateway);

            $payment->fill([
                'status' => GatewayPaymentStatus::AwaitingPayment,
                'external_payment_id' => $result->externalPaymentId,
                'external_invoice_id' => $result->externalInvoiceId,
                'pay_address' => $result->payAddress,
                'pay_currency' => $result->payCurrency,
                'pay_amount' => $result->payAmount,
                'invoice_url' => $result->invoiceUrl,
                'callback_payload' => ['initiation' => $result->rawResponse],
            ])->save();

            return $payment->fresh(['paymentGateway', 'user']);
        });
    }

    public function completePayment(GatewayPayment $payment): void
    {
        if ($payment->status === GatewayPaymentStatus::Completed) {
            return;
        }

        if ($payment->transactions()->where('type', TransactionType::Charge)->exists()) {
            $payment->status = GatewayPaymentStatus::Completed;
            $payment->paid_at ??= now();
            $payment->save();

            return;
        }

        $payment->status = GatewayPaymentStatus::Completed;
        $payment->paid_at = now();
        $payment->save();

        $this->walletService->credit(
            $payment->user,
            (string) $payment->net_toman,
            TransactionType::Charge,
            [
                'related_gateway_payment_id' => $payment->id,
                'description' => $this->walletCreditDescription($payment),
            ],
        );

        if (
            bccomp((string) $payment->commission_toman, '0', 2) > 0
            && $payment->commission_payer === \App\Enums\CommissionPayer::Admin
        ) {
            $admin = User::query()->where('role', UserRole::Admin)->orderBy('id')->first();

            if ($admin !== null) {
                $this->walletService->debit(
                    $admin,
                    (string) $payment->commission_toman,
                    TransactionType::Commission,
                    [
                        'related_gateway_payment_id' => $payment->id,
                        'source_user_id' => $payment->user_id,
                        'description' => __('payment_gateways.admin_commission_description', [
                            'user' => $payment->user->full_name ?? $payment->user->username,
                            'gateway' => $payment->paymentGateway?->display_name ?? $payment->driver->label(),
                        ]),
                    ],
                );
            }
        }
    }

    protected function walletCreditDescription(GatewayPayment $payment): string
    {
        if ($payment->driver === PaymentGatewayDriver::NowPayments && $payment->amount_usdt !== null) {
            return __('payment_gateways.wallet_credit_description', [
                'usdt' => persian_digits(number_format((float) $payment->amount_usdt, 2)),
                'gateway' => $payment->paymentGateway?->display_name ?? $payment->driver->label(),
            ]);
        }

        return __('payment_gateways.wallet_credit_toman_description', [
            'amount' => persian_digits(number_format((float) $payment->gross_toman, 0)),
            'gateway' => $payment->paymentGateway?->display_name ?? $payment->driver->label(),
        ]);
    }

    public function requireGateway(PaymentGatewayDriver $driver): PaymentGateway
    {
        $gateway = PaymentGateway::findByDriver($driver);

        if ($gateway === null || ! $gateway->isOperational()) {
            throw new PaymentGatewayException(__('payment_gateways.gateway_not_available'));
        }

        return $gateway;
    }

    public function assertPositiveNetAmount(string $netToman): void
    {
        if (bccomp($netToman, '0', 2) <= 0) {
            throw new PaymentGatewayException(__('payment_gateways.net_amount_zero'));
        }
    }

    /**
     * @return array{
     *     gross_toman: string,
     *     commission_toman: string,
     *     net_toman: string,
     *     commission_payer: \App\Enums\CommissionPayer
     * }
     */
    protected function emptyPreview(): array
    {
        return [
            'gross_toman' => '0.00',
            'commission_toman' => '0.00',
            'net_toman' => '0.00',
            'commission_payer' => \App\Enums\CommissionPayer::User,
        ];
    }
}
