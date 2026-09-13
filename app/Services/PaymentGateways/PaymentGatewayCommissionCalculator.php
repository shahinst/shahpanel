<?php

namespace App\Services\PaymentGateways;

use App\Enums\CommissionPayer;
use App\Models\PaymentGateway;

final class PaymentGatewayCommissionCalculator
{
    /**
     * @return array{
     *     gross_toman: string,
     *     commission_toman: string,
     *     net_toman: string,
     *     commission_payer: CommissionPayer
     * }
     */
    public function calculate(string $amountUsdt, string $usdtTomanRate, PaymentGateway $gateway): array
    {
        $grossToman = bcmul($amountUsdt, $usdtTomanRate, 2);

        return $this->calculateFromGrossToman($grossToman, $gateway);
    }

    /**
     * @return array{
     *     gross_toman: string,
     *     commission_toman: string,
     *     net_toman: string,
     *     commission_payer: CommissionPayer
     * }
     */
    public function calculateFromGrossToman(string $grossToman, PaymentGateway $gateway): array
    {
        $grossToman = number_format((float) $grossToman, 2, '.', '');

        $percentPart = '0.00';
        $percent = number_format((float) $gateway->commission_percent, 4, '.', '');

        if (bccomp($percent, '0', 4) > 0) {
            $percentPart = bcdiv(bcmul($grossToman, $percent, 4), '100', 2);
        }

        $fixedPart = number_format((float) $gateway->commission_fixed, 2, '.', '');
        $commissionToman = bcadd($percentPart, $fixedPart, 2);

        if (bccomp($commissionToman, $grossToman, 2) > 0) {
            $commissionToman = $grossToman;
        }

        $payer = $gateway->commission_payer instanceof CommissionPayer
            ? $gateway->commission_payer
            : CommissionPayer::from((string) $gateway->commission_payer);

        $netToman = $payer === CommissionPayer::User
            ? bcsub($grossToman, $commissionToman, 2)
            : $grossToman;

        if (bccomp($netToman, '0', 2) < 0) {
            $netToman = '0.00';
        }

        return [
            'gross_toman' => $grossToman,
            'commission_toman' => $commissionToman,
            'net_toman' => $netToman,
            'commission_payer' => $payer,
        ];
    }
}
