<?php

namespace App\Services;

use App\Models\PackageDuration;
use App\Models\Setting;
use App\Models\User;

/**
 * Admin-configured cap on how much above agent wholesale a seller price may be set.
 * When enabled, max seller unit = agent wholesale × (1 + percent/100).
 */
class AgentSellerMarkupService
{
    public const SETTING_PERCENT = 'agent_seller_markup_percent';

    public function percent(): float
    {
        return max(0.0, min(100.0, (float) Setting::getValue(self::SETTING_PERCENT, 0)));
    }

    public function isEnabled(): bool
    {
        return $this->percent() > 0;
    }

    public function maxSellerUnitPrice(string $agentWholesale): string
    {
        $agentWholesale = number_format((float) $agentWholesale, 2, '.', '');

        if (! $this->isEnabled() || bccomp($agentWholesale, '0', 2) <= 0) {
            return $agentWholesale;
        }

        $rate = bcdiv(number_format($this->percent(), 4, '.', ''), '100', 6);
        $multiplier = bcadd('1', $rate, 6);

        return number_format((float) bcmul($agentWholesale, $multiplier, 4), 2, '.', '');
    }

    public function maxMarginPerUnit(string $agentWholesale): string
    {
        $agentWholesale = number_format((float) $agentWholesale, 2, '.', '');

        if (! $this->isEnabled() || bccomp($agentWholesale, '0', 2) <= 0) {
            return '0.00';
        }

        $rate = bcdiv(number_format($this->percent(), 4, '.', ''), '100', 6);

        return number_format((float) bcmul($agentWholesale, $rate, 4), 2, '.', '');
    }

    public function maxSellerUnitPriceFor(User $agent, PackageDuration $duration): ?string
    {
        try {
            $agentWholesale = app(UserPackagePricingService::class)->requireWholesalePrice($agent, $duration);
        } catch (\Throwable) {
            return null;
        }

        return $this->maxSellerUnitPrice($agentWholesale);
    }

    /**
     * @return array{percent: float, max_unit: string, max_margin_unit: string, agent_unit: string}|null
     */
    public function pricingGuideForAgentUnit(string $agentWholesale): ?array
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $agentWholesale = number_format((float) $agentWholesale, 2, '.', '');

        return [
            'percent' => $this->percent(),
            'agent_unit' => $agentWholesale,
            'max_unit' => $this->maxSellerUnitPrice($agentWholesale),
            'max_margin_unit' => $this->maxMarginPerUnit($agentWholesale),
        ];
    }

    /**
     * Effective markup % of agent wholesale for a realized margin (accounting display).
     */
    public function effectiveMarginPercent(string $agentWholesale, string $marginTotal): ?string
    {
        if (bccomp($agentWholesale, '0', 2) <= 0 || bccomp($marginTotal, '0', 2) <= 0) {
            return null;
        }

        return number_format((float) bcmul(bcdiv($marginTotal, $agentWholesale, 6), '100', 4), 2, '.', '');
    }
}
