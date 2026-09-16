<?php

namespace Modules\NowPayments;

use App\Enums\GatewayPaymentStatus;
use App\Enums\PaymentGatewayDriver;
use App\Models\GatewayPayment;
use App\Models\User;
use App\Services\PaymentGateways\GatewayPaymentService;
use App\Services\PaymentGateways\PaymentGatewayCommissionCalculator;
use App\Services\PaymentGateways\PaymentGatewayException;
use App\Support\PaymentSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NowPayments-specific orchestration extracted from the core GatewayPaymentService.
 * Lives in the module so that deactivating the module removes all NowPayments logic.
 * Reuses the shared, driver-agnostic pieces of the core service (initiation,
 * completion, commission) through its public API.
 */
class NowPaymentsService
{
    public function __construct(
        protected GatewayPaymentService $core,
        protected PaymentGatewayCommissionCalculator $commissionCalculator,
    ) {}

    public function createTopUp(User $user, string $amountUsdt, string $payCurrency): GatewayPayment
    {
        $gateway = $this->core->requireGateway(PaymentGatewayDriver::NowPayments);

        if (! PaymentSettings::hasUsdtTomanRate()) {
            throw new PaymentGatewayException(__('payment_gateways.usdt_rate_missing'));
        }

        if (! $gateway->hasEncryptedConfigValue('api_key_enc')) {
            throw new PaymentGatewayException(__('payment_gateways.nowpayments_api_key_missing'));
        }

        $payCurrency = strtolower(trim($payCurrency));

        if ($payCurrency === '' || ! $gateway->allowsNowPaymentsPayCurrency($payCurrency)) {
            throw new PaymentGatewayException(__('payment_gateways.pay_currency_not_allowed'));
        }

        if ($gateway->enabledNowPaymentsPayCurrencies() === []) {
            throw new PaymentGatewayException(__('payment_gateways.pay_currencies_not_configured'));
        }

        $amountUsdt = number_format((float) $amountUsdt, 8, '.', '');
        $minUsdt = (string) config('payment_gateways.nowpayments.min_usdt', '1');

        if (bccomp($amountUsdt, $minUsdt, 8) < 0) {
            throw new PaymentGatewayException(__('payment_gateways.min_usdt', ['min' => $minUsdt]));
        }

        $rate = PaymentSettings::usdtTomanRate();
        $amounts = $this->commissionCalculator->calculate($amountUsdt, (string) $rate, $gateway);
        $this->core->assertPositiveNetAmount($amounts['net_toman']);

        return $this->core->initiateExternalPayment($user, $gateway, PaymentGatewayDriver::NowPayments, [
            'amount_usdt' => $amountUsdt,
            'amount_toman' => null,
            'usdt_toman_rate' => $rate,
            'gross_toman' => $amounts['gross_toman'],
            'commission_toman' => $amounts['commission_toman'],
            'net_toman' => $amounts['net_toman'],
            'commission_payer' => $amounts['commission_payer'],
            'pay_currency' => $payCurrency,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handleIpn(array $payload): void
    {
        $orderId = (string) ($payload['order_id'] ?? '');

        if ($orderId === '') {
            return;
        }

        $payment = GatewayPayment::query()
            ->where('uuid', $orderId)
            ->where('driver', PaymentGatewayDriver::NowPayments)
            ->first();

        if ($payment === null) {
            return;
        }

        $this->syncStatus($payment, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function syncStatus(GatewayPayment $payment, array $payload): GatewayPayment
    {
        $externalStatus = strtolower((string) ($payload['payment_status'] ?? ''));

        return DB::transaction(function () use ($payment, $payload, $externalStatus): GatewayPayment {
            $payment = GatewayPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->status->isTerminal()) {
                return $payment;
            }

            $history = $payment->callback_payload ?? [];
            $history['ipn'][] = $payload;
            $payment->callback_payload = $history;

            $mappedStatus = $this->mapStatus($externalStatus);

            if ($mappedStatus === GatewayPaymentStatus::Completed) {
                // NowPayments also reports under-paid deposits and repeated
                // (top-up) deposits as `finished`, and their docs warn twice not
                // to release goods on those without checking. Crediting the
                // invoiced amount for a deposit that arrived short hands out
                // wallet balance that was never received, so such payments are
                // parked for an admin instead. When the amounts are absent we
                // cannot judge, and the previous behaviour stands.
                $paidAmount = (float) ($payload['actually_paid'] ?? 0);
                $dueAmount = (float) ($payload['pay_amount'] ?? 0);
                $isRepeatDeposit = ($payload['parent_payment_id'] ?? null) !== null;
                $isUnderpaid = $paidAmount > 0 && $dueAmount > 0 && $paidAmount < ($dueAmount * 0.99);

                if ($isRepeatDeposit || $isUnderpaid) {
                    $payment->status = GatewayPaymentStatus::Processing;
                    $payment->save();

                    Log::warning('NowPayments deposit held for review', [
                        'payment_id' => $payment->id,
                        'uuid' => $payment->uuid,
                        'actually_paid' => $paidAmount,
                        'pay_amount' => $dueAmount,
                        'repeat_deposit' => $isRepeatDeposit,
                    ]);

                    return $payment->fresh();
                }

                $payment->save();
                $this->core->completePayment($payment);

                return $payment->fresh();
            }

            if ($mappedStatus !== null) {
                $payment->status = $mappedStatus;
            }

            $payment->save();

            return $payment->fresh();
        });
    }

    protected function mapStatus(string $status): ?GatewayPaymentStatus
    {
        return match ($status) {
            'waiting' => GatewayPaymentStatus::AwaitingPayment,
            'confirming', 'confirmed', 'sending', 'partially_paid' => GatewayPaymentStatus::Processing,
            'finished' => GatewayPaymentStatus::Completed,
            'failed' => GatewayPaymentStatus::Failed,
            'expired' => GatewayPaymentStatus::Expired,
            'refunded' => GatewayPaymentStatus::Cancelled,
            default => null,
        };
    }
}
